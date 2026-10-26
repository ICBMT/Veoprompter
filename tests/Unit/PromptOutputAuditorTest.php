<?php

namespace Tests\Unit;

use App\Services\PromptOutputAuditor;
use PHPUnit\Framework\TestCase;

class PromptOutputAuditorTest extends TestCase
{
    private PromptOutputAuditor $auditor;

    protected function setUp(): void
    {
        parent::setUp();
        $this->auditor = new PromptOutputAuditor;
    }

    public function test_exact_type_a_state_and_presentation_handoff_passes_structure_but_still_requires_human_review(): void
    {
        $state = 'cast=Bee (CHAR-01) x1; object=pollen P-01 on left hind leg; process=transfer not yet started; environment=flower bed; motion=hovering east';
        $presentation = 'camera=macro close-up, static; lighting=soft morning sun; audio=quiet garden ambience';
        $content = $this->clip(1, 'INITIAL CLIP — NO PREVIOUS HANDOFF', 'TYPE A — DIRECT CONTINUATION', $state, $state, $presentation, $presentation)
            ."\n".$this->clip(2, 'TYPE A — DIRECT CONTINUATION', 'FINAL — NO NEXT HANDOFF', $state, $state, $presentation, $presentation);

        $review = $this->auditor->audit($content, $this->input(['clip_count' => 2]));

        $this->assertSame('passed', $review['status']);
        $this->assertSame([], $review['issues']);
        $this->assertSame('human_review', $this->check($review, 'factual_accuracy')['status']);
        $this->assertSame('human_review', $this->check($review, 'rendered_video')['status']);
        $this->assertStringContainsString('does not retrieve research sources', $this->check($review, 'factual_accuracy')['detail']);
    }

    public function test_a_justified_type_b_bridge_can_change_scene_state_visibly_inside_the_next_clip(): void
    {
        $priorState = 'object=seed tray in Mina left hand; stage=seeds planted; location=workbench; motion=stationary';
        $nextState = 'same seed tray on greenhouse bench; stage=seeds planted and ready for watering; motion=stationary';
        $bridge = $this->clip(2, 'TYPE B — NATURAL ACTION TRANSITION', 'FINAL — NO NEXT HANDOFF', $priorState, $nextState);
        $bridge = str_replace(
            'VISIBLE CAUSE → ACTION → RESULT: A clear cause produces one visible action and result.',
            'VISIBLE CAUSE → ACTION → RESULT: Mina lifts the same seed tray from the workbench, walks through the open greenhouse doorway, and sets it on the greenhouse bench without disturbing the planted seeds.',
            $bridge,
        );
        $bridge = str_replace(
            'ENDING PRESENTATION STATE: camera=medium; lighting=soft daylight; audio=quiet ambience',
            'ENDING PRESENTATION STATE: camera=slow tracking follow; lighting=soft daylight; audio=quiet ambience',
            $bridge,
        );
        $content = $this->clip(1, 'INITIAL CLIP — NO PREVIOUS HANDOFF', 'TYPE B — NATURAL ACTION TRANSITION', $priorState, $priorState)
            ."\n".$bridge;

        $review = $this->auditor->audit($content, $this->input(['clip_count' => 2]));

        $this->assertSame('passed', $review['status']);
        $this->assertSame([], $review['issues']);
    }

    public function test_a_type_a_state_jump_exposes_a_skipped_process_stage_or_inconsistent_object_state(): void
    {
        $raw = 'workpiece=P-07 raw steel panel; stage=unformed; location=press table; condition=flat';
        $finished = 'workpiece=P-07 finished car body; stage=complete; location=assembly bay; condition=painted';
        $content = $this->clip(1, 'INITIAL CLIP — NO PREVIOUS HANDOFF', 'TYPE A — DIRECT CONTINUATION', $raw, $raw)
            ."\n".$this->clip(2, 'TYPE A — DIRECT CONTINUATION', 'FINAL — NO NEXT HANDOFF', $finished, $finished);

        $review = $this->auditor->audit($content, $this->input(['clip_count' => 2]));

        $this->assertSame('needs_review', $review['status']);
        $this->assertContains('continuity_state_mismatch', array_column($review['issues'], 'code'));
        $this->assertStringContainsString('does not satisfy the preceding handoff contract', $this->issue($review, 'continuity_state_mismatch')['message']);
    }

