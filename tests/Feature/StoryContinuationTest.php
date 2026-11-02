<?php

namespace Tests\Feature;

use App\Models\AiSetting;
use App\Models\PromptProject;
use App\Models\User;
use App\Services\PromptGenerationService;
use App\Services\PromptOutputAuditor;
use App\Services\StoryContinuationService;
use App\Support\ProjectSettings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Mockery\MockInterface;
use Tests\Support\PromptQaCases;
use Tests\Support\StoryContinuationCases;
use Tests\TestCase;

class StoryContinuationTest extends TestCase
{
    use RefreshDatabase;

    private function source(User $owner, string $form = 'short'): PromptProject
    {
        $brief = [...ProjectSettings::defaults(), ...PromptQaCases::input(), 'topic' => 'The wheel investigation', 'content_form' => $form];
        $content = PromptQaCases::package().($form === 'long' ? StoryContinuationCases::footer() : '');

        return $owner->promptProjects()->create([
            'title' => 'The wheel investigation', 'mode' => 'story', 'brief' => $brief, 'content' => $content,
            'original_content' => $content, 'quality_review' => (new PromptOutputAuditor)->audit($content, $brief),
            'story_context' => (new StoryContinuationService(new PromptOutputAuditor))->initial($content, $brief),
            'story_status' => $form === 'long' ? 'open' : 'ended', 'provider' => 'gemini', 'model' => 'test-model',
        ]);
    }

    private function connect(): void
    {
        AiSetting::query()->create(['id' => 1, 'provider' => 'gemini', 'model' => 'test-model', 'api_key' => 'test-secret']);
    }

    private function generation(string $status = 'open'): array
    {
        $content = PromptQaCases::package().StoryContinuationCases::footer($status);

        return ['content' => $content, 'quality_review' => (new PromptOutputAuditor)->audit($content, PromptQaCases::input())];
    }

    private function payload(string $intent = 'sequel'): array
    {
        return ['intent' => $intent, 'batch_size' => 'set', 'direction' => 'Reveal how the same wheel is driven.', 'request_id' => (string) Str::uuid()];
    }

    public function test_long_form_initial_generation_uses_the_shared_pipeline_and_saves_an_open_batch(): void
    {
        $owner = User::factory()->create();
        $this->connect();
        $studio = $owner->studioProjects()->create(['name' => 'Long story', 'settings' => [...ProjectSettings::defaults(), 'content_form' => 'long']]);
        $text = trim($this->generation()['content']);
        Http::fake(['*' => Http::response(['candidates' => [['content' => ['parts' => [['text' => $text]]]]]])]);
        $this->actingAs($owner)->postJson(route('studio.generate', $studio), ['mode' => 'story', 'topic' => 'The wheel investigation'])
            ->assertOk()->assertJsonPath('run.story_status', 'open')->assertJsonPath('saved_to_history', true);
        Http::assertSentCount(1);
        Http::assertSent(fn ($request) => str_contains(json_encode($request->data()), 'STORY BATCH CONTRACT'));
        $saved = $owner->promptProjects()->firstOrFail();
        $this->assertNotEmpty($saved->story_context['memory']['open_threads']);
        $this->assertSame($text, $saved->original_content);
        $this->get(route('history.show', $saved))->assertOk()->assertSee('Generate next clips')->assertSee('Generate ending');
        $this->assertSame('open', $saved->story_context['status']);
        $this->assertSame(hash('sha256', $saved->content), $saved->story_context['batch_state']['content_sha256']);
        $this->assertSame(2, $saved->story_context['batch_state']['clip_count']);
        $this->assertSame(PromptQaCases::state(), $saved->story_context['batch_state']['ending_state']);
        $this->assertNotEmpty($saved->story_context['batch_state']['ending_presentation']);
        Http::assertSent(fn ($request) => str_contains(json_encode($request->data()), 'sole exception') && str_contains(json_encode($request->data()), 'three separate'));
        $this->mock(PromptGenerationService::class, function (MockInterface $mock): void {
            $mock->shouldReceive('generateWithQualityReview')->once()->andReturn($this->generation());
        });
        $response = $this->postJson(route('history.continue', $saved), $this->payload('continue'))->assertOk();
        $this->assertSame($saved->id, PromptProject::findOrFail($response->json('id'))->parent_prompt_id);
        $this->assertSame($text, $saved->fresh()->content);

    }

