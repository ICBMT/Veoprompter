<?php

namespace Tests\Feature;

use App\Models\AiSetting;
use App\Models\User;
use App\Services\VoiceSampleService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request as ClientRequest;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class VoiceSampleTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_supported_preferences_cover_all_240_voice_combinations(): void
    {
        $combinations = count(VoiceSampleService::SAMPLE_LINES)
            * count(VoiceSampleService::VOICES)
            * count(VoiceSampleService::ACCENTS)
            * count(VoiceSampleService::TONES);

        $this->assertSame(240, $combinations);
    }

    public function test_a_generated_gemini_tts_sample_is_saved_and_reused_for_the_same_combination(): void
    {
        Storage::fake('local');
        $pcm = str_repeat("\x00\x00\x03\x00", 300);
        Http::fake([
            'https://generativelanguage.googleapis.com/*' => Http::response([
                'candidates' => [[
                    'content' => [
                        'parts' => [[
                            'inlineData' => [
                                'mimeType' => 'audio/L16;codec=pcm;rate=24000',
                                'data' => base64_encode($pcm),
                            ],
                        ]],
                    ],
                ]],
            ], 200),
        ]);
        AiSetting::create([
            'provider' => 'gemini',
            'model' => 'gemini-2.5-flash',
            'api_key' => 'prompt-provider-secret',
            'tts_model' => 'gemini-2.5-flash-preview-tts',
            'tts_api_key' => 'speech-provider-secret',
        ]);
        $user = User::factory()->create();
        $configuration = [
            'language' => 'Urdu',
            'narrator_voice' => 'feminine',
            'narrator_accent' => 'Pakistani English',
            'narrator_tone' => 'calm and thoughtful',
        ];

        $first = $this->actingAs($user)
            ->postJson(route('voice-samples.store'), $configuration)
            ->assertOk()
            ->assertHeader('Content-Type', 'audio/wav')
            ->assertDontSee('speech-provider-secret');

        $this->assertSame('RIFF', substr($first->getContent(), 0, 4));
        $this->assertSame('WAVE', substr($first->getContent(), 8, 4));
        $this->assertGreaterThan(strlen($pcm), strlen($first->getContent()));
        $this->assertSame($first->getContent(), $this->postJson(route('voice-samples.store'), $configuration)->getContent());
        Http::assertSentCount(1);
        Http::assertSent(function (ClientRequest $request): bool {
            $data = $request->data();

            return str_contains($request->url(), '/models/gemini-2.5-flash-preview-tts:generateContent')
                && $request->hasHeader('x-goog-api-key', 'speech-provider-secret')
                && data_get($data, 'generationConfig.responseModalities.0') === 'AUDIO'
                && data_get($data, 'generationConfig.speechConfig.voiceConfig.prebuiltVoiceConfig.voiceName') === 'Kore'
                && str_contains(data_get($data, 'contents.0.parts.0.text', ''), 'Urdu')
                && str_contains(data_get($data, 'contents.0.parts.0.text', ''), 'calm, reflective');
        });
    }

    public function test_voice_sample_generation_requires_an_admin_configured_speech_key(): void
    {
        AiSetting::create([
            'provider' => 'gemini',
            'model' => 'gemini-2.5-flash',
            'api_key' => 'prompt-provider-secret',
            'tts_model' => 'gemini-2.5-flash-preview-tts',
        ]);
        $user = User::factory()->create();

        $this->actingAs($user)
            ->postJson(route('voice-samples.store'), [
                'language' => 'English',
                'narrator_voice' => 'masculine',
                'narrator_accent' => 'American English',
                'narrator_tone' => 'warm and friendly',
            ])
            ->assertStatus(503)
            ->assertJsonPath('message', 'Voice samples are not configured yet. Ask an administrator to add a Google TTS key in the Control room.');
    }

    public function test_admin_tts_key_is_encrypted_and_never_rendered_to_the_browser(): void
    {
        $admin = User::factory()->create();
        $admin->is_admin = true;
        $admin->save();
        $secret = 'google-speech-test-secret';

        $this->actingAs($admin)
            ->put(route('admin.voice-settings.update'), [
                'tts_model' => 'gemini-2.5-flash-preview-tts',
                'tts_api_key' => $secret,
            ])
            ->assertRedirect(route('admin.settings', [], false));

        $settings = AiSetting::query()->firstOrFail();
        $this->assertSame($secret, $settings->tts_api_key);
        $this->assertStringNotContainsString($secret, $settings->getRawOriginal('tts_api_key'));
        $this->assertTrue($settings->ttsReady());

        $this->get(route('admin.settings'))
            ->assertOk()
            ->assertDontSee($secret)
            ->assertSee('Voice sample generation')
            ->assertSee('NEVER DISPLAYED AFTER SAVING');
    }

    public function test_non_admin_users_cannot_update_tts_credentials(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->put(route('admin.voice-settings.update'), [
                'tts_model' => 'gemini-2.5-flash-preview-tts',
                'tts_api_key' => 'not-allowed',
            ])
            ->assertForbidden();
    }
}