    public function test_type_a_also_requires_matching_camera_lighting_and_audio_presentation(): void
    {
        $state = 'cast=None; object=flower pot stationary; stage=empty pot; environment=table';
        $content = $this->clip(1, 'INITIAL CLIP — NO PREVIOUS HANDOFF', 'TYPE A — DIRECT CONTINUATION', $state, $state, 'camera=wide; lighting=morning; audio=room tone', 'camera=wide; lighting=morning; audio=room tone')
            ."\n".$this->clip(2, 'TYPE A — DIRECT CONTINUATION', 'FINAL — NO NEXT HANDOFF', $state, $state, 'camera=close-up; lighting=blue night; audio=rain', 'camera=close-up; lighting=blue night; audio=rain');

        $review = $this->auditor->audit($content, $this->input(['clip_count' => 2]));

        $this->assertContains('presentation_state_mismatch', array_column($review['issues'], 'code'));
    }

    public function test_type_b_must_open_on_the_prior_presentation_before_a_visible_camera_change(): void
    {
        $state = 'cast=None; object=seed tray; stage=seeds planted; environment=greenhouse';
        $priorPresentation = 'camera=static wide; lighting=soft morning; audio=greenhouse ambience';
        $changedOpening = 'camera=close-up; lighting=blue night; audio=rain';
        $content = $this->clip(1, 'INITIAL CLIP — NO PREVIOUS HANDOFF', 'TYPE B — NATURAL ACTION TRANSITION', $state, $state, $priorPresentation, $priorPresentation)
            ."\n".$this->clip(2, 'TYPE B — NATURAL ACTION TRANSITION', 'FINAL — NO NEXT HANDOFF', $state, $state, $changedOpening, $changedOpening);

        $review = $this->auditor->audit($content, $this->input(['clip_count' => 2]));

        $this->assertContains('presentation_state_mismatch', array_column($review['issues'], 'code'));
        $this->assertStringContainsString('Type A/Type B openings must match the previous ending presentation state', $this->issue($review, 'presentation_state_mismatch')['message']);
    }

    public function test_overlong_narration_is_flagged_by_conservative_word_timing_and_can_be_revised(): void
    {
        $state = 'cast=Bee x1; object=pollen grain on flower; stage=before landing; environment=garden';
        $presentation = 'camera=macro; lighting=daylight; audio=soft music';
        $clip = $this->clip(1, 'INITIAL CLIP — NO PREVIOUS HANDOFF', 'FINAL — NO NEXT HANDOFF', $state, $state, $presentation, $presentation);
        $clip = str_replace(
            '- 0.2–2.2s | VISIBLE ACTION: pollen transfers to a bee | SPEAKER: Narrator | LINE: “The bee carries pollen.”',
            '- 0.0–1.0s | VISIBLE ACTION: pollen transfers to a bee | SPEAKER: Narrator | LINE: “The tiny bee carries bright yellow pollen from this flower to another flower.”',
            $clip,
        );

        $review = $this->auditor->audit($clip, $this->input());

        $this->assertContains('speech_overrun', array_column($review['issues'], 'code'));
        $this->assertStringContainsString('Shorten it', $this->issue($review, 'speech_overrun')['message']);
    }

    public function test_ai_duration_above_ten_seconds_is_flagged_but_fixed_manual_duration_is_not_capped_at_ten(): void
    {
        $state = 'cast=None; object=single machine; stage=running; environment=workshop';
        $presentation = 'camera=static; lighting=neutral; audio=machine hum';
        $clip = $this->clip(1, 'INITIAL CLIP — NO PREVIOUS HANDOFF', 'FINAL — NO NEXT HANDOFF', $state, $state, $presentation, $presentation);
        $clip = str_replace('CLIP DURATION: 4 seconds', 'CLIP DURATION: 11 seconds', $clip);

        $aiReview = $this->auditor->audit($clip, $this->input());
        $manualReview = $this->auditor->audit($clip, $this->input(['clip_duration' => 11]));

        $this->assertContains('ai_duration_over_limit', array_column($aiReview['issues'], 'code'));
        $this->assertNotContains('ai_duration_over_limit', array_column($manualReview['issues'], 'code'));
    }

