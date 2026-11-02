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
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\Support\PromptQaCases;
use Tests\Support\StoryContinuationCases;
use Tests\TestCase;

class ContinuationCanonicalIdentityTest extends TestCase
{
    use RefreshDatabase;

    private const FIRST = 'Mira has an oval face, amber eyes and a green coat.';

    private const SECOND = 'Tavi has a square face, dark brown eyes, a silver braid and an indigo jacket.';

    private function source(bool $multiple = false): PromptProject
    {
        $owner = User::factory()->create();
        $this->actingAs($owner);
        AiSetting::firstOrCreate(['id' => 1], ['provider' => 'gemini', 'model' => 'test-model', 'api_key' => 'test-secret']);
        $brief = [...ProjectSettings::defaults(), ...PromptQaCases::input(), 'content_form' => 'long', 'topic' => 'A shared investigation'];
        $content = PromptQaCases::characters(PromptQaCases::package()).StoryContinuationCases::footer();
        if ($multiple) {
            $content = str_replace([
                'CANONICAL CHARACTER ROSTER: CHAR-01 ×1',
                'VISIBLE CHARACTER CAST: Mira (CHAR-01) ×1',
                'CHARACTER IDENTITY LOCK: '.self::FIRST,
                'cast=Mira CHAR-01 x1',
                'CANONICAL IDENTITY CHAR-01: '.self::FIRST,
            ], [
                'CANONICAL CHARACTER ROSTER: CHAR-01 ×1; CHAR-02 ×1',
                'VISIBLE CHARACTER CAST: Mira (CHAR-01) ×1; Tavi (CHAR-02) ×1',
                'CHARACTER IDENTITY LOCK: '.self::FIRST.' '.self::SECOND,
                'cast=Mira CHAR-01 x1, Tavi CHAR-02 x1',
                'CANONICAL IDENTITY CHAR-01: '.self::FIRST."\nCANONICAL IDENTITY CHAR-02: ".self::SECOND,
            ], $content);
        }
        $auditor = new PromptOutputAuditor;
        $this->assertSame([], $auditor->audit($content, $brief)['issues']);

        return $owner->promptProjects()->create([
            'title' => 'A shared investigation', 'mode' => 'story', 'brief' => $brief,
            'content' => $content, 'original_content' => $content, 'quality_review' => $auditor->audit($content, $brief),
            'story_context' => (new StoryContinuationService($auditor))->initial($content, $brief),
            'story_status' => 'open', 'provider' => 'gemini', 'model' => 'test-model',
        ])->refresh();
    }

    private function withoutDefinitions(string $content): string
    {
        return preg_replace('/^CANONICAL IDENTITY CHAR-\d+:.*\R/mu', '', $content);
    }

    private function reply(string $text): array
    {
        return ['candidates' => [['content' => ['parts' => [['text' => $text]]]]]];
    }

    private function continue(PromptProject $source)
    {
        return $this->postJson(route('history.continue', $source), [
            'intent' => 'continue', 'batch_size' => 'set', 'request_id' => (string) Str::uuid(),
            'direction' => 'Keep the established cast and continue the investigation.',
        ]);
    }

    public function test_missing_definition_is_carried_literally_from_source_before_the_first_audit(): void
    {
        $source = $this->source();
        $original = $source->getAttributes();
        $draft = $this->withoutDefinitions($source->content);
        $rawReview = (new PromptOutputAuditor)->audit($draft, $source->brief);
        $this->assertContains('canonical_identity_missing', array_column($rawReview['issues'], 'code'));
        $this->assertStringContainsString('Declared character CHAR-01 has no literal CANONICAL IDENTITY definition.', implode(' ', array_column($rawReview['issues'], 'message')));
        Http::fake(['*' => Http::response($this->reply($draft))]);
        $response = $this->continue($source)->assertOk();
        $child = PromptProject::findOrFail($response->json('id'));
        $this->assertSame(trim($source->content), $child->content);
        $this->assertSame(['CHAR-01'], $child->quality_review['first_pass']['serialization']['inherited_identity_ids']);
        $this->assertTrue($child->quality_review['first_pass']['was_clean']);
        $this->assertSame([], $child->quality_review['issues']);
        $this->assertSame($original, $source->fresh()->getAttributes());
        Http::assertSentCount(1);
        Http::assertSent(fn ($request) => str_contains(json_encode($request->data()), self::FIRST)
            && str_contains(json_encode($request->data()), 'names, summaries, story memory and reconstructed descriptions are never substitutes'));
    }

