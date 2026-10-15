<?php

namespace Tests\Feature;

use App\Models\AiSetting;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request as ClientRequest;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class AiProviderSetupTest extends TestCase
{
    use RefreshDatabase;

    public function test_model_discovery_uses_a_temporary_gemini_key_and_only_lists_generation_models(): void
    {
        Http::fake([
            'https://generativelanguage.googleapis.com/v1beta/models*' => Http::response([
                'models' => [
                    [
                        'name' => 'models/gemini-2.5-flash',
                        'displayName' => 'Gemini 2.5 Flash',
                        'supportedGenerationMethods' => ['generateContent'],
                    ],
                    [
                        'name' => 'models/text-embedding-004',
                        'displayName' => 'Text Embedding',
                        'supportedGenerationMethods' => ['embedContent'],
                    ],
                ],
            ], 200),
        ]);

        $response = $this->actingAs($this->administrator())->postJson(route('admin.models'), [
            'provider' => 'gemini',
            'api_key' => 'temporary-gemini-secret',
        ]);

        $response->assertOk()
            ->assertJsonCount(1, 'models')
            ->assertJsonPath('models.0.id', 'gemini-2.5-flash')
            ->assertJsonPath('models.0.label', 'Gemini 2.5 Flash — gemini-2.5-flash')
            ->assertDontSee('temporary-gemini-secret');

        Http::assertSent(fn (ClientRequest $request): bool => $request->url() === 'https://generativelanguage.googleapis.com/v1beta/models?pageSize=100'
            && $request->hasHeader('x-goog-api-key', 'temporary-gemini-secret'));
        $this->assertDatabaseCount('ai_settings', 0);
    }

    public function test_openai_compatible_model_discovery_uses_the_selected_public_endpoint(): void
    {
        Http::fake([
            'https://api.compatible-provider.com/v1/models' => Http::response([
                'data' => [
                    ['id' => 'gpt-4.1-mini'],
                    ['id' => 'text-embedding-3-small'],
                ],
            ], 200),
        ]);

        $this->actingAs($this->administrator())->postJson(route('admin.models'), [
            'provider' => 'openai',
            'base_url' => 'https://api.compatible-provider.com/v1',
            'api_key' => 'temporary-compatible-secret',
        ])->assertOk()
            ->assertJsonCount(2, 'models')
            ->assertJsonFragment(['id' => 'gpt-4.1-mini', 'label' => 'gpt-4.1-mini']);

        Http::assertSent(fn (ClientRequest $request): bool => $request->url() === 'https://api.compatible-provider.com/v1/models'
            && $request->hasHeader('Authorization', 'Bearer temporary-compatible-secret'));
        $this->assertDatabaseCount('ai_settings', 0);
    }

    public function test_saved_key_is_reused_only_for_its_matching_provider_endpoint(): void
    {
        AiSetting::create([
            'provider' => 'openai',
            'model' => 'gpt-4.1-mini',
            'base_url' => 'https://api.compatible-provider.com/v1',
            'api_key' => 'saved-compatible-secret',
        ]);
        Http::fake([
            'https://api.compatible-provider.com/v1/models' => Http::response(['data' => [['id' => 'gpt-4.1-mini']]], 200),
            'https://other.compatible-provider.com/v1/models' => Http::response(['data' => [['id' => 'other-model']]], 200),
        ]);

        $this->actingAs($this->administrator())->postJson(route('admin.models'), [
            'provider' => 'openai',
            'base_url' => 'https://api.compatible-provider.com/v1',
        ])->assertOk();

        Http::assertSent(fn (ClientRequest $request): bool => $request->url() === 'https://api.compatible-provider.com/v1/models'
            && $request->hasHeader('Authorization', 'Bearer saved-compatible-secret'));

        $this->postJson(route('admin.models'), [
            'provider' => 'openai',
            'base_url' => 'https://other.compatible-provider.com/v1',
        ])->assertStatus(422)
            ->assertDontSee('saved-compatible-secret');
    }

    public function test_test_connection_runs_a_short_model_request_without_saving_settings(): void
    {
        Http::fake([
            'https://generativelanguage.googleapis.com/v1beta/models/gemini-2.5-flash:generateContent' => Http::response([
                'candidates' => [[
                    'content' => ['parts' => [['text' => 'VeoPrompter connection test passed.']]],
                ]],
            ], 200),
        ]);

        $this->actingAs($this->administrator())->postJson(route('admin.connection.test'), [
            'provider' => 'gemini',
            'model' => 'gemini-2.5-flash',
            'api_key' => 'temporary-gemini-secret',
        ])->assertOk()
            ->assertJsonPath('message', 'Connection succeeded. The selected model returned a text response.')
            ->assertJsonPath('reply', 'VeoPrompter connection test passed.')
            ->assertDontSee('temporary-gemini-secret');

        Http::assertSent(fn (ClientRequest $request): bool => str_contains($request->url(), 'gemini-2.5-flash:generateContent')
            && $request->hasHeader('x-goog-api-key', 'temporary-gemini-secret')
            && data_get($request->data(), 'generationConfig.maxOutputTokens') === 64);
        $this->assertDatabaseCount('ai_settings', 0);
    }

    public function test_openai_compatible_connection_test_returns_a_short_text_reply(): void
    {
        Http::fake([
            'https://api.compatible-provider.com/v1/chat/completions' => Http::response([
                'choices' => [['message' => ['content' => 'VeoPrompter connection test passed.']]],
            ], 200),
        ]);

        $this->actingAs($this->administrator())->postJson(route('admin.connection.test'), [
            'provider' => 'openai',
            'model' => 'gpt-4.1-mini',
            'base_url' => 'https://api.compatible-provider.com/v1',
            'api_key' => 'temporary-compatible-secret',
        ])->assertOk()
            ->assertJsonPath('reply', 'VeoPrompter connection test passed.')
            ->assertDontSee('temporary-compatible-secret');

        Http::assertSent(fn (ClientRequest $request): bool => $request->url() === 'https://api.compatible-provider.com/v1/chat/completions'
            && $request->hasHeader('Authorization', 'Bearer temporary-compatible-secret')
            && data_get($request->data(), 'model') === 'gpt-4.1-mini'
            && data_get($request->data(), 'max_tokens') === 64);
        $this->assertDatabaseCount('ai_settings', 0);
    }

    public function test_model_discovery_and_connection_test_are_admin_only(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->postJson(route('admin.models'), ['provider' => 'gemini'])->assertForbidden();
        $this->actingAs($user)->postJson(route('admin.connection.test'), [])->assertForbidden();
    }

    private function administrator(): User
    {
        $user = User::factory()->create();
        $user->is_admin = true;
        $user->save();

        return $user;
    }
}
