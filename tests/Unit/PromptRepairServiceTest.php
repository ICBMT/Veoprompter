<?php

namespace Tests\Unit;

use App\Models\AiSetting;
use App\Services\PromptGenerationService;
use App\Services\PromptOutputAuditor;
use App\Services\PromptRepairService;
use Mockery;
use Tests\TestCase;

class PromptRepairServiceTest extends TestCase
{
    private PromptOutputAuditor $auditor;

    protected function setUp(): void
    {
        parent::setUp();
        $this->auditor = new PromptOutputAuditor;
    }

    public function test_reconstructed_six_clip_38_second_glass_bottle_case_repairs_speech_handoffs_and_all_runtime_views(): void
    {
        [$content, $input] = $this->reconstructedSixClipGlassBottleFixture();
        $before = $this->auditor->audit($content, $input);
        $beforePlan = $this->auditor->canonicalProjectPlan($content, $input);
        $beforeCodes = array_column($before['issues'], 'code');

        $this->assertSame(38.0, $beforePlan->totalRuntimeSeconds());
        $this->assertSame(6, $beforePlan->clipCount());
        $this->assertSame([1, 2, 3, 4, 5, 6], $beforePlan->clipOrder());
        $this->assertSame(5.0, $beforePlan->clips[0]['duration_seconds']);
        $this->assertSame(0.2, $beforePlan->clips[0]['speech_timing_policy']['opening_visual_beat_seconds']);
        $this->assertGreaterThan(2.3, $beforePlan->clips[0]['speech_rows'][0]['minimum_seconds']);
        $this->assertSame($input, $beforePlan->savedSettings);
        $this->assertCount(1, $beforePlan->overviewRuntimes);
        $this->assertSame(38.0, $beforePlan->overviewRuntimes[0]['seconds']);
        $this->assertNotContains('overview_total_runtime_missing', $beforeCodes);
        $this->assertSame(12, count(array_filter($beforeCodes, static fn (string $code): bool => $code === 'speech_overrun')));
        $this->assertSame(5, count(array_filter($beforeCodes, static fn (string $code): bool => $code === 'continuity_state_mismatch')));
        $this->assertContains('continuity_audit_overclaim', $beforeCodes);
        $this->assertContains('speech_pacing_overclaim', $beforeCodes);
        $this->assertContains('speech_opening_visual_beat_missing', $beforeCodes);
        $this->assertContains('speech_leaves_no_visual_tail', $beforeCodes);

        $settings = new AiSetting;
        $settings->provider = 'gemini';
        $settings->model = 'gemini-2.5-flash';
        $settings->api_key = 'test-provider-key';
        $generation = Mockery::mock(PromptGenerationService::class);
        $generation->shouldReceive('requestTargetedRepair')
            ->once()
            ->with($settings, Mockery::on(static fn (string $brief): bool => str_contains($brief, 'PAIR 5 — HANDOFF TYPE: A')
                && str_contains($brief, 'CURRENT PRIOR ENDING CONTINUITY STATE:')
                && str_contains($brief, 'CURRENT NEXT OPENING CONTINUITY STATE:')))
            ->andReturn(implode("\n", array_map(
                static fn (int $pair): string => 'PAIR '.$pair.' PHYSICAL STATE: PRIOR_ENDING',
                range(1, 5),
            )));

        $result = (new PromptRepairService($this->auditor, $generation))->repair($settings, $content, $input);
        $afterPlan = $this->auditor->canonicalProjectPlan($result['content'], $input);
        $afterReview = $this->auditor->audit($result['content'], $input);

        $this->assertTrue($result['repair_report']['applied']);
        $this->assertSame('passed', $afterReview['status']);
        $this->assertSame([], $afterReview['issues']);
        $this->assertSame(39.0, $afterPlan->totalRuntimeSeconds());
        $this->assertSame(39.0, $afterPlan->overviewRuntimes[0]['seconds']);
        $this->assertCount(6, $afterPlan->overviewDurations);
        $this->assertCount(6, $afterPlan->clipPlanDurations);
        $this->assertSame(39.0, array_sum(array_column($afterPlan->clipPlanDurations, 'seconds')));
        $this->assertSame('CLIP DURATION: 6 seconds', $this->auditor->fieldValue($result['content'], 'CLIP DURATION'));
        $this->assertStringContainsString('- Clip 1 — 6 seconds', $result['content']);
        $this->assertStringContainsString('Exact duration: 6 seconds', $result['content']);
        $this->assertStringContainsString('Estimated overall running time: 00:39', $result['content']);
        $this->assertStringContainsString('LINE: “The glass bottle is glowing.”', $result['content']);
        $this->assertStringContainsString('LINE: “The bottle remains clear and smooth.”', $result['content']);
        $this->assertContains('continuity_state_mismatch', array_column($result['repair_report']['fixed'], 'code'));
        $this->assertContains('speech_overrun', array_column($result['repair_report']['fixed'], 'code'));
        $this->assertContains('speech_leaves_no_visual_tail', array_column($result['repair_report']['fixed'], 'code'));
        $this->assertSame([], $result['repair_report']['new_issues']);
        $this->assertSame([], $result['repair_report']['unresolved']);
    }

