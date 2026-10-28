<?php

namespace Tests\Unit;

use App\Services\PromptGenerationService;
use App\Services\PromptOutputAuditor;
use App\Services\PromptRepairService;
use Tests\TestCase;

class SpeechTimingTest extends TestCase
{
    public static function fixture(string $heading, array $rows, int $duration = 4): string
    {
        return $heading."\nCLIP DURATION: {$duration} seconds\nTRANSITION TYPE: INITIAL CLIP — NO PREVIOUS HANDOFF\nVISIBLE CHARACTER CAST: None\nCHARACTER IDENTITY LOCK: None — no characters.\nPRIMARY IDEA: The wheel slows.\nVISUAL REPRESENTATION: LITERAL SCENE\nVISIBLE CAUSE → ACTION → RESULT: Friction causes the wheel to slow.\nOPENING CONTINUITY STATE: object=wheel; motion=turning; environment=workshop\nENDING CONTINUITY STATE: object=wheel; motion=slowing; environment=workshop\nOPENING PRESENTATION STATE: camera=static; lighting=daylight\nENDING PRESENTATION STATE: camera=static; lighting=daylight\nSPEECH/VISUAL MAP:\n".implode("\n", $rows)."\nOUTGOING HANDOFF TYPE: FINAL — NO NEXT HANDOFF";
    }

    public static function input(string $mode = 'story', array $extra = []): array
    {
        return [...['mode' => $mode, 'narration' => '1', 'clip_duration' => 'auto',
            'extend_duration' => 'auto', 'clip_count' => 'quality'], ...$extra];
    }

    public function test_timing_findings_and_safe_repairs_are_format_independent(): void
    {
        $auditor = new PromptOutputAuditor;
        foreach (['COPY CLIP 1 PROMPT', '### COPY CLIP 1 PROMPT', 'CLIP 1 VIDEO PROMPT', 'COPY EXTENSION VIDEO PROMPT'] as $heading) {
            $mode = str_contains($heading, 'EXTENSION') ? 'extend' : 'story';
            $content = self::fixture($heading, [
                '- 0.0–1.0s | VISIBLE ACTION: wheel turns | SPEAKER: Narrator | LINE: “It turns.”',
                '- 1.1–3.9s | VISIBLE ACTION: wheel slows | SPEAKER: Narrator | LINE: “It slows.”',
            ]);
            $input = self::input($mode);
            $codes = array_column($auditor->audit($content, $input)['issues'], 'code');
            foreach (['speech_opening_visual_beat_missing', 'speech_inter_line_pause_missing', 'speech_leaves_no_visual_tail'] as $code) {
                $this->assertContains($code, $codes);
            }
            $result = (new PromptRepairService($auditor, new PromptGenerationService))->repair(null, $content, $input);
            $this->assertTrue($result['repair_report']['applied']);
            $this->assertTrue($auditor->preservesSpokenContent($content, $result['content'], $input));
            $this->assertSame($auditor->audit($result['content'], $input)['issues'], $result['quality_review']['issues']);
            foreach (array_column($result['quality_review']['issues'], 'code') as $code) {
                $this->assertFalse(str_starts_with($code, 'speech_'), $code);
            }
        }
    }

    public function test_budget_deducts_opening_tail_and_all_inter_line_pauses(): void
    {
        $auditor = new PromptOutputAuditor;
        $budget = $auditor->speechBudget([['line' => 'One.'], ['line' => 'Two.']], 4, $auditor->speechTimingPolicy());
        $this->assertEqualsWithDelta(3.3, $budget['available_seconds'], 0.0001);
        $this->assertTrue($budget['fits']);
        $this->assertGreaterThan($auditor->minimumSpeechSeconds('It turns.'), $auditor->minimumSpeechSeconds('It turns.', ['audience' => 'Preschool children']));
        $this->assertGreaterThan(1 / 2.5 + 0.12, $auditor->minimumSpeechSeconds('Electromagnetism.'));
    }

    public function test_planning_estimate_constants_are_the_same_values_used_to_compute_qa_time(): void
    {
        $auditor = new PromptOutputAuditor;
        foreach ([[], ['audience' => 'Preschool children']] as $input) {
            $policy = $auditor->speechEstimatePolicy($input);
            $expected = 2 / $policy['words_per_second'] + max($policy['sentence_pause_min_seconds'],
                min($policy['sentence_pause_max_seconds'], $policy['sentence_pause_per_sentence_seconds']));
            $this->assertEqualsWithDelta($expected, $auditor->minimumSpeechSeconds('It moves.', $input), 0.0001);
        }
    }

    public function test_boundary_pads_are_not_weakened_by_rounding_tolerance(): void
    {
        $auditor = new PromptOutputAuditor;
        $content = self::fixture('COPY CLIP 1 PROMPT', [
            '- 0.19–1.0s | VISIBLE ACTION: wheel turns | SPEAKER: Narrator | LINE: “One.”',
            '- 1.19–3.71s | VISIBLE ACTION: wheel slows | SPEAKER: Narrator | LINE: “Two.”',
        ]);
        $codes = array_column($auditor->audit($content, self::input())['issues'], 'code');
        foreach (['speech_opening_visual_beat_missing', 'speech_inter_line_pause_missing', 'speech_leaves_no_visual_tail'] as $code) {
            $this->assertContains($code, $codes);
        }
    }

