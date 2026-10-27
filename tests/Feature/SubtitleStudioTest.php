<?php

namespace Tests\Feature;

use App\Jobs\ProcessSubtitleVideo;
use App\Models\SubtitleVideo;
use App\Models\User;
use App\Services\Subtitles\SubtitleFailure;
use App\Services\Subtitles\VideoSubtitleProcessor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

class SubtitleStudioTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('subtitles');
        Queue::fake();
    }

    private function payload(): array
    {
        return ['video' => UploadedFile::fake()->create('video.mp4', 20, 'video/mp4'), 'upload_token' => (string) Str::uuid(), 'preset' => 'karaoke', 'language' => 'en', 'font_size' => 46, 'highlight' => '#FFFF00', 'line_length' => 26, 'vertical_margin' => 110];
    }

    private function video(User $user, string $status = 'uploaded'): SubtitleVideo
    {
        $payload = $this->payload();
        unset($payload['video'], $payload['upload_token']);
        $video = SubtitleVideo::create(['user_id' => $user->id, 'upload_token' => (string) Str::uuid(), 'original_name' => 'video.mp4', 'size_bytes' => 20, 'status' => $status, 'settings' => $payload]);
        Storage::disk('subtitles')->put($video->directory().'/original.mp4', 'original');

        return $video;
    }

    public function test_owner_can_upload_and_repeated_token_does_not_create_a_second_job(): void
    {
        $owner = User::factory()->create();
        $payload = $this->payload();
        $this->actingAs($owner)->post(route('subtitles.store'), $payload)->assertRedirect();
        $video = SubtitleVideo::firstOrFail();
        $this->assertSame('uploaded', $video->status);
        $this->assertSame($owner->id, $video->user_id);
        Storage::disk('subtitles')->assertExists($video->directory().'/original.mp4');
        $this->post(route('subtitles.store'), $payload)->assertRedirect(route('subtitles.show', $video));
        $this->assertDatabaseCount('subtitle_videos', 1);
        Queue::assertPushed(ProcessSubtitleVideo::class, 1);
    }

    public function test_validation_rejects_extension_mime_size_and_untrusted_controls(): void
    {
        $this->actingAs(User::factory()->create());
        $payload = $this->payload();
        $payload['video'] = UploadedFile::fake()->create('payload.php', 10, 'text/plain');
        $payload['highlight'] = 'red;malicious';
        $payload['preset'] = '../other';
        $this->post(route('subtitles.store'), $payload)->assertSessionHasErrors(['video', 'highlight', 'preset']);
        config(['subtitles.max_upload_kb' => 1]);
        $this->post(route('subtitles.store'), $this->payload())->assertSessionHasErrors('video');
        $this->assertDatabaseCount('subtitle_videos', 0);
        Queue::assertNothingPushed();
    }

    public function test_other_users_cannot_see_status_preview_or_download(): void
    {
        $owner = User::factory()->create();
        $video = $this->video($owner, 'completed');
        Storage::disk('subtitles')->put($video->directory().'/output.mp4', 'finished');
        $this->actingAs(User::factory()->create());
        foreach (['show', 'status', 'preview', 'download'] as $action) {
            $this->get(route('subtitles.'.$action, $video))->assertNotFound();
        }
        $this->actingAs($owner)->get(route('subtitles.download', $video))->assertOk()->assertDownload('subtitled-'.$video->id.'.mp4');
        $this->get(route('subtitles.preview', $video))->assertOk()->assertHeader('Content-Type', 'video/mp4');
    }

    public function test_unfinished_video_has_no_output_access_or_internal_error_details(): void
    {
        $owner = User::factory()->create();
        $video = $this->video($owner);
        $this->actingAs($owner)->get(route('subtitles.download', $video))->assertNotFound();
        $video->update(['status' => 'failed', 'failure_code' => 'processing_failed']);
        $this->getJson(route('subtitles.status', $video))->assertOk()->assertJsonPath('preview_url', null)->assertJsonPath('status', 'failed')->assertDontSee(storage_path());
    }

    public function test_processing_failure_preserves_original_and_cleans_intermediates(): void
    {
        $video = $this->video(User::factory()->create());
        Storage::disk('subtitles')->put($video->directory().'/audio.wav', 'temporary');
        $processor = $this->partialMock(VideoSubtitleProcessor::class);
        $processor->shouldReceive('process')->once()->andThrow(new SubtitleFailure('no_audio'));
        (new ProcessSubtitleVideo($video->id))->handle($processor);
        $this->assertSame('failed', $video->fresh()->status);
        $this->assertSame('no_audio', $video->fresh()->failure_code);
        Storage::disk('subtitles')->assertExists($video->directory().'/original.mp4');
        Storage::disk('subtitles')->assertMissing($video->directory().'/audio.wav');
        // Duplicate delivery cannot reprocess failed or completed records.
        (new ProcessSubtitleVideo($video->id))->handle($processor);
    }

    public function test_success_transitions_and_cleanup_keep_completed_output(): void
    {
        $video = $this->video(User::factory()->create());
        $processor = $this->partialMock(VideoSubtitleProcessor::class);
        $processor->shouldReceive('process')->once()->andReturnUsing(function (SubtitleVideo $claimed): void {
            $this->assertSame('extracting_audio', $claimed->status);
            Storage::disk('subtitles')->put($claimed->directory().'/output.mp4', 'output');
            $claimed->update(['status' => 'completed', 'finished_at' => now()]);
        });
        (new ProcessSubtitleVideo($video->id))->handle($processor);
        $this->assertSame('completed', $video->fresh()->status);
        Storage::disk('subtitles')->assertExists($video->directory().'/output.mp4');
        Storage::disk('subtitles')->assertExists($video->directory().'/original.mp4');
    }

    public function test_retention_removes_expired_jobs_and_orphaned_files_but_not_recent_originals(): void
    {
        $owner = User::factory()->create();
        $old = $this->video($owner, 'failed');
        $old->update(['created_at' => now()->subDays(10)]);
        $recent = $this->video($owner, 'failed');
        $orphan = 'subtitles/'.Str::uuid();
        Storage::disk('subtitles')->put($orphan.'/original.mp4', 'orphan');
        touch(Storage::disk('subtitles')->path($orphan), now()->subDays(2)->timestamp);
        $this->artisan('subtitles:maintain')->assertSuccessful();
        Storage::disk('subtitles')->assertMissing($old->directory().'/original.mp4');
        Storage::disk('subtitles')->assertExists($recent->directory().'/original.mp4');
        Storage::disk('subtitles')->assertMissing($orphan.'/original.mp4');
        $this->assertDatabaseMissing('subtitle_videos', ['id' => $old->id]);
    }
}