    public function test_same_rejected_no_progress_signature_is_blocked_but_retryable_provider_failures_are_not(): void
    {
        $content = 'candidate prompt';
        $brief = ['clip_count' => 6];
        $review = ['issues' => [[
            'code' => 'speech_overrun',
            'category' => 'narration',
            'clip' => 1,
            'message' => 'Clip 1 speech row 1 exceeds the available duration.',
        ]]];
        $signature = PromptRepairService::repairSignature($content, $brief, $review);
        $history = [[
            'action' => 'repair',
            'status' => 'completed',
            'repair_signature' => $signature,
            'report' => ['applied' => false, 'retryable' => false],
        ]];

        $this->assertTrue(PromptRepairService::hasNonRetryableNoProgress($history, $signature));
        $history[0]['report']['retryable'] = true;
        $this->assertFalse(PromptRepairService::hasNonRetryableNoProgress($history, $signature));
        $this->assertFalse(PromptRepairService::hasNonRetryableNoProgress($history, PromptRepairService::repairSignature('changed prompt', $brief, $review)));
    }

    public function test_equivalent_continuity_maps_are_reconciled_without_changing_scene_or_handoff(): void
    {
        $previous = 'cast=None; object=glass vial on bench; level=half full; environment=lab';
        $reordered = 'environment=lab; level=half full; object=glass vial on bench; cast=None';
        $content = $this->clip(1, 'INITIAL CLIP — NO PREVIOUS HANDOFF', 'TYPE A — DIRECT CONTINUATION', $previous, $previous)
            ."\n".$this->clip(2, 'TYPE A — DIRECT CONTINUATION', 'FINAL — NO NEXT HANDOFF', $reordered, $reordered);
        $generation = Mockery::mock(PromptGenerationService::class);
        $generation->shouldNotReceive('requestTargetedRepair');
        $service = new PromptRepairService($this->auditor, $generation);

        $before = $this->auditor->audit($content, $this->input(['clip_count' => 2]));
        $result = $service->repair(null, $content, $this->input(['clip_count' => 2]));

        $this->assertContains('continuity_state_mismatch', array_column($before['issues'], 'code'));
        $this->assertStringContainsString('OPENING CONTINUITY STATE: '.$previous, $result['content']);
        $this->assertSame('passed', $result['quality_review']['status']);
        $this->assertSame(['continuity_state_mismatch'], array_column($result['repair_report']['fixed'], 'code'));
        $this->assertTrue($result['repair_report']['applied']);
        $this->assertSame([], $result['repair_report']['new_issues']);
        $this->assertStringContainsString('OUTGOING HANDOFF TYPE: TYPE A — DIRECT CONTINUATION', $result['content']);
    }

    public function test_type_a_presentation_maps_are_matched_deterministically(): void
    {
        $state = 'cast=None; object=single seed tray; stage=seeds planted; environment=greenhouse';
        $priorPresentation = 'camera=static wide; lighting=soft morning; audio=greenhouse ambience';
        $reorderedPresentation = 'audio=greenhouse ambience; camera=static wide; lighting=soft morning';
        $content = $this->clip(1, 'INITIAL CLIP — NO PREVIOUS HANDOFF', 'TYPE A — DIRECT CONTINUATION', $state, $state, $priorPresentation, $priorPresentation)
            ."\n".$this->clip(2, 'TYPE A — DIRECT CONTINUATION', 'FINAL — NO NEXT HANDOFF', $state, $state, $reorderedPresentation, $reorderedPresentation);
        $service = new PromptRepairService($this->auditor, Mockery::mock(PromptGenerationService::class));

        $before = $this->auditor->audit($content, $this->input(['clip_count' => 2]));
        $result = $service->repair(null, $content, $this->input(['clip_count' => 2]));

        $this->assertContains('presentation_state_mismatch', array_column($before['issues'], 'code'));
        $this->assertStringContainsString('OPENING PRESENTATION STATE: '.$priorPresentation, $result['content']);
        $this->assertNotContains('presentation_state_mismatch', array_column($result['quality_review']['issues'], 'code'));
        $this->assertContains('presentation_state_mismatch', array_column($result['repair_report']['fixed'], 'code'));
    }

    public function test_contextual_review_uses_only_relevant_state_and_action_fields_and_can_select_an_existing_value(): void
    {
        $prior = 'object=glass vial; level=half full; environment=lab';
        $next = 'object=glass vial; level=empty; environment=lab';
        $content = $this->clip(1, 'INITIAL CLIP — NO PREVIOUS HANDOFF', 'TYPE B — NATURAL ACTION TRANSITION', $prior, $prior)
            ."\nEXACT ENDING STATE: The glass vial is half full on the lab bench.\n"
            .$this->clip(2, 'TYPE B — NATURAL ACTION TRANSITION', 'FINAL — NO NEXT HANDOFF', $next, $prior, extra: "EXACT OPENING FRAME: The glass vial is empty on the lab bench.\nUNRELATED FULL PROMPT PROSE THAT MUST NOT BE SENT TO AI");
        $settings = new AiSetting;
        $settings->provider = 'gemini';
        $settings->model = 'gemini-2.5-flash';
        $settings->api_key = 'test-provider-key';
        $generation = Mockery::mock(PromptGenerationService::class);
        $generation->shouldReceive('requestTargetedRepair')
            ->once()
            ->with($settings, Mockery::on(function (string $brief): bool {
                return str_contains($brief, 'CURRENT PRIOR ENDING CONTINUITY STATE:')
                    && str_contains($brief, 'VISIBLE CAUSE → ACTION → RESULT:')
                    && ! str_contains($brief, 'UNRELATED FULL PROMPT PROSE');
            }))
            ->andReturn('PAIR 1 PHYSICAL STATE: prior_ending');
        $service = new PromptRepairService($this->auditor, $generation);

        $result = $service->repair($settings, $content, $this->input(['clip_count' => 2]));

        $this->assertStringContainsString('OPENING CONTINUITY STATE: '.$prior, $result['content']);
        $this->assertStringContainsString('EXACT OPENING FRAME: The glass vial is half full on the lab bench.', $result['content']);
        $this->assertStringContainsString('VISIBLE CAUSE → ACTION → RESULT: A clear cause produces one visible action and result.', $result['content']);
        $this->assertStringContainsString('LINE: “The bee carries pollen.”', $result['content']);
        $this->assertNotContains('continuity_state_mismatch', array_column($result['quality_review']['issues'], 'code'));
        $this->assertNotContains('continuity_prose_state_mismatch', array_column($result['quality_review']['issues'], 'code'));
        $this->assertContains('handoff_opening_prose_mismatch', array_column($result['repair_report']['fixed'], 'code'));
        $this->assertTrue($result['repair_report']['applied']);
    }

