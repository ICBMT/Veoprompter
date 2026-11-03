<?php

namespace Tests\Feature;

use App\Models\AiSetting;
use App\Models\User;
use App\Services\AiProviderSetupService;
use App\Support\ProjectSettings;
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
        $success = ['choices' => [['message' => ['content' => PromptQaCases::package()], 'finish_reason' => 'stop']]];
        Http::preventStrayRequests();
        Http::fake(['https://api.xkiro.com/v1/chat/completions' => Http::sequence()
            ->push($success)->push(['error' => ['message' => 'Do not echo upstream details or credentials']], 429)->push($success)]);
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
            && $r->hasHeader('Authorization', 'Bearer fake-key'));
    }
}
