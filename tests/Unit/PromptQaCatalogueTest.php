<?php

namespace Tests\Unit;

use App\Services\PromptOutputAuditor;
use App\Services\PromptQaCatalogue;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\PromptQaCases;
use Tests\TestCase;

class PromptQaCatalogueTest extends TestCase
{
    public static function cases(): iterable
    {
        foreach (['wheel', 'seed tray', 'glass carafe'] as $topic) {
            foreach (PromptQaCases::all($topic) as $code => $case) yield $topic.' / '.$code => [$code, $case];
        }
    }

    #[DataProvider('cases')]
    public function test_every_catalogued_check_has_a_valid_and_malformed_regression(string $code, array $case): void
    {
        $auditor = new PromptOutputAuditor;
        $this->assertNotContains($code, array_column($auditor->audit($case['valid'], $case['valid_input'])['issues'], 'code'));
        $this->assertContains($code, array_column($auditor->audit($case['invalid'], $case['invalid_input'])['issues'], 'code'));
    }

    public function test_catalogue_and_regressions_cover_all_actual_auditor_codes(): void
    {
        $source = file_get_contents(app_path('Services/PromptOutputAuditor.php'));
        preg_match_all('/\$this->addIssue\(\s*\$issues,\s*\x27([^\x27]+)\x27/', $source, $matches);
        $codes = array_values(array_unique([...$matches[1], 'prompt_structure_missing'])); sort($codes);
        $catalogue = PromptQaCatalogue::all(); $listed = array_keys($catalogue); sort($listed);
        $cases = array_keys(PromptQaCases::all()); sort($cases);
        $this->assertSame($codes, $listed);
        $this->assertSame($listed, $cases);
        foreach ($catalogue as $code => $entry) {
            foreach (['category', 'kind', 'defect_condition', 'earliest_stage', 'invariant', 'independent_check', 'regression_case'] as $key) {
                $this->assertNotEmpty($entry[$key], $code.' / '.$key);
            }
        }
    }

    public function test_a_complete_consistent_character_free_and_character_led_package_passes_exact_output_qa(): void
    {
        $auditor = new PromptOutputAuditor;
        foreach ([PromptQaCases::package(), PromptQaCases::characters(PromptQaCases::package('seed tray'))] as $package) {
            $review = $auditor->audit($package, PromptQaCases::input());
            $this->assertSame('passed', $review['status']);
            $this->assertSame([], $review['issues']);
            $this->assertSame('human_review', array_values(array_filter($review['checks'], fn (array $c): bool => $c['id'] === 'rendered_video'))[0]['status']);
        }
    }

    public function test_ambiguous_or_contextual_findings_are_not_claimed_to_prove_physical_failure(): void
    {
        $case = PromptQaCases::all()['identity_anchor_unverified'];
        $review = (new PromptOutputAuditor)->audit($case['invalid'], $case['invalid_input']);
        $issue = array_values(array_filter($review['issues'], fn (array $i): bool => $i['code'] === 'identity_anchor_unverified'))[0];
        $this->assertSame('review', $issue['kind']);
        $this->assertGreaterThan(0, $review['finding_counts']['context_reviews']);
    }
    public function test_server_recognizes_extension_headings_and_does_not_audit_text_outside_the_copy_fence(): void
    {
        $auditor = new PromptOutputAuditor;
        foreach (['COPY CLIP 1 — VIDEO PROMPT', 'SECTION 8 — CLIP 1 — COPY-AND-PASTE VIDEO PROMPT'] as $heading) {
            $text = str_replace('COPY CLIP 1 PROMPT', $heading, PromptQaCases::package());
            $this->assertCount(2, $auditor->extractClips($text, PromptQaCases::input()));
            $this->assertSame([], $auditor->audit($text, PromptQaCases::input())['issues']);
        }
        $text = str_replace('COPY CLIP 1 PROMPT', 'COPY EXTENSION VIDEO PROMPT', PromptQaCases::clip(1));
        $text .= "Reviewer comment: not part of the copy block.\nCLIP DURATION: 19 seconds";
        $clips = $auditor->extractClips($text, [...PromptQaCases::input(), 'mode' => 'extend']);
        $this->assertSame('6 seconds', $auditor->fieldValue($clips[0]['text'], 'CLIP DURATION'));
        $this->assertStringNotContainsString('Reviewer comment', $clips[0]['text']);
    }

    public function test_absent_explicit_identity_evidence_is_not_a_passed_identity_check(): void
    {
        $text = preg_replace('/^(?:CANONICAL CHARACTER ROSTER|CANONICAL IDENTITY OBJ-01):.*\R/m', '', PromptQaCases::package());
        $checks = (new PromptOutputAuditor)->audit($text, PromptQaCases::input())['checks'];
        $this->assertSame('not_run', array_values(array_filter($checks, fn (array $c): bool => $c['id'] === 'identity'))[0]['status']);
    }

}
