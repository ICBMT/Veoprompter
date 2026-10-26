<?php

namespace Tests\Feature;

use App\Models\PromptProject;
use App\Models\User;
use App\Services\PromptOutputAuditor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PromptRepairTest extends TestCase
{
    use RefreshDatabase;

    public function test_owner_can_auto_fix_a_saved_run_and_restore_its_unchanged_original(): void
    {
        $owner = User::factory()->create();
        $previous = 'cast=None; object=glass vial on bench; level=half full; environment=lab';
        $reordered = 'environment=lab; level=half full; object=glass vial on bench; cast=None';
        $content = $this->clip(1, 'INITIAL CLIP — NO PREVIOUS HANDOFF', 'TYPE A — DIRECT CONTINUATION', $previous, $previous)
            ."\n".$this->clip(2, 'TYPE A — DIRECT CONTINUATION', 'FINAL — NO NEXT HANDOFF', $reordered, $reordered);
        $run = $this->savedRun($owner, $content, ['clip_count' => 2]);

        $this->actingAs($owner)
            ->postJson(route('history.repair', $run))
            ->assertOk()
            ->assertJsonPath('repair_report.applied', true)
            ->assertJsonPath('repair_report.fixed.0.code', 'continuity_state_mismatch')
            ->assertJsonPath('quality_review.status', 'passed')
            ->assertJsonPath('manual_attempts', 1)
            ->assertJsonPath('can_restore_original', true);

        $repaired = $run->fresh();
        $this->assertSame($content, $repaired->original_content);
        $this->assertStringContainsString('OPENING CONTINUITY STATE: '.$previous, $repaired->content);
        $this->assertSame('repair', $repaired->repair_history[0]['action']);
        $this->assertSame('completed', $repaired->repair_history[0]['status']);

        $this->postJson(route('history.restore', $repaired))
            ->assertOk()
            ->assertJsonPath('repair_report.outcome', 'restored_original')
            ->assertJsonPath('content', $content)
            ->assertJsonPath('manual_attempts', 1)
            ->assertJsonPath('can_restore_original', false);

        $restored = $run->fresh();
        $this->assertSame($content, $restored->content);
        $this->assertSame($content, $restored->original_content);
        $this->assertSame('restore', $restored->repair_history[1]['action']);

        $this->get(route('history.show', $restored))
            ->assertOk()
            ->assertSee('Compare with the original generated prompt')
            ->assertSee('Auto-Fix Issues')
            ->assertSee('Handoff state relationships')
            ->assertSee('compatible wording')
            ->assertSee('data-prompt-run-id="'.$restored->id.'"', false);
    }

    public function test_repair_and_restore_endpoints_are_owner_scoped_and_do_not_accept_prompt_text(): void
    {
        $owner = User::factory()->create();
        $otherUser = User::factory()->create();
        $run = $this->savedRun($owner, $this->validSingleClip(), ['clip_count' => 'auto']);

        $this->actingAs($otherUser)
            ->postJson(route('history.repair', $run))
            ->assertNotFound();

        $this->actingAs($otherUser)
            ->postJson(route('history.restore', $run))
            ->assertNotFound();

        $this->actingAs($owner)
            ->postJson(route('history.repair', $run), ['content' => 'untrusted replacement prompt'])
            ->assertStatus(422)
            ->assertJsonPath('message', 'Prompt text is never accepted by the repair endpoint.');

        $this->assertSame($run->content, $run->fresh()->content);
        $this->assertSame([], $run->fresh()->repair_history);
    }

    public function test_saved_run_auto_fix_persists_synchronized_overview_clip_duration_and_total_runtime(): void
    {
        $owner = User::factory()->create();
        $state = 'cast=None; object=bee over flower; stage=pollen transfer; environment=garden';
        $presentation = 'camera=macro; lighting=daylight; audio=soft garden ambience';
        $content = "## PROJECT OVERVIEW\n- Clip 1 — 4 seconds\n- Approximate total runtime: 4 seconds\n\n"
            ."## SECTION 7 — CLIP PLAN AND CONTINUITY MAP\n- Clip 1 — Gather pollen\n  - Exact duration: 4 seconds\n\n## SECTION 8 — COPY-AND-PASTE VIDEO PROMPTS\n"
            .$this->clip(1, 'INITIAL CLIP — NO PREVIOUS HANDOFF', 'FINAL — NO NEXT HANDOFF', $state, $state);
        $content = str_replace(
            '- 0.0–2.0s | VISIBLE ACTION: the seed settles into soil | SPEAKER: Narrator | LINE: “The bee carries pollen.”',
            '- 0.0–2.0s | VISIBLE ACTION: the bee gathers pollen | SPEAKER: Narrator | LINE: “The bee carries pollen.”' . "\n"
                . '- 1.5–2.5s | VISIBLE ACTION: the bee reaches another flower | SPEAKER: Narrator | LINE: “It reaches another flower.”',
            $content,
        );
        $content = str_replace(
            'OPENING PRESENTATION STATE: camera=medium; lighting=soft daylight; audio=quiet ambience',
            'OPENING PRESENTATION STATE: '.$presentation,
            $content,
        );
        $content = str_replace(
            'ENDING PRESENTATION STATE: camera=medium; lighting=soft daylight; audio=quiet ambience',
            'ENDING PRESENTATION STATE: '.$presentation,
            $content,
        );
        $run = $this->savedRun($owner, $content, ['clip_count' => 'auto']);

        $this->actingAs($owner)
            ->postJson(route('history.repair', $run))
            ->assertOk()
            ->assertJsonPath('repair_report.applied', true)
            ->assertJsonPath('quality_review.status', 'passed')
            ->assertJsonPath('manual_attempts', 1);

        $repaired = $run->fresh();
        $this->assertSame($content, $repaired->original_content);
        $this->assertStringContainsString('CLIP DURATION: 5 seconds', $repaired->content);
        $this->assertStringContainsString('- Clip 1 — 5 seconds', $repaired->content);
        $this->assertStringContainsString('Exact duration: 5 seconds', $repaired->content);
        $this->assertStringContainsString('- Approximate total runtime: 5 seconds', $repaired->content);
        $this->assertStringContainsString('“The bee carries pollen.”', $repaired->content);
        $this->assertStringContainsString('“It reaches another flower.”', $repaired->content);
        $this->assertSame([], $repaired->quality_review['issues']);
    }

    public function test_legacy_saved_runs_without_a_stored_review_are_audited_and_offer_auto_fix(): void
    {
        $owner = User::factory()->create();
        $state = 'cast=None; object=seed in soil; stage=planted; environment=greenhouse';
        $different = 'cast=None; object=seed in soil; stage=sprouted; environment=greenhouse';
        $content = $this->clip(1, 'INITIAL CLIP — NO PREVIOUS HANDOFF', 'TYPE A — DIRECT CONTINUATION', $state, $state)
            ."\n".$this->clip(2, 'TYPE A — DIRECT CONTINUATION', 'FINAL — NO NEXT HANDOFF', $different, $different);
        $run = $this->savedRun($owner, $content, ['clip_count' => 2]);
        $run->quality_review = null;
        $run->save();

        $this->actingAs($owner)
            ->get(route('history.show', $run))
            ->assertOk()
            ->assertSee('Auto-Fix Issues')
            ->assertSee('Clip 2 opening scene state does not exactly match Clip 1 ending state.');
    }

    public function test_unsupported_qa_findings_are_reported_without_consuming_a_repair_attempt(): void
    {
        $owner = User::factory()->create();
        $content = str_replace('SPEAKER: Narrator', 'SPEAKER: ', $this->validSingleClip());
        $run = $this->savedRun($owner, $content, ['clip_count' => 'auto']);

        $this->actingAs($owner)
            ->postJson(route('history.repair', $run))
            ->assertOk()
            ->assertJsonPath('repair_report.outcome', 'no_supported_issues')
            ->assertJsonPath('repair_report.attempted', false)
            ->assertJsonPath('repair_report.unresolved.0.code', 'speech_map_incomplete')
            ->assertJsonPath('manual_attempts', 0)
            ->assertJsonPath('can_repair', false);

        $this->assertSame($content, $run->fresh()->content);
        $this->assertSame([], $run->fresh()->repair_history);
        $this->get(route('history.show', $run))
            ->assertOk()
            ->assertSee('Manual review needed');
    }

    public function test_manual_repair_attempts_are_capped_at_two_and_restore_does_not_increase_the_repair_count(): void
    {
        $owner = User::factory()->create();
        $prior = 'object=raw steel panel; stage=unformed; location=press table; environment=factory';
        $next = 'object=finished car body; stage=painted; location=assembly bay; environment=factory';
        $content = $this->clip(1, 'INITIAL CLIP — NO PREVIOUS HANDOFF', 'TYPE C — NARRATIVE / TEMPORAL TRANSITION', $prior, $prior)
            ."\n".$this->clip(2, 'TYPE C — NARRATIVE / TEMPORAL TRANSITION', 'FINAL — NO NEXT HANDOFF', $next, $next);
        $run = $this->savedRun($owner, $content, ['clip_count' => 2]);
        $url = route('history.repair', $run);

        $this->actingAs($owner)->postJson($url)->assertOk()->assertJsonPath('manual_attempts', 1);
        $this->postJson($url)->assertOk()->assertJsonPath('manual_attempts', 2)->assertJsonPath('can_repair', false);
        $this->postJson($url)->assertStatus(409)->assertJsonPath('manual_attempts', 2);

        $this->postJson(route('history.restore', $run))->assertOk()->assertJsonPath('manual_attempts', 2);
        $this->assertSame(2, \App\Services\PromptRepairService::manualAttempts($run->fresh()->repair_history));
    }

    private function savedRun(User $owner, string $content, array $input): PromptProject
    {
        $input = array_merge([
            'mode' => 'story',
            'video_type' => 'cinematic-story',
            'clip_count' => 'auto',
            'clip_duration' => 'auto',
            'narration' => '1',
        ], $input);

        return $owner->promptProjects()->create([
            'title' => 'Repair fixture',
            'mode' => 'story',
            'brief' => $input,
            'content' => $content,
            'original_content' => $content,
            'repair_history' => [],
            'quality_review' => (new PromptOutputAuditor)->audit($content, $input),
            'provider' => 'gemini',
            'model' => 'gemini-test-model',
            'reference_frame_attached' => false,
        ]);
    }

    private function validSingleClip(): string
    {
        $state = 'cast=None; object=seed; stage=planted; environment=greenhouse';

        return $this->clip(1, 'INITIAL CLIP — NO PREVIOUS HANDOFF', 'FINAL — NO NEXT HANDOFF', $state, $state);
    }

    private function clip(
        int $number,
        string $incoming,
        string $outgoing,
        string $openingState,
        string $endingState,
    ): string {
        return implode("\n", [
            'COPY CLIP '.$number.' PROMPT',
            'TRANSITION TYPE: '.$incoming,
            'VISIBLE CHARACTER CAST: None',
            'CHARACTER IDENTITY LOCK: None — no character is present in this shot.',
            'CLIP DURATION: 4 seconds',
            'PRIMARY IDEA: One visible action advances the beat.',
            'VISUAL REPRESENTATION: LITERAL SCENE',
            'VISIBLE CAUSE → ACTION → RESULT: A clear cause produces one visible action and result.',
            'OPENING CONTINUITY STATE: '.$openingState,
            'ENDING CONTINUITY STATE: '.$endingState,
            'OPENING PRESENTATION STATE: camera=medium; lighting=soft daylight; audio=quiet ambience',
            'ENDING PRESENTATION STATE: camera=medium; lighting=soft daylight; audio=quiet ambience',
            'SPEECH/VISUAL MAP:',
            '- 0.0–2.0s | VISIBLE ACTION: the seed settles into soil | SPEAKER: Narrator | LINE: “The bee carries pollen.”',
            'OUTGOING HANDOFF TYPE: '.$outgoing,
        ]);
    }
}