    public function test_clip_plan_duration_is_compared_with_the_copy_ready_clip(): void
    {
        $state = 'cast=None; object=wooden bowl; stage=carved; environment=workshop';
        $plan = "## SECTION 7 — CLIP PLAN AND CONTINUITY MAP\n- Clip 1 — Finish the carved bowl\n  - Exact duration: 5 seconds\n\n## SECTION 8 — COPY-AND-PASTE VIDEO PROMPTS\n";
        $content = $plan.$this->clip(1, 'INITIAL CLIP — NO PREVIOUS HANDOFF', 'FINAL — NO NEXT HANDOFF', $state, $state);

        $review = $this->auditor->audit($content, $this->input());

        $this->assertContains('clip_plan_duration_mismatch', array_column($review['issues'], 'code'));
        $this->assertStringContainsString('clip plan lists Clip 1 as 5 seconds', $this->issue($review, 'clip_plan_duration_mismatch')['message']);
    }

    public function test_project_declared_clip_range_is_enforced_and_conflicts_with_a_fixed_choice_are_reported(): void
    {
        $state = 'cast=None; object=wooden bowl; stage=carved; environment=workshop';
        $content = "## PROJECT OVERVIEW\nAll clips must stay between 3 and 7 seconds.\nClip durations: 8 seconds\nApproximate total runtime: 8 seconds\n\n"
            .$this->clip(1, 'INITIAL CLIP — NO PREVIOUS HANDOFF', 'FINAL — NO NEXT HANDOFF', $state, $state);
        $aiReview = $this->auditor->audit($content, $this->input());
        $fixedReview = $this->auditor->audit($content, $this->input(['clip_duration' => 8]));

        $this->assertContains('declared_duration_constraint_violation', array_column($aiReview['issues'], 'code'));
        $this->assertContains('duration_constraint_conflict', array_column($fixedReview['issues'], 'code'));
        $this->assertSame(3, $this->auditor->durationLimits($content, $this->input())['minimum']);
        $this->assertSame(7, $this->auditor->durationLimits($content, $this->input())['maximum']);

        $mixedRules = $this->auditor->durationLimits('', $this->input([
            'creative_direction' => 'Manual clips must be between 4 and 20 seconds; AI clips must stay between 3 and 7 seconds.',
        ]));
        $this->assertSame(3, $mixedRules['minimum']);
        $this->assertSame(7, $mixedRules['maximum']);
    }

    public function test_ai_durations_below_four_seconds_remain_allowed_without_a_project_lower_bound(): void
    {
        $state = 'cast=None; object=seed; stage=planted; environment=greenhouse';
        $clip = str_replace(
            'CLIP DURATION: 4 seconds',
            'CLIP DURATION: 3 seconds',
            $this->clip(1, 'INITIAL CLIP — NO PREVIOUS HANDOFF', 'FINAL — NO NEXT HANDOFF', $state, $state),
        );

        $review = $this->auditor->audit($clip, $this->input());

        $this->assertNotContains('declared_duration_constraint_violation', array_column($review['issues'], 'code'));
        $this->assertNotContains('ai_duration_over_limit', array_column($review['issues'], 'code'));
    }

    public function test_a_factual_contradiction_is_not_mislabeled_as_verified_by_text_checks(): void
    {
        $state = 'cast=None; object=Earth; stage=not applicable; environment=diagram';
        $presentation = 'camera=static diagram; lighting=flat; audio=none';
        $clip = $this->clip(1, 'INITIAL CLIP — NO PREVIOUS HANDOFF', 'FINAL — NO NEXT HANDOFF', $state, $state, $presentation, $presentation);
        $clip .= "\nFACT CLAIM: Earth is flat.\nNARRATION: Earth is a sphere.";

        $review = $this->auditor->audit($clip, $this->input());
        $factualCheck = $this->check($review, 'factual_accuracy');

        $this->assertSame('human_review', $factualCheck['status']);
        $this->assertStringContainsString('Not independently verified', $factualCheck['detail']);
        $this->assertStringNotContainsString('verified as accurate', strtolower($factualCheck['detail']));
        $this->assertContains('No external research sources were retrieved by this generation endpoint; do not treat the prompt as independently fact-checked.', $review['limitations']);
    }