    public function test_unsafe_scene_change_is_left_for_manual_review_when_context_is_unavailable(): void
    {
        $prior = 'object=unformed steel panel; stage=raw; location=press table; environment=factory';
        $next = 'object=finished car body; stage=painted; location=assembly bay; environment=factory';
        $content = $this->clip(1, 'INITIAL CLIP — NO PREVIOUS HANDOFF', 'TYPE C — NARRATIVE / TEMPORAL TRANSITION', $prior, $prior)
            ."\n".$this->clip(2, 'TYPE C — NARRATIVE / TEMPORAL TRANSITION', 'FINAL — NO NEXT HANDOFF', $next, $next);
        $generation = Mockery::mock(PromptGenerationService::class);
        $generation->shouldNotReceive('requestTargetedRepair');
        $service = new PromptRepairService($this->auditor, $generation);

        $result = $service->repair(null, $content, $this->input(['clip_count' => 2]));

        $this->assertSame($content, $result['content']);
        $this->assertFalse($result['repair_report']['applied']);
        $this->assertContains('continuity_state_mismatch', array_column($result['repair_report']['unresolved'], 'code'));
        $this->assertSame([], $result['repair_report']['new_issues']);
        $this->assertStringContainsString('manual review', implode(' ', $result['repair_report']['notes']));
    }

    public function test_overlapping_speech_is_rescheduled_at_a_natural_rate_with_a_visual_tail_and_ai_duration_stays_at_ten_or_less(): void
    {
        $state = 'cast=None; object=bee over a flower; stage=pollen transfer; environment=garden';
        $presentation = 'camera=macro; lighting=daylight; audio=soft garden ambience';
        $rows = [
            '- 0.0–2.0s | VISIBLE ACTION: the bee gathers pollen | SPEAKER: Narrator | LINE: “The bee carries pollen.”',
            '- 1.5–2.5s | VISIBLE ACTION: the bee reaches another bloom | SPEAKER: Narrator | LINE: “It reaches another flower.”',
        ];
        $content = "## PROJECT OVERVIEW\n- Clip 1 — 4 seconds\n- Approximate total runtime: 4 seconds\n\n"
            ."## SECTION 7 — CLIP PLAN AND CONTINUITY MAP\n- Clip 1 — Gather pollen\n  - Exact duration: 4 seconds\n\n## SECTION 8 — COPY-AND-PASTE VIDEO PROMPTS\n"
            .$this->clip(1, 'INITIAL CLIP — NO PREVIOUS HANDOFF', 'FINAL — NO NEXT HANDOFF', $state, $state, $presentation, $presentation, duration: 4, speechRows: $rows);
        $service = new PromptRepairService($this->auditor, Mockery::mock(PromptGenerationService::class));

        $before = $this->auditor->audit($content, $this->input());
        $result = $service->repair(null, $content, $this->input());

        $this->assertContains('speech_rows_overlap', array_column($before['issues'], 'code'));
        $this->assertSame('CLIP DURATION: 5 seconds', $this->auditor->fieldValue($result['content'], 'CLIP DURATION'));
        $this->assertStringContainsString('- Clip 1 — 5 seconds', $result['content']);
        $this->assertStringContainsString('Exact duration: 5 seconds', $result['content']);
        $this->assertStringContainsString('- Approximate total runtime: 5 seconds', $result['content']);
        $this->assertStringContainsString('- 0.2–2.2s | VISIBLE ACTION: the bee gathers pollen | SPEAKER: Narrator | LINE: “The bee carries pollen.”', $result['content']);
        $this->assertStringContainsString('- 2.4–4.4s | VISIBLE ACTION: the bee reaches another bloom | SPEAKER: Narrator | LINE: “It reaches another flower.”', $result['content']);
        $this->assertSame('passed', $result['quality_review']['status']);
        $this->assertSame([], $result['repair_report']['new_issues']);
        $this->assertTrue($result['repair_report']['applied']);
    }

    public function test_project_duration_range_prevents_dialogue_from_forcing_an_over_limit_expansion(): void
    {
        $state = 'cast=None; object=turning gear; stage=rotating; environment=workshop';
        $presentation = 'camera=close-up; lighting=neutral; audio=machine hum';
        $dialogue = '“One two three four five six seven eight nine ten eleven twelve thirteen fourteen fifteen sixteen seventeen eighteen nineteen twenty twenty-one twenty-two twenty-three.”';
        $row = '- 0.0–4.0s | VISIBLE ACTION: the gear rotates | SPEAKER: Narrator | LINE: '.$dialogue;
        $content = "## PROJECT OVERVIEW\nProject clip duration range: 3–7 seconds.\n- Clip 1 — 4 seconds\n- Approximate total runtime: 4 seconds\n\n"
            .$this->clip(1, 'INITIAL CLIP — NO PREVIOUS HANDOFF', 'FINAL — NO NEXT HANDOFF', $state, $state, $presentation, $presentation, duration: 4, speechRows: [$row]);
        $service = new PromptRepairService($this->auditor, Mockery::mock(PromptGenerationService::class));

        $result = $service->repair(null, $content, $this->input());

        $this->assertSame($content, $result['content']);
        $this->assertStringContainsString($dialogue, $result['content']);
        $this->assertContains('speech_overrun', array_column($result['repair_report']['unresolved'], 'code'));
        $this->assertNotContains('speech_overrun', array_column($result['repair_report']['fixed'], 'code'));
        $this->assertNotContains('declared_duration_constraint_violation', array_column($result['quality_review']['issues'], 'code'));
        $this->assertFalse($result['repair_report']['applied']);
    }