    public function test_valid_literal_definition_is_preserved_without_duplicates_or_identity_reconstruction(): void
    {
        $source = $this->source();
        $text = str_replace('The wheel is turning; its purpose is still being investigated.',
            'A misleading memory says Mira has blue eyes and a red coat.', $source->content);
        $source->update(['content' => $text, 'original_content' => $text,
            'story_context' => (new StoryContinuationService(new PromptOutputAuditor))->initial($text, $source->brief)]);
        $original = $source->refresh()->getAttributes();
        Http::fake(['*' => Http::response($this->reply($source->content))]);
        $child = PromptProject::findOrFail($this->continue($source)->assertOk()->json('id'));
        $this->assertSame(trim($source->content), $child->content);
        $this->assertSame(1, substr_count($child->content, 'CANONICAL IDENTITY CHAR-01: '.self::FIRST));
        $this->assertSame([], $child->quality_review['first_pass']['serialization']['inherited_identity_ids']);
        $this->assertSame($original, $source->fresh()->getAttributes());
        Http::assertSentCount(1);
    }

    public function test_multiple_declared_characters_each_receive_their_complete_distinct_source_definition(): void
    {
        $source = $this->source(true);
        $original = $source->getAttributes();
        $draft = $this->withoutDefinitions($source->content);
        $draft = str_replace('CHARACTER IDENTITY LOCK: '.self::FIRST.' '.self::SECOND,
            'CHARACTER IDENTITY LOCK: {{VEO_IDENTITY:CHAR-01}} {{VEO_IDENTITY:CHAR-02}}', $draft);
        Http::fake(['*' => Http::response($this->reply($draft))]);
        $child = PromptProject::findOrFail($this->continue($source)->assertOk()->json('id'));
        $this->assertSame(['CHAR-01', 'CHAR-02'], $child->quality_review['first_pass']['serialization']['inherited_identity_ids']);
        $this->assertSame(trim($source->content), $child->content);
        $this->assertSame([], $child->quality_review['issues']);
        $this->assertSame($original, $source->fresh()->getAttributes());
        Http::assertSentCount(1);
    }

    public function test_an_unknown_declared_id_without_a_definition_is_rejected_and_source_is_unchanged(): void
    {
        $source = $this->source();
        $original = $source->getAttributes();
        $draft = str_replace('CANONICAL CHARACTER ROSTER: CHAR-01 ×1',
            'CANONICAL CHARACTER ROSTER: CHAR-01 ×1; CHAR-99 ×1', $this->withoutDefinitions($source->content));
        Http::fake(['*' => Http::response($this->reply($draft))]);
        $this->continue($source)->assertStatus(502)->assertJsonPath('message',
            'The continuation has an unresolved identity/roster finding. It was not saved: Declared character CHAR-99 has no literal CANONICAL IDENTITY definition.');
        $this->assertDatabaseCount('prompt_projects', 1);
        $this->assertSame($original, $source->fresh()->getAttributes());
        Http::assertSentCount(1);
    }

    public function test_a_reconstructed_or_changed_canonical_definition_is_not_silently_overwritten(): void
    {
        $source = $this->source();
        $original = $source->getAttributes();
        $draft = str_replace('CANONICAL IDENTITY CHAR-01: '.self::FIRST,
            'CANONICAL IDENTITY CHAR-01: Mira is a familiar person wearing green.', $source->content);
        Http::fake(['*' => Http::response($this->reply($draft))]);
        $this->continue($source)->assertStatus(502)->assertJsonPath('message', 'The continuation redefined canonical identity CHAR-01. It was not saved.');
        $this->assertDatabaseCount('prompt_projects', 1);
        $this->assertSame($original, $source->fresh()->getAttributes());
        Http::assertSentCount(1);
    }

    public function test_omitted_definitions_in_one_shot_repair_are_also_compiled_and_final_output_is_audited(): void
    {
        $source = $this->source(true);
        $original = $source->getAttributes();
        $fixed = $this->withoutDefinitions($source->content);
        $draft = str_replace('OUTGOING HANDOFF TYPE: TYPE A — DIRECT CONTINUATION', '', $fixed);
        Http::fakeSequence()->push($this->reply($draft))->push($this->reply($fixed));
        $child = PromptProject::findOrFail($this->continue($source)->assertOk()->json('id'));
        $this->assertTrue($child->quality_review['corrected']);
        $this->assertContains('outgoing_handoff_missing', $child->quality_review['first_pass']['issue_codes']);
        $this->assertSame([], $child->quality_review['issues']);
        $this->assertSame(trim($source->content), $child->content);
        $this->assertSame($original, $source->fresh()->getAttributes());
        Http::assertSentCount(2);
    }