    public function test_project_overview_clip_durations_and_total_runtime_are_compared_with_the_copy_ready_clips(): void
    {
        $state = 'cast=None; object=single seed tray; stage=seeds planted; environment=greenhouse';
        $overview = "## PROJECT OVERVIEW\nClip durations: 5 seconds, 4 seconds\nApproximate total runtime: 9 seconds\n\n";
        $content = $overview
            .$this->clip(1, 'INITIAL CLIP — NO PREVIOUS HANDOFF', 'TYPE A — DIRECT CONTINUATION', $state, $state)
            ."\n".$this->clip(2, 'TYPE A — DIRECT CONTINUATION', 'FINAL — NO NEXT HANDOFF', $state, $state);

        $review = $this->auditor->audit($content, $this->input(['clip_count' => 2]));
        $codes = array_column($review['issues'], 'code');

        $this->assertContains('overview_clip_duration_mismatch', $codes);
        $this->assertContains('overview_total_runtime_mismatch', $codes);
        $this->assertSame('warning', $this->check($review, 'duration')['status']);
    }

    public function test_project_overview_missing_or_unparseable_duration_references_are_not_silently_passed(): void
    {
        $state = 'cast=None; object=single seed tray; stage=seeds planted; environment=greenhouse';
        $clips = $this->clip(1, 'INITIAL CLIP — NO PREVIOUS HANDOFF', 'TYPE A — DIRECT CONTINUATION', $state, $state)
            ."\n".$this->clip(2, 'TYPE A — DIRECT CONTINUATION', 'FINAL — NO NEXT HANDOFF', $state, $state);
        $unparseable = "## PROJECT OVERVIEW\nClip durations: to be determined\nApproximate total runtime: TBD\n\n".$clips;
        $review = $this->auditor->audit($unparseable, $this->input(['clip_count' => 2]));

        $this->assertContains('overview_clip_durations_unverifiable', array_column($review['issues'], 'code'));
        $this->assertContains('overview_total_runtime_missing', array_column($review['issues'], 'code'));

        $partial = "## PROJECT OVERVIEW\nClip 1 — 4 seconds\nApproximate total runtime: 8 seconds\n\n".$clips;
        $review = $this->auditor->audit($partial, $this->input(['clip_count' => 2]));

        $this->assertContains('overview_clip_duration_missing', array_column($review['issues'], 'code'));
    }

    public function test_type_b_compatible_state_requires_an_explicit_action_bridge(): void
    {
        $prior = 'object=seed tray on greenhouse bench; stage=seeds planted; environment=greenhouse; motion=stationary';
        $next = 'object=seed tray on greenhouse bench; stage=seeds planted and ready for watering; environment=greenhouse; motion=stationary';
        $content = $this->clip(1, 'INITIAL CLIP — NO PREVIOUS HANDOFF', 'TYPE B — NATURAL ACTION TRANSITION', $prior, $prior)
            ."\n".$this->clip(2, 'TYPE B — NATURAL ACTION TRANSITION', 'FINAL — NO NEXT HANDOFF', $next, $next);

        $review = $this->auditor->audit($content, $this->input(['clip_count' => 2]));
        $codes = array_column($review['issues'], 'code');

        $this->assertContains('type_b_transition_action_unverified', $codes);
        $this->assertContains('continuity_state_mismatch', $codes);
    }

    public function test_type_b_requires_a_visible_bridge_even_when_structured_states_are_equal(): void
    {
        $state = 'cast=None; object=seed tray; stage=seeds planted; location=greenhouse bench; motion=stationary';
        $content = $this->clip(1, 'INITIAL CLIP — NO PREVIOUS HANDOFF', 'TYPE B — NATURAL ACTION TRANSITION', $state, $state)
            ."\n".$this->clip(2, 'TYPE B — NATURAL ACTION TRANSITION', 'FINAL — NO NEXT HANDOFF', $state, $state);

        $review = $this->auditor->audit($content, $this->input(['clip_count' => 2]));

        $this->assertContains('type_b_transition_action_unverified', array_column($review['issues'], 'code'));
        $this->assertSame('unverified', $review['handoffs'][0]['overall_relation']);
    }

