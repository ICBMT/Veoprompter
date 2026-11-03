<?php

namespace Tests\Feature;

use App\Models\AiSetting;
use App\Models\PromptProject;
use App\Models\User;
use App\Services\PromptOutputAuditor;
use App\Services\StoryContinuationService;
use App\Support\ProjectSettings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\PromptQaCases;
use Tests\Support\StoryContinuationCases;
use Tests\TestCase;

class ContinuationAdvisoryBoundaryTest extends TestCase
{
    use RefreshDatabase;

    public static function forms(): array
    {
        return [['short'], ['long']];
    }

    private function source(string $form): PromptProject
    {
        config(['prompt_generation.continuation_boundary_mode' => 'advisory', 'prompt_generation.continuation_identity_mode' => 'ai_managed']);
        $owner = User::factory()->create();
        $this->actingAs($owner);
        AiSetting::create(['id' => 1, 'provider' => 'gemini', 'model' => 'fixture-model', 'api_key' => 'private-key']);
        $brief = [...ProjectSettings::defaults(), ...PromptQaCases::input(), 'content_form' => $form];
        $text = PromptQaCases::package().StoryContinuationCases::footer();

        return $owner->promptProjects()->create(['title' => 'User-reviewed boundary', 'mode' => 'story', 'brief' => $brief,
            'content' => $text, 'original_content' => $text, 'story_status' => 'open', 'provider' => 'gemini', 'model' => 'fixture-model',
            'story_context' => (new StoryContinuationService(new PromptOutputAuditor))->initial($text, $brief)]);
    }

    private function send(PromptProject $source)
    {
        return $this->postJson(route('history.continue', $source), ['intent' => 'continue', 'batch_size' => 'set', 'request_id' => (string) Str::uuid()]);
    }

    private function fake(string $candidate): void
    {
        Http::preventStrayRequests();
        Http::fake(['*' => fn () => Http::response(['candidates' => [['finishReason' => 'STOP', 'content' => ['parts' => [['text' => $candidate]]]]]])]);
    }

    #[DataProvider('forms')]
    public function test_suspected_opening_mismatch_is_saved_unchanged_for_user_review(string $form): void
    {
        $source = $this->source($form)->refresh();
        $snapshot = $source->getAttributes();
        $candidate = PromptQaCases::edit($source->content, 1, 'OPENING PRESENTATION STATE: camera=static; lighting=daylight; audio=quiet', 'OPENING PRESENTATION STATE: camera=locked-off; lighting=daylight; audio=quiet');
        $this->fake($candidate);
        $child = PromptProject::findOrFail($this->send($source)->assertOk()->json('id'));
        $this->assertSame(trim($candidate), $child->content);
        $this->assertSame($snapshot, $source->fresh()->getAttributes());
        $this->assertSame('advisory', $child->quality_review['boundary_mode']);
        $this->assertSame('needs_review', $child->quality_review['status']);
        $this->assertContains('cross_batch_opening_review', array_column($child->quality_review['continuation_warnings'], 'code'));
        $check = collect($child->quality_review['checks'])->firstWhere('id', 'story_boundary');
        $this->assertSame('needs_review', $check['status']);
        $this->assertStringNotContainsString('literally', $check['detail']);
        $this->assertDatabaseCount('prompt_projects', 2);
        Http::assertSentCount(1); // No paid retry or automatic cross-boundary rewrite.
        $this->get(route('history.show', $child))->assertOk()->assertSee('Saved for your continuity review');
    }

    public function test_strict_opt_in_still_rejects_mismatches(): void
    {
        $source = $this->source('short');
        config(['prompt_generation.continuation_boundary_mode' => 'strict']);
        $candidate = PromptQaCases::edit($source->content, 1, 'OPENING PRESENTATION STATE: camera=static; lighting=daylight; audio=quiet', 'OPENING PRESENTATION STATE: camera=aerial; lighting=night; audio=quiet');
        $this->fake($candidate);
        $this->send($source)->assertStatus(502)->assertJsonPath('code', 'continuation_opening_mismatch');
        $this->assertDatabaseCount('prompt_projects', 1);
    }

    public function test_empty_or_failed_provider_response_is_not_saved_as_a_candidate(): void
    {
        $source = $this->source('short');
        Http::fake(['*' => Http::response(['error' => ['message' => 'unavailable']], 503)]);
        $this->send($source)->assertStatus(502);
        $this->assertDatabaseCount('prompt_projects', 1);
    }

    public function test_missing_usable_ending_still_blocks_future_state_corruption(): void
    {
        $source = $this->source('long');
        $candidate = PromptQaCases::edit($source->content, 2, 'ENDING PRESENTATION STATE: camera=static; lighting=daylight; audio=quiet', 'ENDING PRESENTATION STATE:');
        $this->fake($candidate);
        $this->send($source)->assertStatus(502);
        $this->assertDatabaseCount('prompt_projects', 1);
    }
}
