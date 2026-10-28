<?php

namespace Tests\Feature;

use App\Models\AiSetting;
use App\Services\PromptGenerationException;
use App\Services\PromptGenerationService;
use App\Services\PromptOutputAuditor;
use App\Services\PromptRepairService;
use Mockery;
use Tests\Unit\SpeechTimingTest;
use Illuminate\Http\Client\Factory;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use ReflectionMethod;
use Tests\TestCase;

class PromptPlanningPolicyTest extends TestCase
{
    private function invokePolicy(string $method, mixed ...$arguments): mixed
    {
        return (new ReflectionMethod(PromptGenerationService::class, $method))
            ->invoke(new PromptGenerationService, ...$arguments);
    }

    public function test_ai_planning_reserves_headroom_and_spills_complete_speech_into_honest_continuations(): void
    {
        $input = ['mode' => 'story', 'clip_count' => 'quality', 'clip_duration' => 'auto'];
        $system = $this->invokePolicy('systemInstructions', $input);
        $brief = $this->invokePolicy('buildCreativeBrief', $input, false);

        foreach (['do not stop at 7 or 8', 'add 2–3 seconds of useful visual headroom',
            'carry the remaining words into the next connected clip', 'Never exceed 10 seconds',
            'same voice identity across the chain', 'Type A direct continuation is the preferred handoff',
            'Type B/C are exceptions, not quotas', 'TIMING FLEXIBILITY',
            'Never authorize exceeding or silently changing CLIP DURATION'] as $instruction) {
            $this->assertStringContainsString($instruction, $system);
        }
        $this->assertStringContainsString('never clamp an overfull beat and rush its speech', $brief);
        $this->assertStringContainsString('Preferred handoff strategy:', $brief);
        $this->assertStringContainsString('Reallocate clips during planning', $brief);
    }

    public function test_fixed_settings_and_extensions_do_not_silently_grow(): void
    {
        foreach ([['mode' => 'story', 'clip_count' => '3', 'clip_duration' => '6'],
            ['mode' => 'extend', 'clip_count' => 'quality', 'extend_duration' => '6']] as $input) {
            $policy = $this->invokePolicy('continuityFirstPlanningInstructions', $input);
            $this->assertStringContainsString('selected manual duration is exact', $policy);
            $this->assertStringContainsString('never add 2–3 seconds to a fixed duration', $policy);
            $this->assertStringContainsString('Keep the fixed count or single extension exactly', $policy);
            $this->assertStringNotContainsString('AI-selected clip count is uncapped', $policy);
        }
    }

    public function test_one_shot_quality_correction_can_split_ai_counts_but_preserves_fixed_counts(): void
    {
        $arguments = ['Original brief', 'Draft', [['clip' => 1, 'message' => 'Opening beat and speech overrun']]];
        $quality = $this->invokePolicy('buildQualityRepairBrief', ...[...$arguments,
            ['mode' => 'story', 'clip_count' => 'quality']]);
        $manual = $this->invokePolicy('buildQualityRepairBrief', ...[...$arguments,
            ['mode' => 'story', 'clip_count' => '3']]);
        $this->assertStringContainsString('add meaningful consecutive clips if necessary', $quality);
        $this->assertStringContainsString('preserve exact spoken wording and order', $quality);
        $this->assertStringContainsString('Synchronize the overview, clip plan, total runtime, numbering', $quality);
        $this->assertStringContainsString('Preserve the fixed clip count', $manual);
        $this->assertStringNotContainsString('add meaningful consecutive clips if necessary', $manual);
    }

    public function test_response_budgets_are_configurable_without_turning_manual_choices_into_ai_limits(): void
    {
        config(['prompt_generation.output_tokens' => 12288, 'prompt_generation.quality_output_tokens' => 40000]);
        $this->assertSame(40000, $this->invokePolicy('outputTokenBudget', ['clip_count' => 'quality']));
        foreach ([['clip_count' => 'auto'], ['clip_count' => '8'], ['mode' => 'extend', 'clip_count' => 'quality']] as $input) {
            $this->assertSame(12288, $this->invokePolicy('outputTokenBudget', $input));
        }
        config(['prompt_generation.quality_output_tokens' => 999999]);
        $this->assertSame(65536, $this->invokePolicy('outputTokenBudget', ['clip_count' => 'quality']));
        config(['prompt_generation.quality_output_tokens' => -1]);
        $this->assertSame(1024, $this->invokePolicy('outputTokenBudget', ['clip_count' => 'quality']));
    }