    public function test_type_c_transition_must_document_before_transition_and_after_states(): void
    {
        $before = 'cast=None; object=glass bottle G-01; material=clear glass; stage=hot shaped bottle; location=furnace exit; motion=on conveyor';
        $after = 'cast=None; object=glass bottle G-01; material=clear glass; stage=cooled finished bottle; location=inspection table; motion=stationary';
        $first = $this->clip(1, 'INITIAL CLIP — NO PREVIOUS HANDOFF', 'TYPE C — NARRATIVE / TEMPORAL TRANSITION', $before, $before);
        $second = $this->clip(2, 'TYPE C — NARRATIVE / TEMPORAL TRANSITION', 'FINAL — NO NEXT HANDOFF', $before, $after);
        $second = str_replace(
            'VISIBLE CAUSE → ACTION → RESULT: A clear cause produces one visible action and result.',
            'VISIBLE CAUSE → ACTION → RESULT: Before state: the hot glass bottle is at the furnace exit. A time-lapse shows it cooling as it travels through the annealing lehr. After state: the cooled bottle rests on the inspection table.',
            $second,
        );
        $review = $this->auditor->audit($first."\n".$second, $this->input(['clip_count' => 2]));

        $this->assertSame([], $review['issues']);
        $this->assertSame('transition_documented', $review['handoffs'][0]['overall_relation']);
        $this->assertFalse($review['handoffs'][0]['exact_copy']);
    }

    public function test_type_c_must_open_on_the_prior_ending_before_showing_its_transition(): void
    {
        $before = 'cast=None; object=red door; state=closed; location=hallway; motion=stationary';
        $after = 'cast=None; object=red door; state=open; location=hallway; motion=stationary';
        $first = $this->clip(1, 'INITIAL CLIP — NO PREVIOUS HANDOFF', 'TYPE C — NARRATIVE / TEMPORAL TRANSITION', $before, $before);
        $second = $this->clip(2, 'TYPE C — NARRATIVE / TEMPORAL TRANSITION', 'FINAL — NO NEXT HANDOFF', $after, $after);
        $second = str_replace(
            'VISIBLE CAUSE → ACTION → RESULT: A clear cause produces one visible action and result.',
            'VISIBLE CAUSE → ACTION → RESULT: Before state: the red door is closed in the hallway. A time-lapse shows the red door opening. After state: the red door is open in the hallway.',
            $second,
        );
        $review = $this->auditor->audit($first."\n".$second, $this->input(['clip_count' => 2]));
        $codes = array_column($review['issues'], 'code');

        $this->assertContains('type_c_transition_undocumented', $codes);
        $this->assertContains('continuity_state_mismatch', $codes);
        $this->assertSame('mismatch', $review['handoffs'][0]['overall_relation']);
    }

    public function test_speech_timing_checks_lead_in_inter_line_pause_and_required_ending_pause(): void
    {
        $state = 'cast=None; object=turning gear; stage=rotating; environment=workshop';
        $rows = [
            '- 0.0–1.5s | VISIBLE ACTION: the gear begins to turn | SPEAKER: Narrator | LINE: “One two three.”',
            '- 1.6–3.8s | VISIBLE ACTION: the gear slows | SPEAKER: Narrator | LINE: “It turns slowly.”',
        ];
        $clip = $this->clip(1, 'INITIAL CLIP — NO PREVIOUS HANDOFF', 'FINAL — NO NEXT HANDOFF', $state, $state);
        $clip = str_replace(
            '- 0.2–2.2s | VISIBLE ACTION: pollen transfers to a bee | SPEAKER: Narrator | LINE: “The bee carries pollen.”',
            implode("\n", $rows),
            $clip,
        );

        $review = $this->auditor->audit($clip, $this->input());
        $codes = array_column($review['issues'], 'code');

        $this->assertContains('speech_opening_visual_beat_missing', $codes);
        $this->assertContains('speech_inter_line_pause_missing', $codes);
        $this->assertContains('speech_leaves_no_visual_tail', $codes);
    }

    public function test_total_runtime_parser_normalizes_supported_seconds_and_clock_formats(): void
    {
        $formats = [
            'Approximate total runtime: 38 seconds' => 38.0,
            'Estimated overall running time: 0:38' => 38.0,
            'Total video duration: 00:00:38' => 38.0,
            'Project run time = 2:03' => 123.0,
        ];

        foreach ($formats as $label => $expectedSeconds) {
            $content = "## PROJECT OVERVIEW\n".$label."\n\n"
                .$this->clip(1, 'INITIAL CLIP — NO PREVIOUS HANDOFF', 'FINAL — NO NEXT HANDOFF', 'cast=None; object=glass bottle G-01; stage=annealed; environment=glassworks', 'cast=None; object=glass bottle G-01; stage=annealed; environment=glassworks');
            $references = $this->auditor->overviewRuntimeReferences($content);

            $this->assertCount(1, $references, $label);
            $this->assertSame($expectedSeconds, $references[0]['seconds'], $label);
        }
    }

