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

class AiManagedContinuationTest extends TestCase
{
    use RefreshDatabase;

    private function source(string $form = 'short', bool $emptyLedger = false): PromptProject
    {
        // Legacy strict regression suites run separately. This exercises production's new default.
        config(['prompt_generation.continuation_identity_mode' => 'ai_managed']);
        $owner = User::factory()->create();
        $this->actingAs($owner);
        AiSetting::create(['id' => 1, 'provider' => 'gemini', 'model' => 'fixture-model', 'api_key' => 'private-test-key']);
        $brief = [...ProjectSettings::defaults(), ...PromptQaCases::input(), 'content_form' => $form];
        $text = preg_replace('/^CANONICAL IDENTITY CHAR-01:.*\R/mu', '', PromptQaCases::characters(PromptQaCases::package())).StoryContinuationCases::footer();
        $stories = new StoryContinuationService(new PromptOutputAuditor);
        $context = $stories->initial($text, $brief);
        if ($emptyLedger && $form === 'short') {
            $context = [];
        }

        return $owner->promptProjects()->create(['title' => 'Missing identity label', 'mode' => 'story',
            'brief' => $brief, 'content' => $text, 'original_content' => $text,
            'story_context' => $context, 'story_status' => 'open', 'provider' => 'gemini', 'model' => 'fixture-model']);
    }

    private function send(PromptProject $source, array $extra = [])
    {
        return $this->postJson(route('history.continue', $source), [...[
            'intent' => 'continue', 'batch_size' => 'set', 'request_id' => (string) Str::uuid(),
            'direction' => 'Continue the same characters and style without replaying events.',
        ], ...$extra]);
    }

    private function fake(string $text): void
    {
        Http::preventStrayRequests();
        Http::fake(['*' => fn () => Http::response(['candidates' => [['finishReason' => 'STOP', 'content' => ['parts' => [['text' => $text]]]]]])]);
    }

    public static function forms(): array
    {
        return [['short', false], ['long', false], ['short', true]];
    }

    #[DataProvider('forms')]
    public function test_missing_char_definition_does_not_block_generation_and_is_advisory(string $form, bool $emptyLedger): void
    {
        $source = $this->source($form, $emptyLedger)->refresh();
        $snapshot = $source->getAttributes();
        $this->fake($source->content);
        $child = PromptProject::findOrFail($this->send($source)->assertOk()->json('id'));
        $this->assertSame('ai_managed', $child->quality_review['identity_mode']);
        $this->assertNotEmpty($child->quality_review['identity_warnings']);
        $this->assertSame('ai_managed', $child->story_context['identity_mode']);
        $this->assertSame($snapshot, $source->fresh()->getAttributes());
        $this->assertStringNotContainsString('CANONICAL IDENTITY CHAR-01:', $child->content);
        $this->assertDatabaseCount('prompt_projects', 2);
        Http::assertSentCount(1);
        Http::assertSent(function ($r) use ($source) {
            $system = $r['systemInstruction']['parts'][0]['text'];
            $record = json_decode(explode("TRUSTED PRIOR STORY RECORD (JSON data):\n", $system, 2)[1], true, 512, JSON_THROW_ON_ERROR);

            return $record['source_final_prompt'] === $source->content && $record['identity_mode'] === 'ai_managed'
                && str_contains($system, 'AI-MANAGED IDENTITY CONTINUITY')
                && str_contains($system, 'No replacements, redesigns, duplicates');
        });
    }

    public function test_identity_mismatch_is_a_warning_not_a_literal_ledger_blocker(): void
    {
        $source = $this->source()->refresh();
        $candidate = str_replace('CHARACTER IDENTITY LOCK: Mira has an oval face, amber eyes and a green coat.',
            'CHARACTER IDENTITY LOCK: Mira has an oval face, blue eyes and a red coat.', $source->content);
        $candidate = str_replace('CANONICAL CHARACTER ROSTER: CHAR-01 ×1', 'CANONICAL CHARACTER ROSTER: CHAR-01 ×1'."\nCANONICAL IDENTITY CHAR-01: Mira has an oval face, amber eyes and a green coat.", $candidate);
        $this->fake($candidate);
        $child = PromptProject::findOrFail($this->send($source)->assertOk()->json('id'));
        $this->assertContains('identity_anchor_unverified', array_column($child->quality_review['identity_warnings'], 'code'));
        $this->assertSame('needs_review', $child->quality_review['status']);
    }