    public function test_project_runtime_maximum_counts_earlier_clip_expansions_in_the_same_repair(): void
    {
        $state = 'cast=None; object=gear; stage=rotating; environment=workshop';
        $presentation = 'camera=macro; lighting=neutral; audio=quiet room tone';
        $rows = [
            '- 0.0–2.0s | VISIBLE ACTION: the gear turns | SPEAKER: Narrator | LINE: “The gear turns slowly.”',
            '- 1.5–2.5s | VISIBLE ACTION: the gear stops | SPEAKER: Narrator | LINE: “It comes to rest.”',
        ];
        $content = $this->clip(1, 'INITIAL CLIP — NO PREVIOUS HANDOFF', 'TYPE A — DIRECT CONTINUATION', $state, $state, $presentation, $presentation, duration: 4, speechRows: $rows)
            ."\n".$this->clip(2, 'TYPE A — DIRECT CONTINUATION', 'FINAL — NO NEXT HANDOFF', $state, $state, $presentation, $presentation, duration: 4, speechRows: $rows);
        $input = $this->input(['clip_count' => 2, 'project_runtime_max_seconds' => 9]);
        $service = new PromptRepairService($this->auditor, Mockery::mock(PromptGenerationService::class));

        $result = $service->repair(null, $content, $input);
        $plan = $this->auditor->canonicalProjectPlan($result['content'], $input);

        $this->assertSame(9.0, $plan->totalRuntimeSeconds());
        $this->assertSame([1 => 5.0, 2 => 4.0], $plan->clipDurations());
        $this->assertTrue($result['repair_report']['applied']);
        $this->assertSame([], $result['repair_report']['new_issues']);
        $this->assertStringContainsString('would exceed the saved project-wide maximum of 9 seconds', implode(' ', $result['repair_report']['informational']));
    }

    public function test_project_runtime_minimum_prevents_a_clip_shrink_that_would_violate_it(): void
    {
        $state = 'cast=None; object=small brass gear; stage=polished; environment=workshop';
        $presentation = 'camera=macro; lighting=neutral; audio=quiet room tone';
        $row = '- 0.2–2.2s | VISIBLE ACTION: the gear turns slowly | SPEAKER: Narrator | LINE: “The gear turns.”';
        $content = $this->clip(1, 'INITIAL CLIP — NO PREVIOUS HANDOFF', 'TYPE A — DIRECT CONTINUATION', $state, $state, $presentation, $presentation, duration: 12, speechRows: [$row])
            ."\n".$this->clip(2, 'TYPE A — DIRECT CONTINUATION', 'TYPE A — DIRECT CONTINUATION', $state, $state, $presentation, $presentation, duration: 8, speechRows: [$row])
            ."\n".$this->clip(3, 'TYPE A — DIRECT CONTINUATION', 'FINAL — NO NEXT HANDOFF', $state, $state, $presentation, $presentation, duration: 8, speechRows: [$row]);
        $input = $this->input(['clip_count' => 3, 'project_runtime_min_seconds' => 27]);
        $service = new PromptRepairService($this->auditor, Mockery::mock(PromptGenerationService::class));

        $result = $service->repair(null, $content, $input);

        $this->assertSame($content, $result['content']);
        $this->assertContains('ai_duration_over_limit', array_column($result['repair_report']['unresolved'], 'code'));
        $this->assertFalse($result['repair_report']['applied']);
        $this->assertStringContainsString('would fall below the saved project-wide minimum of 27 seconds', implode(' ', $result['repair_report']['informational']));
    }

    public function test_safe_duration_repair_clamps_to_project_range_and_synchronizes_every_timing_reference(): void
    {
        $state = 'cast=None; object=small brass gear; stage=polished; environment=workshop';
        $presentation = 'camera=macro; lighting=neutral; audio=quiet room tone';
        $silent = $this->clip(1, 'INITIAL CLIP — NO PREVIOUS HANDOFF', 'FINAL — NO NEXT HANDOFF', $state, $state, $presentation, $presentation, duration: 8, speechRows: []);
        $silent = str_replace('SPEECH/VISUAL MAP:', 'SPEECH/VISUAL MAP: None — spoken audio is off', $silent);
        $content = "## PROJECT OVERVIEW\nAll clips must stay between 3 and 7 seconds.\n- Clip 1 — 8 seconds\n- Approximate total runtime: 8 seconds\n\n"
            ."## SECTION 7 — CLIP PLAN AND CONTINUITY MAP\n- Clip 1 — Inspect the polished gear\n  - Exact duration: 8 seconds\n\n## SECTION 8 — COPY-AND-PASTE VIDEO PROMPTS\n"
            .$silent;
        $service = new PromptRepairService($this->auditor, Mockery::mock(PromptGenerationService::class));

        $before = $this->auditor->audit($content, $this->input(['narration' => '0']));
        $result = $service->repair(null, $content, $this->input(['narration' => '0']));

        $this->assertContains('declared_duration_constraint_violation', array_column($before['issues'], 'code'));
        $this->assertSame('CLIP DURATION: 7 seconds', $this->auditor->fieldValue($result['content'], 'CLIP DURATION'));
        $this->assertStringContainsString('- Clip 1 — 7 seconds', $result['content']);
        $this->assertStringContainsString('Exact duration: 7 seconds', $result['content']);
        $this->assertStringContainsString('- Approximate total runtime: 7 seconds', $result['content']);
        $this->assertSame('passed', $result['quality_review']['status']);
        $this->assertContains('declared_duration_constraint_violation', array_column($result['repair_report']['fixed'], 'code'));
        $this->assertNotContains('declared_duration_constraint_violation', array_column($result['repair_report']['unresolved'], 'code'));
        $this->assertSame([], $result['repair_report']['new_issues']);
    }