    public function test_project_wide_runtime_constraints_are_separate_from_descriptive_total_runtime(): void
    {
        $content = "## PROJECT OVERVIEW\n- Approximate total runtime: 38 seconds\n- Total runtime must not exceed 40 seconds.\n\n"
            .$this->clip(1, 'INITIAL CLIP — NO PREVIOUS HANDOFF', 'FINAL — NO NEXT HANDOFF', 'cast=None; object=glass bottle G-01; stage=annealed; environment=glassworks', 'cast=None; object=glass bottle G-01; stage=annealed; environment=glassworks');
        $limits = $this->auditor->projectRuntimeLimits($content, $this->input([
            'project_runtime_min_seconds' => 35,
        ]));

        $this->assertSame(40, $limits['maximum']);
        $this->assertSame(35, $limits['minimum']);
        $this->assertFalse($limits['conflict']);

        $runningTimeLimits = $this->auditor->projectRuntimeLimits('Overall running time must not exceed 32 seconds.', []);
        $this->assertSame(32, $runningTimeLimits['maximum']);

        $savedRangeLimits = $this->auditor->projectRuntimeLimits('', ['project_runtime_range' => '27–41 seconds']);
        $this->assertSame(27, $savedRangeLimits['minimum']);
        $this->assertSame(41, $savedRangeLimits['maximum']);
        $this->assertFalse($savedRangeLimits['conflict']);

        $labelRangeLimits = $this->auditor->projectRuntimeLimits('Total project runtime: 28–42 seconds.', []);
        $this->assertSame(28, $labelRangeLimits['minimum']);
        $this->assertSame(42, $labelRangeLimits['maximum']);

        $leadingMinimum = $this->auditor->projectRuntimeLimits('Minimum project runtime: 31 seconds.', []);
        $this->assertSame(31, $leadingMinimum['minimum']);
        $interposedMaximum = $this->auditor->projectRuntimeLimits('Project maximum runtime is 46 seconds.', []);
        $this->assertSame(46, $interposedMaximum['maximum']);
    }

    public function test_individual_overview_clip_durations_are_not_mistaken_for_a_shared_duration_range(): void
    {
        $content = "## PROJECT OVERVIEW\n- Clip 1 — 5 seconds\n- Clip 2 — 6 seconds\n- Clip 3 — 7 seconds\n- Approximate total runtime: 18 seconds";

        $limits = $this->auditor->durationLimits($content, $this->input());

        $this->assertFalse($limits['conflict']);
        $this->assertNull($limits['declared_minimum']);
        $this->assertNull($limits['declared_maximum']);
        $this->assertSame(1, $limits['minimum']);
        $this->assertSame(10, $limits['maximum']);

        $singleLineLimits = $this->auditor->durationLimits('Clip 1 — 5 seconds and Clip 2 — 6 seconds', $this->input());
        $this->assertFalse($singleLineLimits['conflict']);
        $this->assertNull($singleLineLimits['declared_minimum']);
        $this->assertNull($singleLineLimits['declared_maximum']);
    }

    public function test_compound_minute_and_second_durations_are_left_unparseable_for_human_review(): void
    {
        $state = 'cast=None; object=wooden bowl; stage=carved; environment=workshop';
        $clip = $this->clip(1, 'INITIAL CLIP — NO PREVIOUS HANDOFF', 'FINAL — NO NEXT HANDOFF', $state, $state);
        $content = "## PROJECT OVERVIEW\n- Clip 1 — 1 minute 30 seconds\n- Approximate total runtime: 1 minute 30 seconds\n\n"
            ."## CLIP PLAN AND CONTINUITY MAP\n- Clip 1 — Finish the bowl\n  - Exact duration: 1 minute 30 seconds\n\n"
            .$clip;

        $review = $this->auditor->audit($content, $this->input());
        $codes = array_column($review['issues'], 'code');

        $this->assertSame([], $this->auditor->overviewDurationReferences($content));
        $this->assertSame([], $this->auditor->overviewRuntimeReferences($content));
        $this->assertSame([], $this->auditor->clipPlanDurationReferences($content));
        $this->assertContains('overview_clip_durations_unverifiable', $codes);
        $this->assertContains('overview_total_runtime_missing', $codes);
        $this->assertContains('clip_plan_duration_missing', $codes);
    }

