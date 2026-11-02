<?php

namespace Tests\Unit;

use App\Services\PromptGenerationException;
use App\Services\PromptOutputAuditor;
use App\Services\PromptPlanSerializer;
use App\Services\PromptQaEvidence;
use App\Services\PromptGenerationService;
use App\Services\PromptRepairService;
use App\Services\StoryContinuationService;
use Tests\Support\StoryContinuationCases;
use Tests\Support\PromptQaCases;
use Tests\TestCase;

class PromptPlanSerializerTest extends TestCase
{

    public function test_single_canonical_sources_compile_to_a_complete_valid_literal_package_without_changing_words(): void
    {
        $auditor = new PromptOutputAuditor; $serializer = new PromptPlanSerializer($auditor);
        $rendered = $serializer->serialize(PromptQaCases::template(), PromptQaCases::input());
        $this->assertGreaterThan(0, $rendered['expanded']);
        $this->assertSame(PromptQaCases::characters(PromptQaCases::package()), $rendered['content']);
        $this->assertSame([], $auditor->audit($rendered['content'], PromptQaCases::input())['issues']);
        $this->assertSame($rendered['content'], $serializer->serialize($rendered['content'], PromptQaCases::input())['content']);
    }

    public function test_legacy_literal_output_is_byte_preserved_and_spoken_token_like_words_are_not_interpolated(): void
    {
        $serializer = new PromptPlanSerializer(new PromptOutputAuditor);
        $text = str_replace('It keeps turning.', 'Literal {{VEO_TOTAL_SECONDS}} spoken text.', PromptQaCases::package());
        $this->assertSame($text, $serializer->serialize($text, PromptQaCases::input())['content']);
    }

    public function test_invalid_missing_nested_or_out_of_scope_sources_fail_closed(): void
    {
        $serializer = new PromptPlanSerializer(new PromptOutputAuditor);
        foreach (['{{VEO_UNKNOWN}}', '{{VEO_CLIP_SECONDS:99}}', '{{VEO_PREVIOUS_END_STATE}}', '{{VEO_IDENTITY:CHAR-99}}'] as $token) {
            try {
                $serializer->serialize('Title: '.$token."\n".PromptQaCases::package(), PromptQaCases::input());
                $this->fail('An invalid reserved reference must never reach a user as a finished template.');
            } catch (PromptGenerationException $exception) {
                $this->assertStringContainsString('No incomplete prompt template was accepted', $exception->getMessage());
            }
        }
    }

    public function test_source_opening_references_are_scoped_to_the_first_clips_matching_fields_only(): void
    {
        $auditor = new PromptOutputAuditor;
        $stories = new StoryContinuationService($auditor);
        $brief = [...PromptQaCases::input(), 'content_form' => 'long'];
        $root = PromptQaCases::package().StoryContinuationCases::footer();
        $input = $stories->prepare($root, $brief, $stories->initial($root, $brief), 'open', 'continue', '', 'set');
        $serializer = new PromptPlanSerializer($auditor);
        $template = PromptQaCases::edit($root, 1, 'OPENING CONTINUITY STATE: '.PromptQaCases::state(), 'OPENING CONTINUITY STATE: {{VEO_SOURCE_END_STATE}}');
        $template = PromptQaCases::edit($template, 1, 'OPENING PRESENTATION STATE: camera=static; lighting=daylight; audio=quiet', 'OPENING PRESENTATION STATE: {{VEO_SOURCE_END_PRESENTATION}}');
        $compiled = $serializer->serialize($template, $input);
        $this->assertSame($root, $compiled['content']);
        $this->assertSame(2, $compiled['expanded']);
        $stories->assertOpening($compiled['content'], $input);
        foreach ([
            'Note: {{VEO_SOURCE_END_STATE}}',
            PromptQaCases::edit($root, 2, 'OPENING CONTINUITY STATE: '.PromptQaCases::state(), 'OPENING CONTINUITY STATE: {{VEO_SOURCE_END_STATE}}'),
            PromptQaCases::edit($root, 1, 'PRIMARY IDEA: Controlled motion.', 'PRIMARY IDEA: {{VEO_SOURCE_END_PRESENTATION}}'),
            str_replace('{{VEO_SOURCE_END_STATE}}', '{{VEO_SOURCE_END_PRESENTATION}}', $template),
        ] as $invalid) {
            try { $serializer->serialize($invalid, $input); $this->fail('Out-of-scope source reference accepted.'); }
            catch (PromptGenerationException $exception) { $this->assertStringContainsString('unresolved or out-of-scope', $exception->getMessage()); }
        }
        foreach ([$brief, [...$input, '_story_context' => [...$input['_story_context'], 'batch_state' => []]],
            [...$input, '_story_context' => [...$input['_story_context'], 'ending_state' => 'Reconstructed from summary']]] as $invalidInput) {
            try { $serializer->serialize($template, $invalidInput); $this->fail('Missing/inconsistent snapshot accepted.'); }
            catch (PromptGenerationException) { $this->addToAssertionCount(1); }
        }
        $wrong = PromptQaCases::edit($root, 1, 'OPENING CONTINUITY STATE: '.PromptQaCases::state(), 'OPENING CONTINUITY STATE: an incompatible state');
        $this->assertSame($wrong, $serializer->serialize($wrong, $input)['content']); // Never fix incompatible literals by overwriting them.
        $spoken = str_replace('It keeps turning.', 'Literal {{VEO_SOURCE_END_STATE}} spoken text.', $root);
        $this->assertSame($spoken, $serializer->serialize($spoken, $input)['content']); // Spoken words are never template-expanded.

    }

    public function test_numeric_message_changes_do_not_misreport_an_unresolved_issue_as_new_or_fixed(): void
    {
        $a = ['code' => 'speech_overrun', 'clip' => 1, 'message' => 'Clip 1 speech row 2 has 14 words in 2 seconds.'];
        $b = [...$a, 'message' => 'Clip 1 speech row 2 has 14 words in 3 seconds.'];
        $this->assertSame(PromptQaEvidence::issueKey($a), PromptQaEvidence::issueKey($b));
        $this->assertSame([], PromptQaEvidence::introduced(['issues' => [$a]], ['issues' => [$b]]));
        $this->assertNotSame(PromptQaEvidence::issueKey($a), PromptQaEvidence::issueKey([...$b, 'message' => 'Clip 1 speech row 3 has 14 words in 3 seconds.']));
    }

    public function test_duplicate_source_metadata_cannot_be_silently_repaired_or_selected(): void
    {
        $case = PromptQaCases::all()['metadata_field_ambiguous'];
        $auditor = new PromptOutputAuditor;
        $this->assertNull($auditor->fieldValue($auditor->extractClips($case['invalid'], $case['invalid_input'])[0]['text'], 'CLIP DURATION'));
        $result = (new PromptRepairService($auditor, new PromptGenerationService))->repair(null, $case['invalid'], $case['invalid_input']);
        $this->assertSame($case['invalid'], $result['content']);
        $this->assertFalse($result['repair_report']['applied']);
        $this->assertSame([], $result['repair_report']['fixed']);
    }
}
