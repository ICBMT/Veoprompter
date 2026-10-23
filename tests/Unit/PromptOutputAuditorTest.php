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
        $this->assertStringContainsString('does not exactly match', $this->issue($review, 'continuity_state_mismatch')['message']);
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
            '- 0.0–2.0s | VISIBLE ACTION: pollen transfers to a bee | SPEAKER: Narrator | LINE: “The bee carries pollen.”',
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
            '- 0.0–2.0s | VISIBLE ACTION: pollen transfers to a bee | SPEAKER: Narrator | LINE: “The bee carries pollen.”',
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