    public function test_repaired_output_with_an_unresolved_identity_is_never_saved_even_if_other_qa_improves(): void
    {
        $source = $this->source();
        $original = $source->getAttributes();
        $fixed = str_replace('CANONICAL CHARACTER ROSTER: CHAR-01 ×1',
            'CANONICAL CHARACTER ROSTER: CHAR-01 ×1; CHAR-99 ×1', $this->withoutDefinitions($source->content));
        $draft = str_replace('OUTGOING HANDOFF TYPE: TYPE A — DIRECT CONTINUATION', '', $fixed);
        Http::fakeSequence()->push($this->reply($draft))->push($this->reply($fixed));
        $this->continue($source)->assertStatus(502)->assertJsonPath('message',
            'The continuation has an unresolved identity/roster finding. It was not saved: Declared character CHAR-99 has no literal CANONICAL IDENTITY definition.');
        $this->assertDatabaseCount('prompt_projects', 1);
        $this->assertSame($original, $source->fresh()->getAttributes());
        Http::assertSentCount(2);
    }

    public function test_the_acceptance_gate_still_rejects_missing_definitions_if_generation_is_bypassed(): void
    {
        $source = $this->source();
        $original = $source->getAttributes();
        $text = $this->withoutDefinitions($source->content);
        $this->mock(PromptGenerationService::class)->shouldReceive('generateWithQualityReview')->once()->andReturn([
            'content' => $text, 'quality_review' => (new PromptOutputAuditor)->audit($text, $source->brief),
        ]);
        $this->continue($source)->assertStatus(502)->assertJsonPath('message',
            'The continuation has an unresolved identity/roster finding. It was not saved: Declared character CHAR-01 has no literal CANONICAL IDENTITY definition.');
        $this->assertDatabaseCount('prompt_projects', 1);
        $this->assertSame($original, $source->fresh()->getAttributes());
    }

    public function test_copying_a_header_does_not_accept_a_variant_in_the_clip_identity_lock(): void
    {
        $source = $this->source();
        $original = $source->getAttributes();
        $draft = str_replace('CHARACTER IDENTITY LOCK: '.self::FIRST,
            'CHARACTER IDENTITY LOCK: Mira has a different face and a blue coat.', $this->withoutDefinitions($source->content));
        Http::fake(['*' => Http::response($this->reply($draft))]);
        $response = $this->continue($source)->assertStatus(502);
        $this->assertStringContainsString('does not repeat canonical anchor CHAR-01 verbatim', $response->json('message'));
        $this->assertDatabaseCount('prompt_projects', 1);
        $this->assertSame($original, $source->fresh()->getAttributes());
        Http::assertSentCount(1);
    }

    public function test_complete_definitions_do_not_authorize_a_duplicate_cast_count(): void
    {
        $source = $this->source();
        $original = $source->getAttributes();
        $draft = str_replace(['CANONICAL CHARACTER ROSTER: CHAR-01 ×1', 'VISIBLE CHARACTER CAST: Mira (CHAR-01) ×1'],
            ['CANONICAL CHARACTER ROSTER: CHAR-01 ×2', 'VISIBLE CHARACTER CAST: Mira (CHAR-01) ×2'], $source->content);
        Http::fake(['*' => Http::response($this->reply($draft))]);
        $this->continue($source)->assertStatus(502)->assertJsonPath('message', 'The continuation changed the established cast count for CHAR-01. It was not saved.');
        $this->assertDatabaseCount('prompt_projects', 1);
        $this->assertSame($original, $source->fresh()->getAttributes());
        Http::assertSentCount(1);
    }

    public function test_a_fully_defined_but_unplanned_new_character_is_still_rejected(): void
    {
        $source = $this->source();
        $original = $source->getAttributes();
        $draft = str_replace('CANONICAL CHARACTER ROSTER: CHAR-01 ×1',
            'CANONICAL CHARACTER ROSTER: CHAR-01 ×1; CHAR-99 ×1'."\nCANONICAL IDENTITY CHAR-99: An unrequested new character with violet eyes and a white coat.", $source->content);
        Http::fake(['*' => Http::response($this->reply($draft))]);
        $response = $this->continue($source)->assertStatus(502);
        $this->assertStringContainsString('introduced a new recurring subject without permission', $response->json('message'));
        $this->assertDatabaseCount('prompt_projects', 1);
        $this->assertSame($original, $source->fresh()->getAttributes());
        Http::assertSentCount(1);
    }

    public function test_missing_source_ledger_is_not_reconstructed_from_names_or_memory(): void
    {
        $source = $this->source();
        $context = $source->story_context;
        $context['canon'] = str_replace('CANONICAL IDENTITY CHAR-01: '.self::FIRST, '', $context['canon']);
        unset($context['anchors']['CHAR-01']);
        $source->update(['story_context' => $context]);
        $original = $source->refresh()->getAttributes();
        Http::fake();
        $this->continue($source)->assertStatus(502)->assertJsonPath('message',
            'The saved roster has no literal CANONICAL IDENTITY definition for CHAR-01. Resolve the source before continuing; nothing was saved.');
        $this->assertDatabaseCount('prompt_projects', 1);
        $this->assertSame($original, $source->fresh()->getAttributes());
        Http::assertNothingSent();
    }
}
