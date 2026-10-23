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
            ."\n".$this->clip(2, 'TYPE B — NATURAL ACTION TRANSITION', 'FINAL — NO NEXT HANDOFF', $next, $prior, extra: 'UNRELATED FULL PROMPT PROSE THAT MUST NOT BE SENT TO AI');
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
        $this->assertNotContains('continuity_state_mismatch', array_column($result['quality_review']['issues'], 'code'));
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
        $content = $this->clip(1, 'INITIAL CLIP — NO PREVIOUS HANDOFF', 'FINAL — NO NEXT HANDOFF', $state, $state, $presentation, $presentation, duration: 4, speechRows: $rows);
        $service = new PromptRepairService($this->auditor, Mockery::mock(PromptGenerationService::class));

        $before = $this->auditor->audit($content, $this->input());
        $result = $service->repair(null, $content, $this->input());

        $this->assertContains('speech_rows_overlap', array_column($before['issues'], 'code'));
        $this->assertSame('CLIP DURATION: 5 seconds', $this->auditor->fieldValue($result['content'], 'CLIP DURATION'));
        $this->assertStringContainsString('- 0.0–2.0s | VISIBLE ACTION: the bee gathers pollen | SPEAKER: Narrator | LINE: “The bee carries pollen.”', $result['content']);
        $this->assertStringContainsString('- 2.2–4.2s | VISIBLE ACTION: the bee reaches another bloom | SPEAKER: Narrator | LINE: “It reaches another flower.”', $result['content']);
        $this->assertSame('passed', $result['quality_review']['status']);
        $this->assertSame([], $result['repair_report']['new_issues']);
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
        $this->assertStringContainsString('- 0.0–2.4s | VISIBLE ACTION:', $negativeResult['content']);
        $this->assertStringContainsString('- 0.0–2.4s | VISIBLE ACTION:', $beyondResult['content']);
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
            '- 0.0–2.0s | VISIBLE ACTION: a bee gathers pollen | SPEAKER: Narrator | LINE: “The bee carries pollen.”',
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