    public function test_impossible_protected_dialogue_remains_unresolved_in_fixed_and_ai_duration(): void
    {
        $auditor = new PromptOutputAuditor;
        $line = implode(' ', array_fill(0, 40, 'word')).'.';
        $content = self::fixture('COPY CLIP 1 PROMPT', ["- 0.0–3.9s | VISIBLE ACTION: wheel turns | SPEAKER: Narrator | LINE: “{$line}”"]);
        foreach ([self::input(), self::input('story', ['clip_duration' => '4', 'clip_count' => '2'])] as $input) {
            $result = (new PromptRepairService($auditor, new PromptGenerationService))->repair(null, $content, $input);
            $this->assertSame($content, $result['content']);
            $this->assertContains('speech_budget_exceeded', array_column($result['repair_report']['unresolved'], 'code'));
            $this->assertContains('speech_overrun', array_column($result['repair_report']['unresolved'], 'code'));
            $this->assertFalse($result['repair_report']['applied']);
            $this->assertSame([], $result['repair_report']['fixed']);
        }
    }

    public function test_existing_delivery_windows_are_not_compressed_to_force_a_fixed_duration(): void
    {
        $auditor = new PromptOutputAuditor;
        $content = self::fixture('COPY CLIP 1 PROMPT', ['- 0.0–4.0s | VISIBLE ACTION: wheel turns | SPEAKER: Narrator | LINE: “It turns.”']);
        $result = (new PromptRepairService($auditor, new PromptGenerationService))->repair(null, $content,
            self::input('story', ['clip_duration' => '4', 'clip_count' => '2']));
        $this->assertSame($content, $result['content']);
        $this->assertFalse($result['repair_report']['applied']);
        // A long visual setup and an intentional inter-line pause must not be compressed either.
        $late = self::fixture('COPY CLIP 1 PROMPT', ['- 2.0–3.9s | VISIBLE ACTION: wheel slows | SPEAKER: Narrator | LINE: “It slows.”']);
        $lateResult = (new PromptRepairService($auditor, new PromptGenerationService))->repair(null, $late,
            self::input('story', ['clip_duration' => '4', 'clip_count' => '2']));
        $this->assertSame($late, $lateResult['content']);
        $paused = self::fixture('COPY CLIP 1 PROMPT', [
            '- 0.2–1.0s | VISIBLE ACTION: wheel turns | SPEAKER: Narrator | LINE: “One.”',
            '- 3.0–3.9s | VISIBLE ACTION: wheel slows | SPEAKER: Narrator | LINE: “Two.”',
        ]);
        $pausedResult = (new PromptRepairService($auditor, new PromptGenerationService))->repair(null, $paused,
            self::input('story', ['clip_duration' => '4', 'clip_count' => '2']));
        $this->assertSame($paused, $pausedResult['content']);
    }

    public function test_unaffected_clip_bodies_must_survive_a_contextual_correction(): void
    {
        $auditor = new PromptOutputAuditor;
        $row = '- 0.2–2.0s | VISIBLE ACTION: wheel turns | SPEAKER: Narrator | LINE: “It turns.”';
        $first = self::fixture('COPY CLIP 1 PROMPT', [$row]);
        $fourth = self::fixture('COPY CLIP 4 PROMPT', [$row]);
        $before = $first."\n".$fourth;
        $this->assertTrue($auditor->preservesUnaffectedClips($before, $before, self::input(), [['clip' => 1]]));
        $this->assertFalse($auditor->preservesUnaffectedClips($before, $first."\n".str_replace('LITERAL SCENE', 'DIAGRAM', $fourth), self::input(), [['clip' => 1]]));
    }

    public function test_spoken_word_and_speaker_guard_allows_splits_but_rejects_rewrites_and_reordering(): void
    {
        $auditor = new PromptOutputAuditor;
        $before = self::fixture('COPY CLIP 1 PROMPT', ['- 0.2–2.5s | VISIBLE ACTION: wheel turns | SPEAKER: Narrator | LINE: “The wheel turns slowly.”']);
        $split = self::fixture('COPY CLIP 1 PROMPT', ['- 0.2–1.3s | VISIBLE ACTION: wheel turns | SPEAKER: Narrator | LINE: “The wheel”', '- 1.5–2.8s | VISIBLE ACTION: wheel slows | SPEAKER: Narrator | LINE: “turns slowly.”']);
        $this->assertTrue($auditor->preservesSpokenContent($before, $split, self::input()));
        $this->assertFalse($auditor->preservesSpokenContent($before, str_replace('slowly.', 'quickly.', $split), self::input()));
        $this->assertFalse($auditor->preservesSpokenContent($before, str_replace('SPEAKER: Narrator', 'SPEAKER: Other', $split), self::input()));
        $this->assertFalse($auditor->preservesSpokenContent($before, str_replace('0.2–1.3s', 'unparseable', $split), self::input()));
    }
}