    public function test_both_providers_receive_the_quality_response_budget(): void
    {
        config(['prompt_generation.quality_output_tokens' => 40000]);
        foreach (['gemini', 'openai'] as $provider) {
            Http::swap(new Factory);
            $text = '## PROJECT OVERVIEW'."\nA complete provider fixture, not rendered-video validation.";
            Http::fake(['*' => Http::response($provider === 'gemini'
                ? ['candidates' => [['finishReason' => 'STOP', 'content' => ['parts' => [['text' => $text]]]]]]
                : ['choices' => [['finish_reason' => 'stop', 'message' => ['content' => $text]]]])]);
            $settings = new AiSetting(['provider' => $provider, 'model' => 'test-model',
                'base_url' => 'https://example.test/v1', 'api_key' => 'test-only-secret']);
            $this->assertSame($text, (new PromptGenerationService)->generate($settings,
                ['mode' => 'story', 'clip_count' => 'quality', 'clip_duration' => 'auto']));
            Http::assertSent(fn (Request $request): bool => data_get($request->data(),
                $provider === 'gemini' ? 'generationConfig.maxOutputTokens' : 'max_tokens') === 40000);
            Http::assertSentCount(1);
        }
    }

    public function test_studio_rejects_a_shortened_ai_script_even_if_its_timing_would_pass(): void
    {
        $words = 'The tiny bee carries bright yellow pollen from this flower to another flower.';
        $draft = SpeechTimingTest::fixture('COPY CLIP 1 PROMPT', [
            '- 0.0–1.0s | VISIBLE ACTION: wheel turns | SPEAKER: Narrator | LINE: “'.$words.'”',
        ]);
        $rewritten = str_replace(['0.0–1.0s', $words], ['0.2–1.8s', 'It turns.'], $draft);
        Http::fake(['*' => Http::sequence()
            ->push(['candidates' => [['content' => ['parts' => [['text' => $draft]]]]]])
            ->push(['candidates' => [['content' => ['parts' => [['text' => $rewritten]]]]]])]);
        $settings = new AiSetting(['provider' => 'gemini', 'model' => 'test-model', 'api_key' => 'test-secret']);
        $result = (new PromptGenerationService)->generateWithQualityReview($settings,
            SpeechTimingTest::input('story', ['clip_duration' => '4']));
        $this->assertSame($draft, $result['content']);
        $this->assertSame('rejected_protected_speech_change', $result['quality_review']['repair_outcome']);
        $this->assertSame('needs_review', $result['quality_review']['status']);
        Http::assertSentCount(2);
    }

    public function test_contextual_overflow_proposal_is_not_misreported_as_a_completed_fix(): void
    {
        $settings = new AiSetting(['provider' => 'gemini', 'model' => 'test-model', 'api_key' => 'test-secret']);
        $draft = SpeechTimingTest::fixture('COPY CLIP 1 PROMPT', [
            '- 0.0–3.9s | VISIBLE ACTION: wheel turns | SPEAKER: Narrator | LINE: “'.str_repeat('word ', 40).'”',
        ]);
        $generation = Mockery::mock(PromptGenerationService::class);
        $generation->shouldReceive('requestSpeechTimingProposal')->once()->with($settings, Mockery::type('string'))
            ->andReturn('{"clips":[{"clip":1,"proposal":"SPLIT_REQUIRED"}]}');
        $result = (new PromptRepairService(new PromptOutputAuditor, $generation))->repair($settings, $draft, SpeechTimingTest::input());
        $this->assertSame($draft, $result['content']);
        $this->assertSame([], $result['repair_report']['fixed']);
        $this->assertNotEmpty($result['repair_report']['unresolved']);
        $this->assertStringContainsString('No split was applied', implode(' ', $result['repair_report']['notes']));
        $this->assertSame(2, PromptRepairService::MAX_MANUAL_ATTEMPTS);
    }

    public function test_truncated_provider_responses_are_never_accepted_as_complete_prompt_kits(): void
    {
        foreach (['gemini', 'openai'] as $provider) {
            Http::swap(new Factory);
            Http::fake(['*' => Http::response($provider === 'gemini'
                ? ['candidates' => [['finishReason' => 'MAX_TOKENS', 'content' => ['parts' => [['text' => 'Partial clip']]]]]]
                : ['choices' => [['finish_reason' => 'length', 'message' => ['content' => 'Partial clip']]]])]);
            $settings = new AiSetting(['provider' => $provider, 'model' => 'test-model',
                'base_url' => 'https://example.test/v1', 'api_key' => 'test-only-secret']);
            try {
                (new PromptGenerationService)->generate($settings, ['mode' => 'story', 'clip_count' => 'quality']);
                $this->fail('A truncated prompt kit must not be accepted.');
            } catch (PromptGenerationException $exception) {
                $this->assertStringContainsString('No partial response was accepted', $exception->getMessage());
            }
            Http::assertSentCount(1);
        }
    }
}