    public function test_opening_state_is_captured_after_serialization_and_automatic_repairs(): void
    {
        $owner = User::factory()->create();
        $this->connect();
        $studio = $owner->studioProjects()->create(['name' => 'Long story', 'settings' => [...ProjectSettings::defaults(), 'content_form' => 'long']]);
        $raw = str_replace('0.2–2.2s', '0.0–2.0s', PromptQaCases::template()).StoryContinuationCases::footer();
        Http::fake(['*' => Http::response(['candidates' => [['content' => ['parts' => [['text' => $raw]]]]]])]);
        $this->actingAs($owner)->postJson(route('studio.generate', $studio), ['mode' => 'story', 'topic' => 'A connected investigation'])
            ->assertOk()->assertJsonPath('saved_to_history', true);
        $saved = $owner->promptProjects()->firstOrFail();
        $this->assertStringNotContainsString('{{VEO_', $saved->content);
        $this->assertNotSame(hash('sha256', trim($raw)), $saved->story_context['batch_state']['content_sha256']);
        $this->assertSame(hash('sha256', $saved->content), $saved->story_context['batch_state']['content_sha256']);
        $this->assertTrue($saved->quality_review['corrected']);
        $this->assertSame($saved->content, $saved->original_content);
        (new StoryContinuationService(new PromptOutputAuditor))->prepare($saved->content, $saved->brief, $saved->story_context, 'open', 'continue', '', 'set');
        Http::assertSentCount(1);
    }

    public function test_next_batch_is_compiled_from_the_exact_persisted_opening_snapshot_not_summary(): void
    {
        $owner = User::factory()->create();
        $this->connect();
        $studio = $owner->studioProjects()->create(['name' => 'Connected story', 'settings' => [...ProjectSettings::defaults(), 'content_form' => 'long']]);
        $state = 'cast=Mira CHAR-01 x1; character_position=left of workbench; object=wheel OBJ-01; object_position=right edge of workbench; stage=in progress; location=workshop; environment=closed north door; motion=clockwise';
        $presentation = 'camera=locked medium from north; framing=Mira left and wheel right; lighting=warm west window; style=hand-painted cobalt palette; audio=quiet motor hum, narrator has finished the prior line';
        $root = str_replace([str_replace('cast=None', 'cast=Mira CHAR-01 x1', PromptQaCases::state()), 'camera=static; lighting=daylight; audio=quiet'],
            [$state, $presentation], PromptQaCases::characters(PromptQaCases::package())).StoryContinuationCases::footer();
        $next = PromptQaCases::edit($root, 1, 'OPENING CONTINUITY STATE: '.$state, 'OPENING CONTINUITY STATE: {{VEO_SOURCE_END_STATE}}');
        $next = PromptQaCases::edit($next, 1, 'OPENING PRESENTATION STATE: '.$presentation, 'OPENING PRESENTATION STATE: {{VEO_SOURCE_END_PRESENTATION}}');
        Http::fakeSequence()->push(['candidates' => [['content' => ['parts' => [['text' => $root]]]]]])
            ->push(['candidates' => [['content' => ['parts' => [['text' => $next]]]]]]);
        $created = $this->actingAs($owner)->postJson(route('studio.generate', $studio), ['mode' => 'story', 'topic' => 'A connected investigation'])->assertOk();
        $source = PromptProject::findOrFail($created->json('run.id'));
        $snapshot = $source->story_context['batch_state'];
        $this->assertSame($state, $snapshot['ending_state']);
        $this->assertSame($presentation, $snapshot['ending_presentation']);
        $this->assertStringNotContainsString('warm west window', $source->story_context['memory']['summary']);
        $original = $source->getAttributes();
        $response = $this->postJson(route('history.continue', $source), $this->payload('continue'))->assertOk();
        Http::assertSentCount(2);
        Http::assertSent(function ($request) use ($snapshot): bool {
            $system = data_get($request->data(), 'systemInstruction.parts.0.text', '');
            if (! str_contains($system, 'AUTHORITATIVE OPENING INHERITANCE')) { return false; }
            $record = json_decode(Str::after($system, "TRUSTED PRIOR STORY RECORD (JSON data):\n"), true);
            return ($record['source_batch_snapshot'] ?? null) === $snapshot
                && ($record['authoritative_opening_snapshot'] ?? null) === ['ending_state' => $snapshot['ending_state'], 'ending_presentation' => $snapshot['ending_presentation']]
                && str_contains($system, 'OPENING CONTINUITY STATE: {{VEO_SOURCE_END_STATE}}')
                && str_contains($system, 'OPENING PRESENTATION STATE: {{VEO_SOURCE_END_PRESENTATION}}');
        });
        $child = PromptProject::findOrFail($response->json('id'));
        $first = (new PromptOutputAuditor)->extractClips($child->content, $child->brief)[0]['text'];
        $this->assertSame($state, (new PromptOutputAuditor)->fieldValue($first, 'OPENING CONTINUITY STATE'));
        $this->assertSame($presentation, (new PromptOutputAuditor)->fieldValue($first, 'OPENING PRESENTATION STATE'));
        $this->assertStringNotContainsString('{{VEO_', $child->content);
        $this->assertSame(2, $child->quality_review['first_pass']['serialization']['expanded']);
        $this->assertSame($source->id, $child->parent_prompt_id);
        $this->assertSame($original, $source->fresh()->getAttributes());
    }

