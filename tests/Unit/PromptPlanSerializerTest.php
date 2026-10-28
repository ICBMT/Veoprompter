<?php

namespace Tests\Unit;

use App\Services\PromptGenerationException;
use App\Services\PromptOutputAuditor;
use App\Services\PromptPlanSerializer;
use App\Services\PromptQaEvidence;
use App\Services\PromptGenerationService;
use App\Services\PromptRepairService;
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
