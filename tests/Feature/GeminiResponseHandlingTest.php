<?php

namespace Tests\Feature;

use App\Models\AiSetting;
use App\Models\User;
use App\Services\AiProviderSetupException;
use App\Services\AiProviderSetupService;
use App\Support\ProjectSettings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\PromptQaCases;
use Tests\TestCase;

class GeminiResponseHandlingTest extends TestCase
{
    use RefreshDatabase;

    private function project()
    {
        $owner = User::factory()->create();
        $this->actingAs($owner);
        // An exact requested ID in a mocked provider contract, not proof of live availability.
        AiSetting::create(['id' => 1, 'provider' => 'gemini', 'model' => 'gemini-3.5-flash-lite', 'api_key' => 'fake-test-key']);

        return $owner->studioProjects()->create(['name' => 'Provider response checks', 'settings' => [...ProjectSettings::defaults(), 'video_type' => 'custom']]);
    }

    private function response(array $parts, string $reason = 'STOP'): array
    {
        return ['candidates' => [['finishReason' => $reason, 'content' => ['parts' => $parts]]]];
    }

    public function test_a_quick_empty_success_is_retried_once_through_the_application(): void
    {
        $project = $this->project();
        Http::fakeSequence()->push($this->response([]))->push($this->response([['text' => PromptQaCases::package()]]));
        $this->postJson(route('studio.generate', $project), ['mode' => 'story', 'topic' => 'A wheel'])
            ->assertOk()->assertJsonPath('saved_to_history', true);
        Http::assertSentCount(2);
        Http::assertSent(fn ($r) => str_contains($r->url(), '/gemini-3.5-flash-lite:streamGenerateContent?alt=sse') && $r->hasHeader('x-goog-api-key', 'fake-test-key'));
        $this->assertDatabaseCount('prompt_projects', 1);
    }

    public function test_repeated_empty_responses_are_bounded_and_do_not_save_a_run(): void
    {
        $project = $this->project();
        Http::fake(['*' => fn () => Http::response($this->response([]))]);
        $this->postJson(route('studio.generate', $project), ['mode' => 'story', 'topic' => 'A wheel'])
            ->assertStatus(502)->assertJsonPath('message', 'The AI provider returned no final text after two attempts. No output was accepted. Check the selected text-generation model, provider availability and quota in the Control room.');
        Http::assertSentCount(2);
        $this->assertDatabaseCount('prompt_projects', 0);
    }

    public function test_thought_parts_are_not_returned_or_persisted_as_the_prompt(): void
    {
        $project = $this->project();
        Http::fake(['*' => fn () => Http::response($this->response([
            ['thought' => true, 'text' => 'PRIVATE REASONING MUST NOT BE A PROMPT'],
            ['text' => PromptQaCases::package()],
        ]))]);
        $response = $this->postJson(route('studio.generate', $project), ['mode' => 'story', 'topic' => 'A wheel'])->assertOk();
        $this->assertStringNotContainsString('PRIVATE REASONING', $response->json('content'));
        $this->assertSame(trim(PromptQaCases::package()), $project->user->promptProjects()->firstOrFail()->content);
        Http::assertSentCount(1);
    }

    public static function stoppedResponses(): array
    {
        $candidate = static fn (string $reason): array => ['candidates' => [[
            'finishReason' => $reason, 'content' => ['parts' => [['text' => 'Partial output']]],
        ]]];

        return [
            'blocked prompt' => [['promptFeedback' => ['blockReason' => 'SAFETY']], 'blocked the request'],
            'safety stop' => [$candidate('SAFETY'), 'did not finish'],
            'truncated' => [$candidate('MAX_TOKENS'), 'response-length limit'],
        ];
    }

    #[DataProvider('stoppedResponses')]
    public function test_blocked_and_partial_responses_are_not_retried_or_saved(array $body, string $message): void
    {
        $project = $this->project();
        Http::fake(['*' => fn () => Http::response($body)]);
        $response = $this->postJson(route('studio.generate', $project), ['mode' => 'story', 'topic' => 'A wheel'])->assertStatus(502);
        $this->assertStringContainsString($message, $response->json('message'));
        Http::assertSentCount(1);
        $this->assertDatabaseCount('prompt_projects', 0);
    }

    public static function nonFinalResponses(): array
    {
        return [
            'thoughts only' => [['candidates' => [['finishReason' => 'STOP', 'content' => ['parts' => [['thought' => true, 'text' => 'PRIVATE THOUGHT']]]]]]],
            'malformed parts' => [['candidates' => [['finishReason' => 'STOP', 'content' => ['parts' => 'not a text-parts array']]]]],
            'no candidate' => [[]],
        ];
    }

    #[DataProvider('nonFinalResponses')]
    public function test_non_final_payloads_never_become_saved_prompts(array $body): void
    {
        $project = $this->project();
        Http::fake(['*' => fn () => Http::response($body)]);
        $response = $this->postJson(route('studio.generate', $project), ['mode' => 'story', 'topic' => 'A wheel'])->assertStatus(502);
        $this->assertStringNotContainsString('PRIVATE THOUGHT', $response->getContent());
        $this->assertStringContainsString('no final text after two attempts', $response->json('message'));
        Http::assertSentCount(2);
        $this->assertDatabaseCount('prompt_projects', 0);
    }

    public function test_connection_test_does_not_misreport_thought_only_output_as_success(): void
    {
        Http::fake(['*' => fn () => Http::response($this->response([['thought' => true, 'text' => 'Not a final answer']]))]);
        $this->expectException(AiProviderSetupException::class);
        $this->expectExceptionMessage('returned no text');
        (new AiProviderSetupService)->testConnection('gemini', null, 'gemini-3.5-flash-lite', 'fake-test-key');
    }
}