    public function test_literal_opening_mismatches_are_rejected_before_repairs_and_leave_the_entire_source_unchanged(): void
    {
        $owner = User::factory()->create();
        $source = $this->source($owner, 'long')->refresh();
        $original = $source->getAttributes();
        $this->connect();
        foreach (['OPENING CONTINUITY STATE' => 'cast=None; object=unrelated subject; location=elsewhere',
            'OPENING PRESENTATION STATE' => 'camera=aerial; lighting=night; audio=new dialogue'] as $field => $wrong) {
            $text = $this->generation()['content'];
            $first = (new PromptOutputAuditor)->extractClips($text, $source->brief)[0]['text'];
            $text = PromptQaCases::edit($text, 1, $field.': '.(new PromptOutputAuditor)->fieldValue($first, $field), $field.': '.$wrong);
            Http::fake(['*' => Http::response(['candidates' => [['content' => ['parts' => [['text' => $text]]]]]])]);
            $this->actingAs($owner)->postJson(route('history.continue', $source), $this->payload('continue'))->assertStatus(502)
                ->assertJsonPath('message', 'The next batch did not open on the saved ending state/presentation. It was not saved; the original story is unchanged.');
            Http::assertSentCount(1); // No hidden correction that overwrites incompatible literal states.
            $this->assertDatabaseCount('prompt_projects', 1);
            $this->assertSame($original, $source->fresh()->getAttributes());
        }
    }

    public function test_invalid_long_openings_are_errors_not_successful_unsaved_or_invented_stories(): void
    {
        $owner = User::factory()->create();
        $this->connect();
        $studio = $owner->studioProjects()->create(['name' => 'Long story', 'settings' => [...ProjectSettings::defaults(), 'content_form' => 'long']]);
        foreach ([PromptQaCases::package(), PromptQaCases::package().StoryContinuationCases::footer('ended'),
            $this->generation()['content']."STORY STATUS: OPEN\n",
            str_replace('STORY MEMORY: {', 'STORY MEMORY: invalid {', $this->generation()['content'])] as $text) {
            Http::fake(['*' => Http::response(['candidates' => [['content' => ['parts' => [['text' => $text]]]]]])]);
            $this->actingAs($owner)->postJson(route('studio.generate', $studio), ['mode' => 'story', 'topic' => 'A connected investigation'])->assertStatus(502);
            $this->assertSame(0, $owner->promptProjects()->count());
        }
    }

    public function test_continuation_rejects_missing_stale_or_tampered_persisted_state_without_changing_source(): void
    {
        $owner = User::factory()->create();
        $source = $this->source($owner, 'long');
        $good = $source->story_context;
        $this->connect();
        Http::fake();
        $badStates = [[], array_diff_key($good, ['memory' => true]), array_diff_key($good, ['batch_state' => true]),
            [...$good, 'status' => 'ended'], [...$good, 'memory' => [...$good['memory'], 'summary' => 'Invented outcome']],
            [...$good, 'batch_state' => [...$good['batch_state'], 'ending_state' => 'A different ending']],
            [...$good, 'batch_state' => [...$good['batch_state'], 'content_sha256' => str_repeat('0', 64)]]];
        foreach ($badStates as $bad) {
            $source->update(['story_context' => $bad]);
            $this->actingAs($owner)->postJson(route('history.continue', $source), $this->payload('continue'))->assertStatus(502);
            $this->assertSame($source->content, $source->fresh()->content);
            $this->assertSame($source->original_content, $source->fresh()->original_content);
        }
        Http::assertNothingSent();
        $this->assertSame(1, $owner->promptProjects()->count());
    }

