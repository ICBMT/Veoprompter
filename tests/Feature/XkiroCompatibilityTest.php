<?php

namespace Tests\Feature;

use App\Models\AiSetting;
use App\Models\User;
use App\Services\AiProviderSetupService;
use App\Services\PromptGenerationException;
use App\Services\ProviderTextStream;
use App\Support\ProjectSettings;
use GuzzleHttp\Promise\FulfilledPromise;
use GuzzleHttp\Psr7\FnStream;
use GuzzleHttp\Psr7\Response;
use GuzzleHttp\Psr7\Utils;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\Support\PromptQaCases;
use Tests\TestCase;

class XkiroCompatibilityTest extends TestCase
{
    use RefreshDatabase;

    public function test_catalogue_preserves_vendor_ids_and_labels_access_tiers_without_claiming_entitlement(): void
    {
        Http::preventStrayRequests();
        Http::fake(['https://api.xkiro.com/v1/models' => Http::response(['data' => [
            ['id' => 'vendor/free-model', 'access_tier' => 'free'],
            ['id' => 'vendor/paid-model', 'access_tier' => 'paid'],
            ['id' => 'vendor/premium-model', 'access_tier' => 'premium'],
        ]])]);
        $models = app(AiProviderSetupService::class)->models('openai', 'https://api.xkiro.com/v1', 'fake-key');
        $this->assertSame([
            ['id' => 'vendor/free-model', 'label' => 'vendor/free-model — Free tier'],
            ['id' => 'vendor/paid-model', 'label' => 'vendor/paid-model — Paid tier'],
            ['id' => 'vendor/premium-model', 'label' => 'vendor/premium-model — Premium tier'],
        ], $models);
        Http::assertSent(fn ($r) => $r->hasHeader('Authorization', 'Bearer fake-key'));
    }

    public function test_connection_probe_uses_openai_dialect_and_full_model_id(): void
    {
        Http::preventStrayRequests();
        Http::fake(['https://api.xkiro.com/v1/chat/completions' => Http::response([
            'choices' => [['message' => ['content' => 'Connection passed.'], 'finish_reason' => 'stop']],
        ])]);
        $this->assertSame('Connection passed.', app(AiProviderSetupService::class)->testConnection(
            'openai', 'https://api.xkiro.com/v1', 'vendor/free-model', 'fake-key',
        ));
        Http::assertSent(fn ($r) => $r['model'] === 'vendor/free-model'
            && $r->hasHeader('Authorization', 'Bearer fake-key') && isset($r['messages']));
    }

    public function test_quota_failure_after_success_keeps_history_and_allows_recovery(): void
    {
        $owner = User::factory()->create();
        $this->actingAs($owner);
        AiSetting::create(['id' => 1, 'provider' => 'openai', 'base_url' => 'https://api.xkiro.com/v1',
            'model' => 'vendor/free-model', 'api_key' => 'fake-key']);
        $project = $owner->studioProjects()->create(['name' => 'xKiro contract',
            'settings' => [...ProjectSettings::defaults(), 'video_type' => 'custom']]);
        $success = $this->stream(PromptQaCases::package());
        Http::preventStrayRequests();
        Http::fake(['https://api.xkiro.com/v1/chat/completions' => Http::sequence()
            ->push($success, 200, ['Content-Type' => 'text/event-stream'])->push(['error' => ['message' => 'Do not echo upstream details or credentials']], 429)->push($success, 200, ['Content-Type' => 'text/event-stream'])]);
        $body = ['mode' => 'story', 'topic' => 'A wheel'];
        $this->postJson(route('studio.generate', $project), $body)->assertOk()->assertDontSee('fake-key');
        $original = $owner->promptProjects()->firstOrFail();
        $snapshot = $original->getAttributes();
        $this->postJson(route('studio.generate', $project), $body)->assertStatus(502)
            ->assertDontSee('fake-key')->assertDontSee('Do not echo upstream');
        $this->assertDatabaseCount('prompt_projects', 1);
        $this->assertSame($snapshot, $original->fresh()->getAttributes());
        $this->postJson(route('studio.generate', $project), $body)->assertOk();
        $this->assertDatabaseCount('prompt_projects', 2);
        Http::assertSentCount(3);
        Http::assertSent(fn ($r) => $r['model'] === 'vendor/free-model' && $r['max_tokens'] > 0
            && $r->hasHeader('Authorization', 'Bearer fake-key') && $r['stream'] === true);
    }

    public function test_stream_read_errors_close_the_connection_and_hide_transport_details(): void
    {
        $closed = false;
        $body = FnStream::decorate(Utils::streamFor('pending'), [
            'read' => function () {
                throw new \RuntimeException('PRIVATE-TRANSPORT-DETAILS');
            },
            'close' => function () use (&$closed) {
                $closed = true;
            },
        ]);
        Http::preventStrayRequests();
        Http::fake(['https://api.xkiro.com/v1/chat/completions' => new FulfilledPromise(
            new Response(200, ['Content-Type' => 'text/event-stream'], $body),
        )]);
        try {
            app(ProviderTextStream::class)->request('https://api.xkiro.com/v1/chat/completions', 'fake-key', ['model' => 'vendor/model']);
            $this->fail('An unreadable stream must not be accepted.');
        } catch (PromptGenerationException $exception) {
            $this->assertStringContainsString('interrupted or timed out', $exception->getMessage());
            $this->assertStringNotContainsString('PRIVATE-TRANSPORT', $exception->getMessage());
        }
        $this->assertTrue($closed);
        Http::assertSentCount(1);
    }

    private function stream(string $content, string $finish = 'stop'): string
    {
        return 'data: '.json_encode(['choices' => [['index' => 0, 'delta' => ['content' => $content], 'finish_reason' => null]]])."\n\n"
            .'data: '.json_encode(['choices' => [['index' => 0, 'delta' => [], 'finish_reason' => $finish]]])."\n\n"
            ."data: [DONE]\n\n";
    }

    public function test_interrupted_stream_is_not_saved_or_automatically_retried(): void
    {
        $this->assertRejectedStream(str_replace("data: [DONE]\n\n", '', $this->stream(PromptQaCases::package())));
    }

    public function test_streamed_truncation_is_rejected_before_persistence(): void
    {
        $this->assertRejectedStream($this->stream(PromptQaCases::package(), 'length'));
    }

    public function test_error_inside_http_200_stream_is_not_echoed_or_saved(): void
    {
        $this->assertRejectedStream('data: '.json_encode(['error' => ['message' => 'UPSTREAM-SECRET']])."\n\ndata: [DONE]\n\n");
    }

    private function assertRejectedStream(string $stream): void
    {
        $owner = User::factory()->create();
        $this->actingAs($owner);
        AiSetting::create(['id' => 1, 'provider' => 'openai', 'base_url' => 'https://api.xkiro.com/v1',
            'model' => 'vendor/free-model', 'api_key' => 'fake-key']);
        $project = $owner->studioProjects()->create(['name' => 'Stream rejection',
            'settings' => [...ProjectSettings::defaults(), 'video_type' => 'custom']]);
        Http::preventStrayRequests();
        Http::fake(['https://api.xkiro.com/v1/chat/completions' => Http::response($stream, 200, ['Content-Type' => 'text/event-stream'])]);
        $this->postJson(route('studio.generate', $project), ['mode' => 'story', 'topic' => 'A wheel'])
            ->assertStatus(502)->assertDontSee('UPSTREAM-SECRET')->assertDontSee('fake-key');
        $this->assertDatabaseCount('prompt_projects', 0);
        Http::assertSentCount(1);
    }
}