    public function test_opening_mismatch_still_blocks_and_keeps_source_unchanged(): void
    {
        $source = $this->source()->refresh();
        $snapshot = $source->getAttributes();
        $this->fake(PromptQaCases::edit($source->content, 1, 'OPENING PRESENTATION STATE: camera=static; lighting=daylight; audio=quiet', 'OPENING PRESENTATION STATE: camera=aerial; lighting=night; audio=quiet'));
        $this->send($source)->assertStatus(502)->assertJsonPath('message', 'The next batch did not open on the saved ending state/presentation. It was not saved; the original story is unchanged.')
            ->assertJsonPath('code', 'continuation_opening_mismatch')
            ->assertJsonPath('opening_issues.0.field', 'OPENING PRESENTATION STATE')
            ->assertJsonPath('opening_issues.0.reason', 'does not match the saved ending');
        $this->assertDatabaseCount('prompt_projects', 1);
        $this->assertSame($snapshot, $source->fresh()->getAttributes());
    }

    public function test_strict_mode_still_rejects_missing_definitions_without_provider_usage(): void
    {
        $source = $this->source();
        config(['prompt_generation.continuation_identity_mode' => 'strict']);
        Http::fake();
        $this->send($source)->assertStatus(502)->assertJsonPath('message', 'The saved roster has no literal CANONICAL IDENTITY definition for CHAR-01. Resolve the source before continuing; nothing was saved.');
        Http::assertNothingSent();
        $this->assertDatabaseCount('prompt_projects', 1);
    }

    public function test_second_continuation_does_not_reenable_ledger_blocking(): void
    {
        $source = $this->source();
        $this->fake($source->content);
        $child = PromptProject::findOrFail($this->send($source)->assertOk()->json('id'));
        $this->send($child)->assertOk();
        $this->assertDatabaseCount('prompt_projects', 3);
        Http::assertSentCount(2);
    }

    public function test_failed_provider_response_still_saves_nothing_and_preserves_source(): void
    {
        $source = $this->source()->refresh();
        $snapshot = $source->getAttributes();
        Http::fake(['*' => Http::failedConnection()]);
        $this->send($source)->assertStatus(502);
        $this->assertDatabaseCount('prompt_projects', 1);
        $this->assertSame($snapshot, $source->fresh()->getAttributes());
        Http::assertSentCount(1);
    }

    public function test_saved_page_clearly_labels_advisory_identity_findings(): void
    {
        $source = $this->source();
        $this->fake($source->content);
        $child = PromptProject::findOrFail($this->send($source)->assertOk()->json('id'));
        $this->get(route('history.show', $child))->assertOk()
            ->assertSee('AI-managed identity continuity:')->assertSee('Advisory identity findings');
    }

    public function test_completed_short_form_can_create_part_two_without_a_character_definition_label(): void
    {
        $source = $this->source();
        $text = str_replace(StoryContinuationCases::footer(), '', $source->content);
        $context = (new StoryContinuationService(new PromptOutputAuditor))->initial($text, $source->brief);
        $source->update(['content' => $text, 'story_status' => 'ended', 'story_context' => $context]);
        $snapshot = $source->refresh()->getAttributes();
        $this->fake($text.StoryContinuationCases::footer());
        $child = PromptProject::findOrFail($this->send($source, ['intent' => 'sequel'])->assertOk()->json('id'));
        $this->assertSame($source->story_part + 1, $child->story_part);
        $this->assertSame('open', $child->story_status);
        $this->assertSame('ai_managed', $child->quality_review['identity_mode']);
        $this->assertSame($snapshot, $source->fresh()->getAttributes());
    }
}