    public function test_final_audit_pacing_and_synchronization_claims_need_matching_qa_evidence(): void
    {
        $state = 'cast=None; object=spinning wheel; stage=rotating; environment=workshop';
        $clip = $this->clip(1, 'INITIAL CLIP — NO PREVIOUS HANDOFF', 'FINAL — NO NEXT HANDOFF', $state, $state);
        $clip = str_replace(
            '- 0.2–2.2s | VISIBLE ACTION: pollen transfers to a bee | SPEAKER: Narrator | LINE: “The bee carries pollen.”',
            '- 0.0–1.0s | VISIBLE ACTION: the wheel spins | SPEAKER: Narrator | LINE: “One two three four five six seven eight nine ten eleven.”',
            $clip,
        );
        $content = $clip."\n\n### FINAL CONTINUITY AUDIT\n- All clip durations are synchronized.\n- Every spoken line respects natural pacing.";

        $review = $this->auditor->audit($content, $this->input());
        $codes = array_column($review['issues'], 'code');

        $this->assertContains('speech_overrun', $codes);
        $this->assertContains('timing_audit_overclaim', $codes);
        $this->assertContains('speech_pacing_overclaim', $codes);
    }

    public function test_final_audit_timing_and_pacing_claims_are_allowed_when_all_text_checks_pass(): void
    {
        $state = 'cast=None; object=spinning wheel; stage=rotating; environment=workshop';
        $speech = '- 0.2–3.2s | VISIBLE ACTION: the wheel spins steadily | SPEAKER: Narrator | LINE: “The wheel turns smoothly.”';
        $clip = $this->clip(1, 'INITIAL CLIP — NO PREVIOUS HANDOFF', 'FINAL — NO NEXT HANDOFF', $state, $state);
        $clip = str_replace(
            '- 0.2–2.2s | VISIBLE ACTION: pollen transfers to a bee | SPEAKER: Narrator | LINE: “The bee carries pollen.”',
            $speech,
            $clip,
        );
        $content = "## PROJECT OVERVIEW\n- Clip 1 — 4 seconds\n- Approximate total runtime: 4 seconds\n\n"
            ."## CLIP PLAN AND CONTINUITY MAP\n- Clip 1 — Wheel\n  - Exact duration: 4 seconds\n\n"
            .$clip."\n\n### FINAL CONTINUITY AUDIT\n- All clip durations are synchronized.\n- Every spoken line respects natural pacing.";

        $review = $this->auditor->audit($content, $this->input());
        $codes = array_column($review['issues'], 'code');

        $this->assertNotContains('timing_audit_overclaim', $codes);
        $this->assertNotContains('speech_pacing_overclaim', $codes);
    }

    public function test_structured_continuity_state_conflicting_with_exact_frame_prose_is_flagged(): void
    {
        $state = 'object=red door closed; location=hallway';
        $clip = $this->clip(1, 'INITIAL CLIP — NO PREVIOUS HANDOFF', 'FINAL — NO NEXT HANDOFF', $state, $state)
            ."\nEXACT OPENING FRAME: The red door stands open in the hallway."
            ."\nEXACT ENDING STATE: The red door remains wide open in the hallway.";

        $review = $this->auditor->audit($clip, $this->input());

        $this->assertSame(2, count(array_filter(
            $review['issues'],
            static fn (array $issue): bool => $issue['code'] === 'continuity_prose_state_mismatch',
        )));
    }

    public function test_type_b_with_exact_opening_and_visible_bridge_is_compatible_not_an_exact_prose_copy(): void
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

        $review = $this->auditor->audit($first."\n".$second, $this->input(['clip_count' => 2]));