    public function test_incomplete_clip_plan_blocks_duration_expansion_instead_of_leaving_a_stale_plan(): void
    {
        $state = 'cast=None; object=bee near a flower; stage=pollen transfer; environment=garden';
        $presentation = 'camera=macro; lighting=daylight; audio=soft garden ambience';
        $rows = [
            '- 0.0–2.0s | VISIBLE ACTION: the bee gathers pollen | SPEAKER: Narrator | LINE: “The bee carries pollen.”',
            '- 1.5–2.5s | VISIBLE ACTION: the bee reaches a bloom | SPEAKER: Narrator | LINE: “It reaches another flower.”',
        ];
        $content = "## SECTION 7 — CLIP PLAN AND CONTINUITY MAP\n- Clip 1 — Gather pollen\n  - Exact duration: to be determined\n\n## SECTION 8 — COPY-AND-PASTE VIDEO PROMPTS\n"
            .$this->clip(1, 'INITIAL CLIP — NO PREVIOUS HANDOFF', 'FINAL — NO NEXT HANDOFF', $state, $state, $presentation, $presentation, duration: 4, speechRows: $rows);
        $service = new PromptRepairService($this->auditor, Mockery::mock(PromptGenerationService::class));

        $result = $service->repair(null, $content, $this->input());

        $this->assertSame($content, $result['content']);
        $this->assertSame('CLIP DURATION: 4 seconds', $this->auditor->fieldValue($result['content'], 'CLIP DURATION'));
        $this->assertContains('clip_plan_duration_missing', array_column($result['repair_report']['unresolved'], 'code'));
        $this->assertContains('speech_rows_overlap', array_column($result['repair_report']['unresolved'], 'code'));
        $this->assertNotContains('speech_rows_overlap', array_column($result['repair_report']['fixed'], 'code'));
    }

    public function test_final_audit_claims_are_rephrased_from_qa_evidence_and_remaining_findings_stay_unresolved(): void
    {
        $state = 'cast=None; object=turning gear; stage=rotating; environment=workshop';
        $dialogue = '“One two three four five six seven eight nine ten eleven twelve thirteen fourteen fifteen sixteen seventeen eighteen nineteen twenty twenty-one twenty-two twenty-three.”';
        $row = '- 0.0–1.0s | VISIBLE ACTION: the gear turns | SPEAKER: Narrator | LINE: '.$dialogue;
        $clip = $this->clip(1, 'INITIAL CLIP — NO PREVIOUS HANDOFF', 'FINAL — NO NEXT HANDOFF', $state, $state, duration: 4, speechRows: [$row]);
        $content = $clip."\n\n### FINAL CONTINUITY AUDIT\n- All clip durations are synchronized, and every spoken line respects natural pacing.";
        $service = new PromptRepairService($this->auditor, Mockery::mock(PromptGenerationService::class));

        $result = $service->repair(null, $content, $this->input());
        $fixedCodes = array_column($result['repair_report']['fixed'], 'code');
        $unresolvedCodes = array_column($result['repair_report']['unresolved'], 'code');

        $this->assertStringContainsString('Timing claims reflect parsed QA only', $result['content']);
        $this->assertStringContainsString('actual delivery and same-clip visual alignment still require human review', $result['content']);
        $this->assertContains('speech_overrun', $unresolvedCodes);
        $this->assertNotContains('speech_overrun', $fixedCodes);
        $this->assertContains('timing_audit_overclaim', $fixedCodes);
        $this->assertContains('speech_pacing_overclaim', $fixedCodes);
        $this->assertEmpty(array_intersect($fixedCodes, $unresolvedCodes));
        $this->assertTrue($result['repair_report']['applied']);
    }

