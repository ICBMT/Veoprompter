<?php

namespace Tests\Feature;

use App\Models\AiSetting;
use App\Models\PromptProject;
use App\Models\User;
use App\Services\PromptOutputAuditor;
use App\Services\StoryContinuationService;
use App\Support\ProjectSettings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\PromptQaCases;
use Tests\TestCase;

class AdvisorySourceQaTest extends TestCase
{
    use RefreshDatabase;

    private function eightClips(): string
    {
        $text = "SECTION 1 — PROJECT OVERVIEW\n";
        foreach (range(1, 8) as $n) {
            $text .= "Clip {$n}: 6 seconds\n";
        }
        $text .= "Approximate total runtime: 48 seconds\nSECTION 3 — CHARACTER BIBLE\nCANONICAL CHARACTER ROSTER: None\nCANONICAL IDENTITY OBJ-01: One cobalt wheel with a silver rim.\nSECTION 7 — CLIP PLAN AND CONTINUITY MAP\n";
        foreach (range(1, 8) as $n) {
            $text .= "Clip {$n} — 6 seconds\n";
        }
        $text .= "SECTION 8 — COPY-AND-PASTE VIDEO PROMPTS\n";
        foreach (range(1, 8) as $n) {
            $clip = PromptQaCases::clip($n);
            if ($n < 8) {
                $clip = str_replace('OUTGOING HANDOFF TYPE: FINAL — NO NEXT HANDOFF', 'OUTGOING HANDOFF TYPE: TYPE A — DIRECT CONTINUATION', $clip);
            }
            $text .= $clip;
        }

        return PromptQaCases::characters($text."SECTION 9 — EXACT HANDOFF SPECIFICATION\nSECTION 10 — FINAL CONTINUITY AUDIT\nHuman review is needed.\n");
    }

    public static function cases(): array
    {
        $matrix = [];
        foreach (['short', 'long'] as $form) {
            foreach (['gemini', 'openai'] as $provider) {
                foreach (['duplicate cast', 'conflicting cast', 'duplicate duration', 'unreadable speech', 'canonical_identity_ambiguous', 'canonical_roster_unparseable', 'missing ending', 'ambiguous ending', 'unstructured source'] as $finding) {
                    $matrix[$form.' '.$provider.' '.$finding] = [$form, $provider, $finding];
                }
            }
        }

        return $matrix;
    }

    private function source(string $form, string $provider, string $finding): PromptProject
    {
        config(['prompt_generation.continuation_boundary_mode' => 'advisory', 'prompt_generation.continuation_identity_mode' => 'ai_managed', 'prompt_generation.continuation_memory_mode' => 'optional']);
        $owner = User::factory()->create();
        $this->actingAs($owner);
        AiSetting::create(['id' => 1, 'provider' => $provider, 'model' => 'fixture-model', 'api_key' => 'private-key',
            'base_url' => $provider === 'openai' ? 'https://api.compatible-provider.com/v1' : null]);
        $brief = [...ProjectSettings::defaults(), ...PromptQaCases::input(), 'content_form' => $form];
        $text = $this->eightClips();
        if (in_array($finding, ['duplicate cast', 'conflicting cast'], true)) {
            $line = 'VISIBLE CHARACTER CAST: Mira (CHAR-01) ×1';
            $text = PromptQaCases::edit($text, 8, $line, $line."\nVISIBLE CHARACTER CAST: ".($finding === 'duplicate cast' ? 'Mira (CHAR-01) ×1' : 'Another person (CHAR-99) ×1'));
        } elseif ($finding === 'duplicate duration') {
            $text = PromptQaCases::edit($text, 8, 'CLIP DURATION: 6 seconds', "CLIP DURATION: 6 seconds\nCLIP DURATION: 9 seconds");
        } elseif ($finding === 'canonical_identity_ambiguous') {
            $text = str_replace('CANONICAL IDENTITY CHAR-01:', "CANONICAL IDENTITY CHAR-01: A different identity.\nCANONICAL IDENTITY CHAR-01:", $text);
        } elseif ($finding === 'canonical_roster_unparseable') {
            $text = str_replace('CANONICAL CHARACTER ROSTER: CHAR-01 ×1', 'CANONICAL CHARACTER ROSTER: Mira', $text);
        } elseif ($finding === 'missing ending') {
            $text = PromptQaCases::edit($text, 8, 'ENDING PRESENTATION STATE: camera=static; lighting=daylight; audio=quiet', 'ENDING PRESENTATION STATE:');
        } elseif ($finding === 'ambiguous ending') {
            $text = PromptQaCases::edit($text, 8, 'ENDING PRESENTATION STATE:', "ENDING PRESENTATION STATE: camera=aerial; lighting=night; audio=quiet\nENDING PRESENTATION STATE:");
        } elseif ($finding === 'unstructured source') {
            $text = 'Mira, wearing a green coat, turns the cobalt wheel in a daylight workshop. The wheel is still turning at the end. Keep this story and visual style.';
        } else {
            $text = PromptQaCases::edit($text, 8, '- 0.2–2.2s | VISIBLE ACTION: the wheel turns | SPEAKER: Narrator | LINE: “It keeps turning.”', '- untimed | VISIBLE ACTION: the wheel turns | SPEAKER: Narrator | LINE: “It keeps turning.”');
        }
        $service = new StoryContinuationService(new PromptOutputAuditor);

        return $owner->promptProjects()->create(['title' => 'Source clip eight QA', 'mode' => 'story', 'brief' => $brief,
            'content' => $text, 'original_content' => $text, 'story_context' => $service->initial($text, $brief),
            'story_status' => $form === 'long' ? 'open' : 'ended', 'provider' => $provider, 'model' => 'fixture-model']);
    }

