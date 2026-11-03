<?php

namespace Tests\Feature;

use App\Models\AiSetting;
use App\Models\User;
use App\Support\ProjectSettings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\PromptQaCases;
use Tests\TestCase;

class ProviderTransportTest extends TestCase
{
    use RefreshDatabase;

    public static function providers(): array
    {
        return [
            'Gemini native SSE' => ['gemini', 'gemini-text-fixture', null, true],
            'Gemini complete JSON' => ['gemini', 'gemini-text-fixture', null, false],
            'OpenAI chat SSE' => ['openai', 'gpt-4.1-mini', 'https://api.openai.com/v1', true],
            'OpenAI reasoning SSE' => ['openai', 'o3', 'https://api.openai.com/v1', true],
            'OpenAI GPT5 JSON' => ['openai', 'gpt-5', 'https://api.openai.com/v1', false],
            'custom gateway SSE' => ['openai', 'vendor/reasoner', 'https://api.compatible-provider.com/v1', true],
            'custom gateway JSON' => ['openai', 'vendor/model', 'https://api.compatible-provider.com/v1', false],
            'xKiro SSE' => ['openai', 'vendor/model', 'https://api.xkiro.com/v1', true],
        ];
    }

    #[DataProvider('providers')]
    public function test_complete_text_responses_work_across_supported_protocols(string $provider, string $model, ?string $baseUrl, bool $sse): void
    {
        $owner = User::factory()->create();
        $this->actingAs($owner);
        AiSetting::create(['id' => 1, 'provider' => $provider, 'model' => $model, 'base_url' => $baseUrl, 'api_key' => 'fake-private-key']);
        $project = $owner->studioProjects()->create(['name' => 'Transport matrix', 'settings' => [...ProjectSettings::defaults(), 'video_type' => 'custom']]);
        $text = PromptQaCases::package();
        $frame = $provider === 'gemini'
            ? ['candidates' => [['finishReason' => 'STOP', 'content' => ['parts' => [['text' => $text]]]]]]
            : ['choices' => [['index' => 0, 'delta' => ['content' => $text], 'message' => ['content' => $text], 'finish_reason' => 'stop']]];
        $body = $sse ? 'data: '.json_encode($frame)."\n\n".($provider === 'gemini' ? '' : "data: [DONE]\n\n") : $frame;
        Http::preventStrayRequests();
        Http::fake(['*' => fn () => Http::response($body, 200, ['Content-Type' => $sse ? 'text/event-stream' : 'application/json'])]);
        $this->postJson(route('studio.generate', $project), ['mode' => 'story', 'topic' => 'A wheel'])
            ->assertOk()->assertJsonPath('saved_to_history', true)->assertDontSee('fake-private-key');
        $this->assertDatabaseCount('prompt_projects', 1);
        Http::assertSentCount(1); // JSON fallback consumes the same response, never resends.
        Http::assertSent(function ($request) use ($provider, $model, $baseUrl) {
            if ($provider === 'gemini') {
                return str_contains($request->url(), ':streamGenerateContent?alt=sse')
                    && $request->hasHeader('x-goog-api-key', 'fake-private-key');
            }
            $reasoning = $baseUrl === 'https://api.openai.com/v1' && in_array($model, ['o3', 'gpt-5'], true);

            return $request['stream'] === true && $request['model'] === $model
                && $request->hasHeader('Authorization', 'Bearer fake-private-key')
                && ($reasoning ? isset($request['max_completion_tokens']) && ! isset($request['temperature']) : isset($request['max_tokens']));
        });
    }

    public function test_streaming_can_be_disabled_without_retrying_an_incompatible_gateway(): void
    {
        config(['prompt_generation.stream' => false]);
        $owner = User::factory()->create();
        $this->actingAs($owner);
        AiSetting::create(['id' => 1, 'provider' => 'openai', 'model' => 'vendor/model', 'base_url' => 'https://api.compatible-provider.com/v1', 'api_key' => 'fake-key']);
        $project = $owner->studioProjects()->create(['name' => 'Legacy gateway', 'settings' => [...ProjectSettings::defaults(), 'video_type' => 'custom']]);
        Http::fake(['*' => fn () => Http::response(['choices' => [['message' => ['content' => PromptQaCases::package()], 'finish_reason' => 'stop']]])]);
        $this->postJson(route('studio.generate', $project), ['mode' => 'story', 'topic' => 'A wheel'])->assertOk();
        Http::assertSentCount(1);
        Http::assertSent(fn ($r) => ! isset($r['stream']));
    }
}