    public function test_new_issue_in_a_rejected_repair_candidate_is_not_reported_as_fixed_or_saved(): void
    {
        $previous = 'cast=None; object=glass vial; level=half full; environment=lab';
        $reordered = 'environment=lab; level=half full; object=glass vial; cast=None';
        $content = $this->clip(1, 'INITIAL CLIP — NO PREVIOUS HANDOFF', 'TYPE A — DIRECT CONTINUATION', $previous, $previous)
            ."\n".$this->clip(2, 'TYPE A — DIRECT CONTINUATION', 'FINAL — NO NEXT HANDOFF', $reordered, $reordered);
        $input = $this->input(['clip_count' => 2]);
        $baseAuditor = new PromptOutputAuditor;
        $originalReview = $baseAuditor->audit($content, $input);
        $auditor = new class($content, $originalReview) extends PromptOutputAuditor {
            public function __construct(private string $originalContent, private array $originalReview) {}

            public function audit(string $content, array $input): array
            {
                if ($content === $this->originalContent) {
                    return $this->originalReview;
                }
                $review = parent::audit($content, $input);
                $review['issues'][] = [
                    'code' => 'test_introduced_duration_inconsistency',
                    'category' => 'duration',
                    'clip' => 1,
                    'message' => 'A synthetic regression issue introduced only in this candidate.',
                    'repairable' => false,
                ];
                $review['status'] = 'needs_review';

                return $review;
            }
        };
        $service = new PromptRepairService($auditor, Mockery::mock(PromptGenerationService::class));

        $result = $service->repair(null, $content, $input);

        $this->assertSame($content, $result['content']);
        $this->assertSame([], $result['repair_report']['fixed']);
        $this->assertSame([], $result['repair_report']['new_issues']);
        $this->assertContains('continuity_state_mismatch', array_column($result['repair_report']['unresolved'], 'code'));
        $this->assertContains('test_introduced_duration_inconsistency', array_column($result['repair_report']['rejected_candidate_issues'], 'code'));
        $this->assertFalse($result['repair_report']['applied']);
    }

    public function test_type_b_handoff_rewrites_only_an_inaccurate_exact_copy_claim(): void
    {
        $prior = 'object=seed tray on greenhouse bench; stage=seeds planted; environment=greenhouse; motion=stationary';
        $next = 'environment=greenhouse; object=seed tray resting on the greenhouse bench; stage=seeds planted and ready for watering; motion=still';
        $first = $this->clip(1, 'INITIAL CLIP — NO PREVIOUS HANDOFF', 'TYPE B — NATURAL ACTION TRANSITION', $prior, $prior)
            ."\nEXACT ENDING STATE: The seed tray rests on the greenhouse bench, the seeds are planted, and the scene is still.";
        $second = $this->clip(2, 'TYPE B — NATURAL ACTION TRANSITION', 'FINAL — NO NEXT HANDOFF', $prior, $next)
            ."\nEXACT OPENING FRAME: The seed tray rests on the greenhouse bench with planted seeds; the frame is still."
            ."\nEXACT ENDING STATE: The planted seeds in the tray are ready for watering on the greenhouse bench."
            ."\n\n### FINAL CONTINUITY AUDIT\n- The Type B handoff is an exact state copy of the prior clip.";
        $second = str_replace(
            'VISIBLE CAUSE → ACTION → RESULT: A clear cause produces one visible action and result.',
            'VISIBLE CAUSE → ACTION → RESULT: A hand lifts the seed tray from the bench and sets it down, leaving the planted seeds ready for watering.',
            $second,
        );
        $content = $first."\n".$second;
        $service = new PromptRepairService($this->auditor, Mockery::mock(PromptGenerationService::class));

        $before = $this->auditor->audit($content, $this->input(['clip_count' => 2]));
        $result = $service->repair(null, $content, $this->input(['clip_count' => 2]));

        $this->assertSame('exact', $before['handoffs'][0]['continuity_relation']);
        $this->assertSame('compatible', $before['handoffs'][0]['overall_relation']);
        $this->assertFalse($before['handoffs'][0]['exact_copy']);
        $this->assertContains('continuity_audit_overclaim', array_column($before['issues'], 'code'));
        $this->assertStringContainsString('Handoffs with compatible, mismatched, or unverified states are not verbatim copies;', $result['content']);
        $this->assertSame('passed', $result['quality_review']['status']);
        $this->assertContains('continuity_audit_overclaim', array_column($result['repair_report']['fixed'], 'code'));
        $this->assertTrue($result['repair_report']['applied']);
    }

    public function test_successful_repair_keeps_clip_fields_overview_runtime_and_exact_state_prose_consistent(): void
    {
        $state = 'cast=None; object=glass vial on bench; level=half full; environment=lab';
        $reordered = 'environment=lab; level=half full; object=glass vial on bench; cast=None';
        $presentation = 'camera=macro; lighting=soft daylight; audio=quiet lab ambience';
        $rows = [
            '- 0.0–2.0s | VISIBLE ACTION: the bee gathers pollen | SPEAKER: Narrator | LINE: “The bee carries pollen.”',
            '- 1.5–2.5s | VISIBLE ACTION: the bee reaches another bloom | SPEAKER: Narrator | LINE: “It reaches another flower.”',
        ];
        $overview = "## PROJECT OVERVIEW\n- Clip 1 — 4 seconds\n- Clip 2 — 4 seconds\n- Approximate total runtime: 8 seconds\n\n";
        $content = $overview
            .$this->clip(1, 'INITIAL CLIP — NO PREVIOUS HANDOFF', 'TYPE A — DIRECT CONTINUATION', $state, $state, $presentation, $presentation, duration: 4, speechRows: $rows)
            ."\nEXACT ENDING STATE: The glass vial is stationary on the lab bench, half full.\n"
            .$this->clip(2, 'TYPE A — DIRECT CONTINUATION', 'FINAL — NO NEXT HANDOFF', $reordered, $reordered, $presentation, $presentation, duration: 4)
            ."\nEXACT OPENING FRAME: The glass vial is stationary on the lab bench, half full.";
        $service = new PromptRepairService($this->auditor, Mockery::mock(PromptGenerationService::class));

        $result = $service->repair(null, $content, $this->input(['clip_count' => 2]));
        $reviewed = $this->auditor->audit($result['content'], $this->input(['clip_count' => 2]));

        $this->assertSame('passed', $reviewed['status']);
        $this->assertSame([], $reviewed['issues']);
        $this->assertStringContainsString('CLIP DURATION: 5 seconds', $result['content']);
        $this->assertStringContainsString('- Clip 1 — 5 seconds', $result['content']);
        $this->assertStringContainsString('- Clip 2 — 4 seconds', $result['content']);
        $this->assertStringContainsString('- Approximate total runtime: 9 seconds', $result['content']);
        $this->assertStringContainsString('EXACT OPENING FRAME: The glass vial is stationary on the lab bench, half full.', $result['content']);
        $this->assertStringContainsString('It reaches another flower.', $result['content']);
        $this->assertTrue($result['repair_report']['applied']);
    }