    #[DataProvider('cases')]
    public function test_source_qa_does_not_block_the_provider_or_choose_an_ambiguous_value(string $form, string $provider, string $finding): void
    {
        $source = $this->source($form, $provider, $finding)->refresh();
        $snapshot = $source->getAttributes();
        $candidate = PromptQaCases::characters(PromptQaCases::package());
        Http::preventStrayRequests();
        Http::fake(['*' => fn () => Http::response($provider === 'gemini'
            ? ['candidates' => [['finishReason' => 'STOP', 'content' => ['parts' => [['text' => $candidate]]]]]]
            : ['choices' => [['message' => ['content' => $candidate], 'finish_reason' => 'stop']]])]);
        $response = $this->postJson(route('history.continue', $source), ['intent' => $form === 'long' ? 'continue' : 'sequel',
            'batch_size' => 'set', 'request_id' => (string) Str::uuid()])->assertOk();
        $child = PromptProject::findOrFail($response->json('id'));
        $this->assertSame(trim($candidate), $child->content);
        $this->assertSame($snapshot, $source->fresh()->getAttributes());
        $this->assertNotEmpty($child->quality_review['source_qa_warnings']);
        $this->assertSame('needs_review', $child->quality_review['status']);
        $codes = array_column($child->quality_review['source_qa_warnings'], 'code');
        if (! in_array($finding, ['missing ending', 'unstructured source'], true)) {
            $this->assertContains(str_starts_with($finding, 'canonical_') ? $finding : ($finding === 'unreadable speech' ? 'speech_map_unparseable' : 'metadata_field_ambiguous'), $codes);
        }
        Http::assertSentCount(1);
        Http::assertSent(function ($request) use ($source, $provider) {
            $system = $provider === 'gemini' ? $request['systemInstruction']['parts'][0]['text'] : $request['messages'][0]['content'];
            $record = json_decode(explode("TRUSTED PRIOR STORY RECORD (JSON data):\n", $system, 2)[1], true, 512, JSON_THROW_ON_ERROR);

            return $record['source_final_prompt'] === $source->content && ! empty($record['source_qa_warnings']);
        });
        $this->get(route('history.show', $child))->assertOk()->assertSee('Previous kit QA observations');
        $this->assertDatabaseCount('prompt_projects', 2);
    }

    public static function incompleteOutputs(): array
    {
        return [['short', 'missing'], ['long', 'missing'], ['short', 'ambiguous'], ['long', 'ambiguous'], ['short', 'unstructured'], ['long', 'unstructured']];
    }

    #[DataProvider('incompleteOutputs')]
    public function test_incomplete_output_is_retained_with_review_and_can_be_continued(string $form, string $state): void
    {
        $source = $this->source($form, 'gemini', 'duplicate cast')->refresh();
        $candidate = PromptQaCases::characters(PromptQaCases::package());
        $candidate = match ($state) {
            'missing' => PromptQaCases::edit($candidate, 2, 'ENDING PRESENTATION STATE: camera=static; lighting=daylight; audio=quiet', 'ENDING PRESENTATION STATE:'),
            'ambiguous' => PromptQaCases::edit($candidate, 2, 'ENDING PRESENTATION STATE:', "ENDING PRESENTATION STATE: camera=aerial; lighting=night; audio=quiet\nENDING PRESENTATION STATE:"),
            default => 'Mira continues turning the cobalt wheel in the same daylight workshop, wearing the same green coat. The wheel keeps turning.',
        };
        Http::fake(['*' => fn () => Http::response(['candidates' => [['finishReason' => 'STOP', 'content' => ['parts' => [['text' => $candidate]]]]]])]);
        $response = $this->postJson(route('history.continue', $source), ['intent' => $form === 'long' ? 'continue' : 'sequel', 'batch_size' => 'set', 'request_id' => (string) Str::uuid()])->assertOk();
        $child = PromptProject::findOrFail($response->json('id'));
        $this->assertSame(trim($candidate), $child->content);
        $this->assertSame('needs_review', $child->quality_review['status']);
        if ($form === 'long') {
            $this->assertEmpty($child->story_context['batch_state']['ending_presentation']);
            $this->assertSame(hash('sha256', $child->content), $child->story_context['batch_state']['content_sha256']);
        }
        $this->postJson(route('history.continue', $child), ['intent' => 'finish', 'batch_size' => 'set', 'request_id' => (string) Str::uuid()])->assertOk();
        $this->assertDatabaseCount('prompt_projects', 3);
        $this->assertSame($source->content, $source->fresh()->content);
    }