    public function test_long_opening_is_not_reported_successful_when_atomic_history_save_fails(): void
    {
        $owner = User::factory()->create();
        $this->connect();
        $studio = $owner->studioProjects()->create(['name' => 'Long story', 'settings' => [...ProjectSettings::defaults(), 'content_form' => 'long']]);
        $this->mock(PromptGenerationService::class, function (MockInterface $mock): void {
            $mock->shouldReceive('generateWithQualityReview')->once()->andReturn($this->generation());
        });
        // Fail after the source INSERT, proving the transaction rolls back the incomplete save.
        \Illuminate\Support\Facades\Event::listen('eloquent.created: '.PromptProject::class, function (): void {
            throw new \RuntimeException('Synthetic storage failure');
        });
        $this->actingAs($owner)->postJson(route('studio.generate', $studio), ['mode' => 'story', 'topic' => 'A connected investigation'])
            ->assertStatus(503)->assertJsonPath('saved_to_history', false);
        $this->assertSame(0, $owner->promptProjects()->count());
    }

    public function test_repair_and_restore_keep_long_form_snapshot_bound_to_the_actual_saved_batch(): void
    {
        $owner = User::factory()->create();
        $source = $this->source($owner, 'long');
        $content = str_replace('0.2–2.2s', '0.0–2.0s', $source->content);
        $stories = new StoryContinuationService(new PromptOutputAuditor);
        $source->update(['content' => $content, 'original_content' => $content,
            'story_context' => $stories->refreshBatchState($content, $source->brief, $source->story_context, 'open')]);
        $this->actingAs($owner)->postJson(route('history.repair', $source))->assertOk()->assertJsonPath('repair_report.applied', true);
        $source->refresh();
        $this->assertNotSame($content, $source->content);
        $this->assertSame(hash('sha256', $source->content), $source->story_context['batch_state']['content_sha256']);
        $stories->prepare($source->content, $source->brief, $source->story_context, 'open', 'continue', '', 'set');
        $this->postJson(route('history.restore', $source))->assertOk();
        $source->refresh();
        $this->assertSame($content, $source->content);
        $this->assertSame(hash('sha256', $content), $source->story_context['batch_state']['content_sha256']);
        $stories->prepare($source->content, $source->brief, $source->story_context, 'open', 'continue', '', 'set');
    }

    public function test_continuations_require_authentication_and_ownership(): void
    {
        $source = $this->source(User::factory()->create());
        $this->postJson(route('history.continue', $source), $this->payload())->assertUnauthorized();
        $this->actingAs(User::factory()->create())->postJson(route('history.continue', $source), $this->payload())->assertNotFound();
    }

    public function test_short_form_sequel_and_explicit_ending_are_new_linked_kits_with_originals_preserved(): void
    {
        $owner = User::factory()->create();
        $source = $this->source($owner);
        $original = $source->content;
        $this->connect();
        $inputs = [];
        $this->mock(PromptGenerationService::class, function (MockInterface $mock) use (&$inputs): void {
            $mock->shouldReceive('generateWithQualityReview')->twice()->withArgs(function ($settings, $input) use (&$inputs): bool {
                $inputs[] = $input;

                return true;
            })->andReturn($this->generation(), $this->generation('ended'));
        });
        $response = $this->actingAs($owner)->postJson(route('history.continue', $source), $this->payload())->assertOk();
        $part = PromptProject::query()->findOrFail($response->json('id'));
        $this->assertSame(2, $part->story_part);
        $this->assertSame(1, $part->story_batch);
        $this->assertSame('open', $part->story_status);
        $this->assertSame($source->id, $part->parent_prompt_id);
        $this->assertArrayNotHasKey('_story_context', $part->brief);
        $this->assertStringContainsString('same wheel', $inputs[0]['_story_context']['direction']);
        $this->postJson(route('history.continue', $part), $this->payload('finish'))->assertOk();
        $end = PromptProject::query()->latest('id')->first();
        $this->assertSame(2, $end->story_part);
        $this->assertSame(2, $end->story_batch);
        $this->assertSame('ended', $end->story_status);
        $this->assertSame($original, $source->fresh()->content);
        $this->assertSame($original, $source->fresh()->original_content);
        $this->get(route('history.show', $end))->assertOk()->assertSee('Create Part 3')->assertSee('data-story-form', false);
        $this->get(route('history.index'))->assertOk()->assertSee('data-story-form', false);
    }