    public function test_negative_and_beyond_clip_timestamps_are_compacted_inside_the_actual_clip_bounds(): void
    {
        $state = 'cast=None; object=seed; stage=planted; environment=soil tray';
        $presentation = 'camera=close-up; lighting=soft; audio=quiet room';
        $rows = [
            '- -0.5–1.5s | VISIBLE ACTION: a seed settles into soil | SPEAKER: Narrator | LINE: “The seed rests in soil.”',
        ];
        $negative = $this->clip(1, 'INITIAL CLIP — NO PREVIOUS HANDOFF', 'FINAL — NO NEXT HANDOFF', $state, $state, $presentation, $presentation, duration: 4, speechRows: $rows);
        $beyond = str_replace('- -0.5–1.5s', '- 3.0–5.0s', $negative);
        $service = new PromptRepairService($this->auditor, Mockery::mock(PromptGenerationService::class));

        $negativeBefore = $this->auditor->audit($negative, $this->input());
        $negativeResult = $service->repair(null, $negative, $this->input());
        $beyondBefore = $this->auditor->audit($beyond, $this->input());
        $beyondResult = $service->repair(null, $beyond, $this->input());

        $this->assertContains('speech_time_range_invalid', array_column($negativeBefore['issues'], 'code'));
        $this->assertContains('speech_exceeds_clip', array_column($beyondBefore['issues'], 'code'));
        $this->assertStringContainsString('- 0.2–2.6s | VISIBLE ACTION:', $negativeResult['content']);
        $this->assertStringContainsString('- 0.2–2.6s | VISIBLE ACTION:', $beyondResult['content']);
        $this->assertSame('passed', $negativeResult['quality_review']['status']);
        $this->assertSame('passed', $beyondResult['quality_review']['status']);
    }

    public function test_ai_selected_speech_can_expand_to_exactly_ten_seconds_but_manual_duration_is_never_changed(): void
    {
        $state = 'cast=None; object=glowing seed; stage=ready to plant; environment=greenhouse';
        $presentation = 'camera=static macro; lighting=warm; audio=quiet ambience';
        $dialogue = '“One two three four five six seven eight nine ten eleven twelve thirteen fourteen fifteen sixteen seventeen eighteen nineteen twenty.”';
        $row = '- 0.0–4.0s | VISIBLE ACTION: the seed glows steadily | SPEAKER: Narrator | LINE: '.$dialogue;
        $content = $this->clip(1, 'INITIAL CLIP — NO PREVIOUS HANDOFF', 'FINAL — NO NEXT HANDOFF', $state, $state, $presentation, $presentation, duration: 4, speechRows: [$row]);
        $service = new PromptRepairService($this->auditor, Mockery::mock(PromptGenerationService::class));

        $aiResult = $service->repair(null, $content, $this->input());
        $manualResult = $service->repair(null, $content, $this->input(['clip_duration' => 4]));

        $this->assertSame('CLIP DURATION: 10 seconds', $this->auditor->fieldValue($aiResult['content'], 'CLIP DURATION'));
        $this->assertSame('passed', $aiResult['quality_review']['status']);
        $this->assertStringContainsString($dialogue, $aiResult['content']);
        $this->assertSame($content, $manualResult['content']);
        $this->assertFalse($manualResult['repair_report']['applied']);
        $this->assertContains('speech_overrun', array_column($manualResult['repair_report']['unresolved'], 'code'));
    }

    public function test_dialogue_that_cannot_fit_is_preserved_exactly_and_left_unresolved(): void
    {
        $state = 'cast=None; object=spinning gear; stage=rotating; environment=workshop';
        $presentation = 'camera=close-up; lighting=neutral; audio=machine hum';
        $dialogue = '“One two three four five six seven eight nine ten eleven twelve thirteen fourteen fifteen sixteen seventeen eighteen nineteen twenty twenty-one twenty-two twenty-three.”';
        $row = '- 0.0–4.0s | VISIBLE ACTION: the gear rotates | SPEAKER: Narrator | LINE: '.$dialogue;
        $content = $this->clip(1, 'INITIAL CLIP — NO PREVIOUS HANDOFF', 'FINAL — NO NEXT HANDOFF', $state, $state, $presentation, $presentation, duration: 4, speechRows: [$row]);
        $service = new PromptRepairService($this->auditor, Mockery::mock(PromptGenerationService::class));

        $result = $service->repair(null, $content, $this->input());

        $this->assertSame($content, $result['content']);
        $this->assertStringContainsString($dialogue, $result['content']);
        $this->assertContains('speech_overrun', array_column($result['repair_report']['unresolved'], 'code'));
        $this->assertFalse($result['repair_report']['applied']);
    }