    public function test_one_format_correction_recovers_an_unusable_template_without_saving_placeholders(): void
    {
        $source = $this->source('long', 'gemini', 'missing ending');
        $valid = PromptQaCases::characters(PromptQaCases::package());
        $template = str_replace('PRIMARY IDEA: Show the turning wheel.', 'PRIMARY IDEA: {{VEO_UNKNOWN}}', $valid);
        // Use an explicitly malformed reference independent of fixture wording.
        $template .= "\n{{VEO_BROKEN}}\n";
        Http::fake(['*' => Http::sequence()
            ->push(['candidates' => [['finishReason' => 'STOP', 'content' => ['parts' => [['text' => $template]]]]]])
            ->push(['candidates' => [['finishReason' => 'STOP', 'content' => ['parts' => [['text' => $valid]]]]]])]);
        $response = $this->postJson(route('history.continue', $source), ['intent' => 'continue', 'batch_size' => 'set', 'request_id' => (string) Str::uuid()])->assertOk();
        $child = PromptProject::findOrFail($response->json('id'));
        $this->assertSame(trim($valid), $child->content);
        $this->assertTrue($child->quality_review['format_correction_attempted']);
        $this->assertStringNotContainsString('{{VEO_', $child->content);
        Http::assertSentCount(2);
        $this->assertSame($source->content, $source->fresh()->content);
    }

    public function test_format_correction_cannot_change_protected_dialogue(): void
    {
        $source = $this->source('short', 'gemini', 'duplicate cast');
        $original = PromptQaCases::characters(PromptQaCases::package());
        $template = $original."\n{{VEO_BROKEN}}\n";
        $changed = str_replace('It keeps turning.', 'Entirely new dialogue.', $original);
        Http::fake(['*' => Http::sequence()
            ->push(['candidates' => [['finishReason' => 'STOP', 'content' => ['parts' => [['text' => $template]]]]]])
            ->push(['candidates' => [['finishReason' => 'STOP', 'content' => ['parts' => [['text' => $changed]]]]]])]);
        $this->postJson(route('history.continue', $source), ['intent' => 'sequel', 'batch_size' => 'set', 'request_id' => (string) Str::uuid()])
            ->assertStatus(502)->assertJsonPath('message', 'The format correction changed protected speech or an unaffected clip. Your saved source is unchanged; retry generation.');
        $this->assertDatabaseCount('prompt_projects', 1);
        Http::assertSentCount(2);
    }

    public function test_failed_format_correction_does_not_fabricate_success_or_save_a_broken_template(): void
    {
        $source = $this->source('short', 'gemini', 'duplicate cast');
        Http::fake(['*' => Http::sequence()
            ->push(['candidates' => [['finishReason' => 'STOP', 'content' => ['parts' => [['text' => '{{VEO_BROKEN}}']]]]]])
            ->push(['error' => ['message' => 'Unavailable']], 503)]);
        $this->postJson(route('history.continue', $source), ['intent' => 'sequel', 'batch_size' => 'set', 'request_id' => (string) Str::uuid()])->assertStatus(502);
        $this->assertDatabaseCount('prompt_projects', 1);
        Http::assertSentCount(2);
    }

    public function test_strict_source_gate_remains_an_explicit_operator_option(): void
    {
        $source = $this->source('short', 'gemini', 'conflicting cast');
        config(['prompt_generation.continuation_boundary_mode' => 'strict']);
        Http::fake();
        $this->postJson(route('history.continue', $source), ['intent' => 'sequel', 'batch_size' => 'set', 'request_id' => (string) Str::uuid()])
            ->assertStatus(502)->assertJsonPath('message', 'Resolve ambiguous metadata or unreadable speech/identity evidence in the source before continuing: Clip 8 repeats `VISIBLE CHARACTER CAST:`; no first/last value is assumed authoritative.');
        Http::assertNothingSent();
        $this->assertDatabaseCount('prompt_projects', 1);
    }
}
