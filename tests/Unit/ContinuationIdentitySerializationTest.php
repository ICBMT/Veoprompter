<?php

namespace Tests\Unit;

use App\Services\PromptGenerationException;
use App\Services\PromptOutputAuditor;
use App\Services\PromptPlanSerializer;
use App\Services\StoryContinuationService;
use Tests\Support\PromptQaCases;
use Tests\Support\StoryContinuationCases;
use Tests\TestCase;

class ContinuationIdentitySerializationTest extends TestCase
{
    private const DEFINITION = 'Neri has an oval face, amber eyes, a green coat with copper buttons, a crescent scar on the left cheek, and a low, measured voice.';

    private function fixture(): array
    {
        $root = str_replace(['CHAR-01', 'Mira has an oval face, amber eyes and a green coat.', 'Mira'],
            ['CAST-17', self::DEFINITION, 'Neri'], PromptQaCases::characters(PromptQaCases::package())).StoryContinuationCases::footer();
        $brief = [...PromptQaCases::input(), 'content_form' => 'long'];
        $stories = new StoryContinuationService(new PromptOutputAuditor);
        $input = $stories->prepare($root, $brief, $stories->initial($root, $brief), 'open', 'continue', '', 'set');
        $missing = str_replace('CANONICAL IDENTITY CAST-17: '.self::DEFINITION."\n", '', $root);

        return [$root, $missing, $input];
    }

    public function test_arbitrary_ids_and_complete_extended_definitions_are_inherited_once_and_serialization_is_idempotent(): void
    {
        [$root, $missing, $input] = $this->fixture();
        $serializer = new PromptPlanSerializer(new PromptOutputAuditor);
        $rendered = $serializer->serialize($missing, $input);
        $this->assertSame($root, $rendered['content']);
        $this->assertSame(['CAST-17'], $rendered['inherited_identity_ids']);
        $this->assertSame(hash('sha256', $missing), $rendered['template_sha256']);
        $this->assertSame($root, $serializer->serialize($root, $input)['content']);
        $this->assertSame([], $serializer->serialize($root, $input)['inherited_identity_ids']);
        $this->assertSame(1, substr_count($rendered['content'], 'CANONICAL IDENTITY CAST-17:'));
        (new StoryContinuationService(new PromptOutputAuditor))->assertIdentities($rendered['content'], $input);
    }

    public function test_construction_does_not_interpolate_token_like_spoken_words(): void
    {
        [, $missing, $input] = $this->fixture();
        $spoken = 'Literal {{VEO_IDENTITY:CAST-17}} spoken.';
        $draft = str_replace('It keeps turning.', $spoken, $missing);
        $rendered = (new PromptPlanSerializer(new PromptOutputAuditor))->serialize($draft, $input);
        $this->assertSame(2, substr_count($rendered['content'], 'LINE: “'.$spoken.'”'));
        $this->assertStringContainsString('CANONICAL IDENTITY CAST-17: '.self::DEFINITION, $rendered['content']);
    }

    public function test_a_corrupted_source_anchor_map_is_rejected_rather_than_used_to_reconstruct_identity(): void
    {
        [, $missing, $input] = $this->fixture();
        $input['_story_context']['anchors']['CAST-17'] = 'A summary or reconstruction.';
        $this->expectException(PromptGenerationException::class);
        $this->expectExceptionMessage('saved canonical identity ledger is inconsistent');
        (new PromptPlanSerializer(new PromptOutputAuditor))->serialize($missing, $input);
    }

    public function test_missing_approved_source_counts_are_not_treated_as_permission_to_duplicate_characters(): void
    {
        [, $missing, $input] = $this->fixture();
        $input['_story_context']['approved_cast'] = [];
        $this->expectException(PromptGenerationException::class);
        $this->expectExceptionMessage('saved canonical roster is inconsistent for CAST-17');
        (new PromptPlanSerializer(new PromptOutputAuditor))->serialize($missing, $input);
    }

    public function test_duplicate_provider_definitions_still_reject_even_if_both_equal_the_source(): void
    {
        [$root, , $input] = $this->fixture();
        $draft = str_replace('CANONICAL IDENTITY CAST-17: '.self::DEFINITION,
            'CANONICAL IDENTITY CAST-17: '.self::DEFINITION."\nCANONICAL IDENTITY CAST-17: ".self::DEFINITION, $root);
        $this->expectException(PromptGenerationException::class);
        $this->expectExceptionMessage('ambiguous canonical identity');
        (new PromptPlanSerializer(new PromptOutputAuditor))->serialize($draft, $input);
    }

    public function test_an_explicit_empty_definition_is_not_silently_overwritten(): void
    {
        [$root, , $input] = $this->fixture();
        $draft = str_replace('CANONICAL IDENTITY CAST-17: '.self::DEFINITION, 'CANONICAL IDENTITY CAST-17:', $root);
        $this->expectException(PromptGenerationException::class);
        $this->expectExceptionMessage('redefined canonical identity CAST-17');
        (new PromptPlanSerializer(new PromptOutputAuditor))->serialize($draft, $input);
    }

    public function test_final_gate_rejects_a_missing_definition_even_when_the_roster_line_was_also_dropped(): void
    {
        [, $missing, $input] = $this->fixture();
        $draft = str_replace("CANONICAL CHARACTER ROSTER: CAST-17 ×1\n", '', $missing);
        $this->expectException(PromptGenerationException::class);
        $this->expectExceptionMessage('Declared character CAST-17 has no literal CANONICAL IDENTITY definition');
        (new StoryContinuationService(new PromptOutputAuditor))->assertIdentities($draft, $input);
    }

    public function test_shot_level_declarations_also_require_a_literal_definition_in_the_final_package(): void
    {
        [, $missing, $input] = $this->fixture();
        $draft = str_replace(["CANONICAL CHARACTER ROSTER: CAST-17 ×1\n", 'VISIBLE CHARACTER CAST: Neri (CAST-17) ×1', 'cast=Neri CAST-17 x1'],
            ['', 'VISIBLE CHARACTER CAST: None', 'cast=None'], $missing);
        $draft = PromptQaCases::edit($draft, 1, 'PRIMARY IDEA:', "SHOT 1 VISIBLE CHARACTER CAST: Neri (CAST-17) ×1\nPRIMARY IDEA:");
        $stories = new StoryContinuationService(new PromptOutputAuditor);
        $this->expectException(PromptGenerationException::class);
        $this->expectExceptionMessage('Declared character CAST-17 has no literal CANONICAL IDENTITY definition');
        $stories->assertIdentities($draft, $input);
    }

    public function test_a_source_with_no_identities_does_not_authorize_new_unplanned_characters(): void
    {
        $root = preg_replace('/^(?:CANONICAL IDENTITY OBJ-01:|VISIBLE SUBJECT IDS:|SUBJECT IDENTITY LOCK:).*\R/mu', '',
            PromptQaCases::package()).StoryContinuationCases::footer();
        $brief = [...PromptQaCases::input(), 'content_form' => 'long'];
        $stories = new StoryContinuationService(new PromptOutputAuditor);
        $input = $stories->prepare($root, $brief, $stories->initial($root, $brief), 'open', 'continue', '', 'set');
        $this->assertSame([], $input['_story_context']['anchors']);
        $draft = str_replace('cast=Mira CHAR-01 x1', 'cast=None', PromptQaCases::characters($root));
        $this->expectException(PromptGenerationException::class);
        $this->expectExceptionMessage('introduced a new recurring subject without permission');
        $stories->accept($draft, $input);
    }
}