    /**
     * Reconstructed regression fixture: the original six-clip glass-bottle QA
     * transcript was not present in the repository, so this clearly labeled case
     * reproduces the reported 38-second parser, speech, handoff, and claim failures.
     *
     * @return array{0: string, 1: array<string, mixed>}
     */
    private function reconstructedSixClipGlassBottleFixture(): array
    {
        $durations = [5, 6, 7, 6, 7, 7];
        $startState = 'cast=None; object=glass bottle G-01; material=borosilicate glass; process=molten gather; location=furnace exit; motion=held on blowpipe';
        $endStates = [
            'cast=None; object=glass bottle G-01; material=borosilicate glass; process=molten gather transferred; location=blowpipe station; motion=held on blowpipe',
            'cast=None; object=glass bottle G-01; material=borosilicate glass; process=inflated parison; location=blow mold; motion=held by mold',
            'cast=None; object=glass bottle G-01; material=borosilicate glass; process=neck shaped; location=neck ring; motion=held by mold',
            'cast=None; object=glass bottle G-01; material=borosilicate glass; process=final shape formed; location=annealing lehr entrance; motion=conveyor moving east',
            'cast=None; object=glass bottle G-01; material=borosilicate glass; process=annealed and cooled; location=inspection table; motion=stationary',
            'cast=None; object=glass bottle G-01; material=borosilicate glass; process=inspected and accepted; location=packaging line; motion=conveyor moving east',
        ];
        $wrongOpening = 'cast=None; object=glass bottle G-01; material=borosilicate glass; process=broken fragments; location=scrap bin; motion=stationary';
        $presentation = 'camera=macro tracking; lighting=warm furnace glow; audio=quiet glassworks ambience';
        $overview = [
            '## SECTION 1 — PROJECT OVERVIEW',
            ...array_map(static fn (int $index, int $duration): string => '- Clip '.($index + 1).' — '.$duration.' seconds', array_keys($durations), $durations),
            '- Estimated overall running time: 00:38',
            '',
            '## SECTION 7 — CLIP PLAN AND CONTINUITY MAP',
        ];
        foreach ($durations as $index => $duration) {
            $overview[] = '- Clip '.($index + 1).' — Form one glass bottle stage';
            $overview[] = '  - Exact duration: '.$duration.' seconds';
        }
        $overview[] = '';
        $overview[] = '## SECTION 8 — COPY-AND-PASTE VIDEO PROMPTS';

        foreach ($durations as $index => $duration) {
            $number = $index + 1;
            $incoming = $index === 0 ? 'INITIAL CLIP — NO PREVIOUS HANDOFF' : 'TYPE A — DIRECT CONTINUATION';
            $outgoing = $index === count($durations) - 1 ? 'FINAL — NO NEXT HANDOFF' : 'TYPE A — DIRECT CONTINUATION';
            $speechRows = [
                '- 0.0–0.5s | VISIBLE ACTION: the glass bottle glows at the furnace exit | SPEAKER: Narrator | LINE: “The glass bottle is glowing.”',
                '- '.($duration - 1.0).'–'.($duration - 0.05).'s | VISIBLE ACTION: the bottle advances through this manufacturing stage | SPEAKER: Narrator | LINE: “The bottle remains clear and smooth.”',
            ];
            $overview[] = $this->clip(
                $number,
                $incoming,
                $outgoing,
                $index === 0 ? $startState : $wrongOpening,
                $endStates[$index],
                $presentation,
                $presentation,
                duration: $duration,
                speechRows: $speechRows,
            );
        }

        $overview[] = '';
        $overview[] = '## SECTION 9 — QA SUMMARY';
        $overview[] = '## SECTION 10 — FINAL CONTINUITY AUDIT';
        $overview[] = '- All five handoffs are consistent and verified.';
        $overview[] = '- All clip durations are synchronized.';
        $overview[] = '- Every spoken line respects natural pacing.';

        return [implode("\n", $overview), $this->input(['clip_count' => 6])];
    }

    private function clip(
        int $number,
        string $incoming,
        string $outgoing,
        string $openingState,
        string $endingState,
        string $openingPresentation = 'camera=medium; lighting=soft daylight; audio=quiet ambience',
        string $endingPresentation = 'camera=medium; lighting=soft daylight; audio=quiet ambience',
        int $duration = 4,
        ?array $speechRows = null,
        string $extra = '',
    ): string {
        $rows = $speechRows ?? [
            '- 0.2–2.2s | VISIBLE ACTION: a bee gathers pollen | SPEAKER: Narrator | LINE: “The bee carries pollen.”',
        ];

        return implode("\n", array_filter([
            'COPY CLIP '.$number.' PROMPT',
            'TRANSITION TYPE: '.$incoming,
            'VISIBLE CHARACTER CAST: None',
            'CHARACTER IDENTITY LOCK: None — no character is present in this shot.',
            'CLIP DURATION: '.$duration.' seconds',
            'PRIMARY IDEA: One visible action advances the beat.',
            'VISUAL REPRESENTATION: LITERAL SCENE',
            'VISIBLE CAUSE → ACTION → RESULT: A clear cause produces one visible action and result.',
            $extra,
            'OPENING CONTINUITY STATE: '.$openingState,
            'ENDING CONTINUITY STATE: '.$endingState,
            'OPENING PRESENTATION STATE: '.$openingPresentation,
            'ENDING PRESENTATION STATE: '.$endingPresentation,
            'SPEECH/VISUAL MAP:',
            ...$rows,
            'OUTGOING HANDOFF TYPE: '.$outgoing,
        ], static fn (string $line): bool => $line !== ''));
    }

    private function input(array $overrides = []): array
    {
        return array_merge([
            'mode' => 'story',
            'video_type' => 'cinematic-story',
            'clip_count' => 'auto',
            'clip_duration' => 'auto',
            'narration' => '1',
        ], $overrides);
    }
}