    public function test_retries_do_not_regenerate_or_duplicate_and_changed_payload_cannot_reuse_the_id(): void
    {
        $owner = User::factory()->create();
        $source = $this->source($owner);
        $this->connect();
        $this->mock(PromptGenerationService::class, fn (MockInterface $mock) => $mock->shouldReceive('generateWithQualityReview')->once()->andReturn($this->generation()));
        $payload = $this->payload();
        $this->actingAs($owner);
        $first = $this->postJson(route('history.continue', $source), $payload)->assertOk();
        $this->postJson(route('history.continue', $source), $payload)->assertOk()->assertJsonPath('id', $first->json('id'));
        $this->postJson(route('history.continue', $source), [...$payload, 'direction' => 'Different direction'])->assertStatus(409);
        $this->assertDatabaseCount('prompt_projects', 2);
    }

    public function test_in_progress_request_lock_prevents_duplicate_provider_calls(): void
    {
        $owner = User::factory()->create();
        $source = $this->source($owner);
        $payload = $this->payload();
        $key = hash('sha256', $owner->id.':'.$source->id.':'.$payload['request_id']);
        $lock = Cache::lock('story-generation:'.$key, 360);
        $lock->get();
        $this->mock(PromptGenerationService::class, fn (MockInterface $mock) => $mock->shouldNotReceive('generateWithQualityReview'));
        try {
            $this->actingAs($owner)->postJson(route('history.continue', $source), $payload)->assertStatus(409);
        } finally {
            $lock->release();
        }
    }

    public function test_boundary_or_identity_drift_is_not_saved_and_source_is_untouched(): void
    {
        $owner = User::factory()->create();
        $source = $this->source($owner, 'long');
        $original = $source->content;
        $this->connect();
        $bad = $this->generation();
        $bad['content'] = PromptQaCases::edit($bad['content'], 1, 'OPENING CONTINUITY STATE: '.PromptQaCases::state(), 'OPENING CONTINUITY STATE: a different world');
        $this->mock(PromptGenerationService::class, fn (MockInterface $mock) => $mock->shouldReceive('generateWithQualityReview')->once()->andReturn($bad));
        $this->actingAs($owner)->postJson(route('history.continue', $source), $this->payload('continue'))->assertStatus(502);
        $this->assertDatabaseCount('prompt_projects', 1);
        $this->assertSame($original, $source->fresh()->content);
    }

    public function test_no_blind_commit_when_a_source_is_repaired_during_generation(): void
    {
        $owner = User::factory()->create();
        $source = $this->source($owner);
        $this->connect();
        $this->mock(PromptGenerationService::class, function (MockInterface $mock) use ($source): void {
            $mock->shouldReceive('generateWithQualityReview')->once()->andReturnUsing(function () use ($source): array {
                $source->update(['content' => $source->content."\nA concurrent edit."]);

                return $this->generation();
            });
        });
        $this->actingAs($owner)->postJson(route('history.continue', $source), $this->payload())->assertStatus(409);
        $this->assertDatabaseCount('prompt_projects', 1);
    }

    public function test_state_only_changes_during_generation_cannot_attach_a_child_to_an_obsolete_snapshot(): void
    {
        $owner = User::factory()->create();
        $source = $this->source($owner, 'long');
        $original = $source->content;
        $this->connect();
        $this->mock(PromptGenerationService::class, function (MockInterface $mock) use ($source): void {
            $mock->shouldReceive('generateWithQualityReview')->once()->andReturnUsing(function () use ($source): array {
                $source->update(['story_context' => [...$source->story_context, 'status' => 'ended']]);

                return $this->generation();
            });
        });
        $this->actingAs($owner)->postJson(route('history.continue', $source), $this->payload('continue'))->assertStatus(409);
        $this->assertDatabaseCount('prompt_projects', 1);
        $this->assertSame($original, $source->fresh()->content);
    }

    public function test_direction_and_explicit_new_subject_permission_are_validated(): void
    {
        $owner = User::factory()->create();
        $source = $this->source($owner);
        $this->actingAs($owner)->postJson(route('history.continue', $source), [...$this->payload(), 'direction' => str_repeat('a', 4001)])->assertUnprocessable();
        $this->postJson(route('history.continue', $source), [...$this->payload(), 'allow_new_subjects' => true, 'direction' => ''])->assertUnprocessable();
        $this->postJson(route('history.continue', $source), [...$this->payload(), 'intent' => 'unknown'])->assertUnprocessable();
    }

    public function test_long_form_preference_is_in_the_one_question_wizard_and_settings(): void
    {
        $owner = User::factory()->create();
        $this->actingAs($owner)->get(route('projects.create'))->assertOk()->assertSee('Choose content length')->assertSee('name="content_form" value="long"', false);
        $this->assertSame('short', ProjectSettings::defaults()['content_form']);
        $this->assertSame('long', ProjectSettings::fromValidated(['content_form' => 'long'])['content_form']);
    }
}
