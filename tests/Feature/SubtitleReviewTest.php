<?php

namespace Tests\Feature;

use App\Jobs\ProcessSubtitleVideo;
use App\Jobs\RenderSubtitleVideo;
use App\Models\SubtitleVideo;
use App\Models\User;
use App\Services\Subtitles\LocalMediaProcess;
use App\Services\Subtitles\SubtitleFailure;
use App\Services\Subtitles\TranscriptReview;
use App\Services\Subtitles\VideoSubtitleProcessor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Mockery;
use Tests\TestCase;

class SubtitleReviewTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('subtitles');
        Queue::fake();
    }

    private function video(): SubtitleVideo
    {
        $user = User::factory()->create();
        $this->actingAs($user);
        $words = (new TranscriptReview)->normalize(json_decode(file_get_contents(base_path('tests/Fixtures/subtitles/words.json')), true), 4);
        $video = SubtitleVideo::create(['user_id' => $user->id, 'upload_token' => (string) Str::uuid(), 'original_name' => 'video.mp4', 'size_bytes' => 20,
            'status' => 'awaiting_review', 'transcript_source' => $words, 'transcript_draft' => $words, 'transcript_revision' => 1, 'review_ready_at' => now(),
            'media' => ['width' => 720, 'height' => 1280, 'duration' => 4],
            'settings' => ['preset' => 'karaoke', 'language' => 'en', 'font_size' => 46, 'highlight' => '#FFFF00', 'line_length' => 26, 'vertical_margin' => 110]]);
        Storage::disk('subtitles')->put($video->directory().'/original.mp4', 'original');

        return $video;
    }

    private function body(SubtitleVideo $video): array
    {
        return ['revision' => $video->transcript_revision, 'words' => array_column($video->transcript_draft, 'word')];
    }

    public function test_review_and_original_are_owner_only_and_private(): void
    {
        $video = $this->video();
        $this->getJson(route('subtitles.transcript', $video))->assertOk()->assertJsonPath('editable', true)->assertJsonPath('words.0.original', 'Every');
        $this->get(route('subtitles.original', $video))->assertOk()->assertHeader('Content-Type', 'video/mp4');
        $this->actingAs(User::factory()->create());
        $this->getJson(route('subtitles.transcript', $video))->assertNotFound();
        $this->get(route('subtitles.original', $video))->assertNotFound();
        $this->putJson(route('subtitles.transcript.save', $video), $this->body($video))->assertNotFound();
        $this->postJson(route('subtitles.approve', $video), $this->body($video) + ['confirmed' => true])->assertNotFound();
    }

    public function test_draft_save_preserves_timestamps_and_never_queues_a_render(): void
    {
        $video = $this->video();
        $body = $this->body($video);
        $body['words'][0] = 'EVERY';
        $this->putJson(route('subtitles.transcript.save', $video), $body)->assertOk()->assertJsonPath('revision', 2);
        $video->refresh();
        $this->assertSame('awaiting_review', $video->status);
        $this->assertSame('Every', $video->transcript_source[0]['word']);
        $this->assertSame('EVERY', $video->transcript_draft[0]['word']);
        $this->assertSame(array_column($video->transcript_source, 'start'), array_column($video->transcript_draft, 'start'));
        $this->assertNull($video->approved_at);
        Queue::assertNothingPushed();
        $this->putJson(route('subtitles.transcript.save', $video), $body)->assertConflict();
    }

    public function test_approval_requires_confirmation_and_an_exact_complete_word_list(): void
    {
        $video = $this->video();
        $body = $this->body($video);
        $this->postJson(route('subtitles.approve', $video), $body)->assertUnprocessable();
        $this->postJson(route('subtitles.approve', $video), $body + ['confirmed' => false])->assertUnprocessable();
        array_pop($body['words']);
        $this->postJson(route('subtitles.approve', $video), $body + ['confirmed' => true])->assertUnprocessable();
        $this->assertNull($video->fresh()->approved_at);
        Queue::assertNothingPushed();
    }

    public function test_explicit_approval_freezes_exact_edits_is_idempotent_and_rejects_later_changes(): void
    {
        $video = $this->video();
        $body = $this->body($video) + ['confirmed' => true];
        $body['words'][0] = 'EVERY';
        $this->postJson(route('subtitles.approve', $video), $body)->assertOk()->assertJsonPath('status', 'approved');
        $video->refresh();
        $this->assertTrue($video->hasApprovedTranscript());
        $this->assertSame('EVERY', $video->transcript_approved[0]['word']);
        $this->assertSame($video->user_id, $video->approved_by);
        $this->assertSame(2, $video->approved_revision);
        $this->postJson(route('subtitles.approve', $video), $body)->assertOk();
        Queue::assertPushed(RenderSubtitleVideo::class, 1);
        $this->putJson(route('subtitles.transcript.save', $video), $this->body($video))->assertConflict();
        $body['words'][0] = 'Different';
        $this->postJson(route('subtitles.approve', $video), $body)->assertConflict();
        $this->assertSame('EVERY', $video->fresh()->transcript_approved[0]['word']);
    }

    public function test_long_unpunctuated_transcript_can_be_approved_without_changing_words_or_timings(): void
    {
        $video = $this->video();
        $words = [];
        foreach (range(0, 199) as $i) {
            $words[] = ['start' => $i * 0.3, 'end' => $i * 0.3 + 0.25, 'word' => 'token'.$i];
        }
        $video->update(['transcript_source' => $words, 'transcript_draft' => $words,
            'media' => ['width' => 720, 'height' => 1280, 'duration' => 60]]);
        $this->postJson(route('subtitles.approve', $video), ['revision' => 1, 'words' => array_column($words, 'word'), 'confirmed' => true])
            ->assertOk()->assertJsonPath('status', 'approved');
        $this->assertSame(TranscriptReview::hash($words), $video->fresh()->approved_hash);
        $this->assertTrue($video->fresh()->hasApprovedTranscript());
        Queue::assertPushed(RenderSubtitleVideo::class, 1);
    }

    public function test_unrenderable_individual_token_returns_a_specific_error_without_freezing_approval(): void
    {
        $video = $this->video();
        $body = $this->body($video) + ['confirmed' => true];
        $body['words'][0] = str_repeat('W', 120);
        $this->postJson(route('subtitles.approve', $video), $body)->assertUnprocessable()
            ->assertJsonPath('code', 'subtitle_layout_unrenderable')->assertJsonValidationErrors('words');
        $this->assertSame('awaiting_review', $video->fresh()->status);
        $this->assertNull($video->fresh()->approved_at);
        Queue::assertNothingPushed();
    }

    public function test_stale_approval_cannot_overwrite_another_tabs_saved_draft(): void
    {
        $video = $this->video();
        $old = $this->body($video);
        $updated = $old;
        $updated['words'][0] = 'EVERY';
        $this->putJson(route('subtitles.transcript.save', $video), $updated)->assertOk();
        $this->postJson(route('subtitles.approve', $video), $old + ['confirmed' => true])->assertConflict();
        $this->assertNull($video->fresh()->approved_at);
        Queue::assertNothingPushed();
    }

    public function test_manual_render_dispatch_cannot_claim_an_unapproved_video(): void
    {
        $video = $this->video();
        $processor = $this->mock(VideoSubtitleProcessor::class);
        $processor->shouldNotReceive('render');
        (new RenderSubtitleVideo($video->id))->handle($processor);
        $this->assertSame('awaiting_review', $video->fresh()->status);
    }

    public function test_processor_blocks_unapproved_or_tampered_snapshots_before_any_media_command(): void
    {
        $video = $this->video();
        $runner = Mockery::mock(LocalMediaProcess::class);
        $runner->shouldNotReceive('run');
        $processor = new VideoSubtitleProcessor($runner, new \App\Services\Subtitles\AssSubtitleGenerator);
        $video->update(['status' => 'generating_subtitles']);
        foreach ([false, true] as $tampered) {
            if ($tampered) {
                $video->update(['transcript_approved' => $video->transcript_draft, 'approved_at' => now(), 'approved_by' => $video->user_id,
                    'approved_revision' => 1, 'approved_hash' => str_repeat('0', 64)]);
            }
            try {
                $processor->render($video->refresh());
                $this->fail('Unapproved captions must never render.');
            } catch (SubtitleFailure $exception) {
                $this->assertSame('approval_required', $exception->reason);
            }
        }
    }

    public function test_maintenance_preserves_waiting_review_and_recovers_only_explicitly_approved_renders(): void
    {
        $video = $this->video();
        $video->update(['started_at' => now()->subDay()]);
        $this->artisan('subtitles:maintain')->assertSuccessful();
        $this->assertSame('awaiting_review', $video->fresh()->status);
        Queue::assertNothingPushed();
        $this->postJson(route('subtitles.approve', $video), $this->body($video) + ['confirmed' => true])->assertOk();
        Queue::fake();
        $video->update(['approved_at' => now()->subMinutes(6)]);
        $this->artisan('subtitles:maintain')->assertSuccessful();
        Queue::assertPushed(RenderSubtitleVideo::class, 1);
        Queue::assertNotPushed(ProcessSubtitleVideo::class);
    }

    public function test_approved_worker_claims_once_and_keeps_the_approved_evidence_after_cleanup(): void
    {
        $video = $this->video();
        $this->postJson(route('subtitles.approve', $video), $this->body($video) + ['confirmed' => true])->assertOk();
        $processor = $this->partialMock(VideoSubtitleProcessor::class);
        $processor->shouldReceive('render')->once()->andReturnUsing(function (SubtitleVideo $claimed): void {
            $this->assertSame('generating_subtitles', $claimed->status);
            $this->assertTrue($claimed->hasApprovedTranscript());
            Storage::disk('subtitles')->put($claimed->directory().'/output.mp4', 'render stub');
            $claimed->update(['status' => 'completed', 'finished_at' => now()]);
        });
        (new RenderSubtitleVideo($video->id))->handle($processor);
        (new RenderSubtitleVideo($video->id))->handle($processor);
        $this->assertSame('completed', $video->fresh()->status);
        $this->assertTrue($video->fresh()->hasApprovedTranscript());
        Storage::disk('subtitles')->assertExists($video->directory().'/output.mp4');
    }

    public function test_review_retention_and_expired_approval(): void
    {
        $video = $this->video();
        $video->update(['created_at' => now()->subDays(config('subtitles.retention_days') + 1)]);
        $this->postJson(route('subtitles.approve', $video), $this->body($video) + ['confirmed' => true])->assertGone();
        $this->artisan('subtitles:maintain')->assertSuccessful();
        $this->assertNull($video->fresh());
        Storage::disk('subtitles')->assertMissing($video->directory().'/original.mp4');
    }

    public function test_duplicate_failure_callback_cannot_clobber_paused_review_or_a_locked_worker(): void
    {
        $video = $this->video();
        (new ProcessSubtitleVideo($video->id))->failed(null);
        (new RenderSubtitleVideo($video->id))->failed(null);
        $this->assertSame('awaiting_review', $video->fresh()->status);
        $video->update(['status' => 'rendering']);
        $lock = Cache::lock('subtitle-video:'.$video->id, 60);
        $this->assertTrue($lock->get());
        try {
            (new RenderSubtitleVideo($video->id))->failed(null);
        } finally {
            $lock->release();
        }
        $this->assertSame('rendering', $video->fresh()->status);
    }
}