        $this->assertSame('exact', $review['handoffs'][0]['continuity_relation']);
        $this->assertSame('compatible', $review['handoffs'][0]['overall_relation']);
        $this->assertFalse($review['handoffs'][0]['exact_copy']);
        $this->assertContains('continuity_audit_overclaim', array_column($review['issues'], 'code'));
        $this->assertNotContains('continuity_state_mismatch', array_column($review['issues'], 'code'));
        $this->assertNotContains('type_b_transition_action_unverified', array_column($review['issues'], 'code'));
    }

    public function test_type_b_with_exact_structured_states_but_compatible_prose_is_not_a_verbatim_copy(): void
    {
        $state = 'object=seed tray on greenhouse bench; stage=seeds planted; environment=greenhouse; motion=stationary';
        $first = $this->clip(1, 'INITIAL CLIP — NO PREVIOUS HANDOFF', 'TYPE B — NATURAL ACTION TRANSITION', $state, $state)
            ."\nEXACT ENDING STATE: Seeds are planted in the seed tray on the greenhouse bench, still.";
        $second = $this->clip(2, 'TYPE B — NATURAL ACTION TRANSITION', 'FINAL — NO NEXT HANDOFF', $state, $state)
            ."\nEXACT OPENING FRAME: The planted seeds remain in the seed tray on the greenhouse bench, motionless."
            ."\n\n### FINAL CONTINUITY AUDIT\n- The Type B handoff is an exact state copy of the prior clip.";
        $second = str_replace(
            'VISIBLE CAUSE → ACTION → RESULT: A clear cause produces one visible action and result.',
            'VISIBLE CAUSE → ACTION → RESULT: A hand turns the seed tray slightly on the greenhouse bench, keeping the planted seeds in place.',
            $second,
        );

        $review = $this->auditor->audit($first."\n".$second, $this->input(['clip_count' => 2]));

        $this->assertSame('exact', $review['handoffs'][0]['continuity_relation']);
        $this->assertSame('compatible', $review['handoffs'][0]['written_opening_relation']);
        $this->assertSame('compatible', $review['handoffs'][0]['overall_relation']);
        $this->assertFalse($review['handoffs'][0]['exact_copy']);
        $this->assertContains('continuity_audit_overclaim', array_column($review['issues'], 'code'));
    }

    public function test_missing_structured_prompt_is_reported_as_not_checked_not_as_passed(): void
    {
        $review = $this->auditor->audit('A plain unstructured answer.', $this->input());

        $this->assertSame('not_checked', $review['status']);
        $this->assertSame('warning', $this->check($review, 'structure')['status']);
        $this->assertSame('not_run', $this->check($review, 'duration')['status']);
        $this->assertFalse($review['issues'][0]['repairable']);
    }

    private function clip(
        int $number,
        string $incoming,
        string $outgoing,
        string $openingState,
        string $endingState,
        string $openingPresentation = 'camera=medium; lighting=soft daylight; audio=quiet ambience',
        string $endingPresentation = 'camera=medium; lighting=soft daylight; audio=quiet ambience',
    ): string {
        $transition = str_contains($incoming, 'INITIAL') ? 'INITIAL CLIP — NO PREVIOUS HANDOFF' : $incoming;

        return implode("\n", [
            'COPY CLIP '.$number.' PROMPT',
            'TRANSITION TYPE: '.$transition,
            'VISIBLE CHARACTER CAST: None',
            'CHARACTER IDENTITY LOCK: None — no character is present in this shot.',
            'CLIP DURATION: 4 seconds',
            'PRIMARY IDEA: One visible action teaches or advances the beat.',
            'VISUAL REPRESENTATION: LITERAL SCENE',
            'VISIBLE CAUSE → ACTION → RESULT: A clear cause produces one visible action and result.',
            'OPENING CONTINUITY STATE: '.$openingState,
            'ENDING CONTINUITY STATE: '.$endingState,
            'OPENING PRESENTATION STATE: '.$openingPresentation,
            'ENDING PRESENTATION STATE: '.$endingPresentation,
            'SPEECH/VISUAL MAP:',
            '- 0.2–2.2s | VISIBLE ACTION: pollen transfers to a bee | SPEAKER: Narrator | LINE: “The bee carries pollen.”',
            'OUTGOING HANDOFF TYPE: '.$outgoing,
        ]);
    }

    private function input(array $overrides = []): array
    {
        return array_merge([
            'mode' => 'story',
            'video_type' => 'educational',
            'clip_count' => 'auto',
            'clip_duration' => 'auto',
            'narration' => '1',
        ], $overrides);
    }

    private function check(array $review, string $id): array
    {
        foreach ($review['checks'] as $check) {
            if ($check['id'] === $id) return $check;
        }

        self::fail('Missing quality check: '.$id);
    }

    private function issue(array $review, string $code): array
    {
        foreach ($review['issues'] as $issue) {
            if ($issue['code'] === $code) return $issue;
        }

        self::fail('Missing quality issue: '.$code);
    }
}
