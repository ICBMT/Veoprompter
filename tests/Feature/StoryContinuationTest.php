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
        $text = $this->generation()['content'];
        Http::fake(['*' => Http::response(['candidates' => [['content' => ['parts' => [['text' => $text]]]]]])]);
        $this->actingAs($owner)->postJson(route('studio.generate', $studio), ['mode' => 'story', 'topic' => 'The wheel investigation'])
            ->assertOk()->assertJsonPath('run.story_status', 'open')->assertJsonPath('saved_to_history', true);
        Http::assertSentCount(1);
        Http::assertSent(fn ($request) => str_contains(json_encode($request->data()), 'STORY BATCH CONTRACT'));
        $saved = $owner->promptProjects()->firstOrFail();
        $this->assertNotEmpty($saved->story_context['memory']['open_threads']);
        $this->assertSame($text, $saved->original_content);
        $this->get(route('history.show', $saved))->assertOk()->assertSee('Generate next clips')->assertSee('Generate ending');
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
