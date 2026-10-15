<?php

namespace Tests\Feature;

use App\Models\AiSetting;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request as ClientRequest;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class PromptStudioTest extends TestCase
{
    use RefreshDatabase;

    public function test_prompt_studio_is_public_and_explains_the_workflow(): void
    {
        $this->get(route('studio'))
            ->assertOk()
            ->assertSee('Make every frame')
            ->assertSee('New story')
            ->assertSee('Extend a video')
            ->assertSee('YOUR COPY-READY PLAN');
    }

    public function test_prompt_generation_explains_when_an_admin_has_not_configured_an_ai(): void
    {
        $this->postJson(route('studio.generate'), $this->storyBrief())
            ->assertStatus(503)
            ->assertJsonPath('message', 'The prompt studio is waiting for an AI connection. An administrator can finish setup in the Control room.');
    }

    public function test_configured_gemini_receives_the_master_spec_and_returns_copyable_text(): void
    {
        Http::fake([
            'https://generativelanguage.googleapis.com/*' => Http::response([
                'candidates' => [[
                    'content' => [
                        'parts' => [['text' => "## PROJECT OVERVIEW\nA short story.\n\n### COPY CLIP 1 PROMPT\n```text\nA stable opening shot.\n```"]],
                    ],
                ]],
            ], 200),
        ]);

        AiSetting::create([
            'provider' => 'gemini',
            'model' => 'gemini-2.5-flash',
            'api_key' => 'test-provider-secret',
        ]);

        $this->postJson(route('studio.generate'), $this->storyBrief())
            ->assertOk()
            ->assertJsonPath('mode', 'story')
            ->assertJsonPath('content', "## PROJECT OVERVIEW\nA short story.\n\n### COPY CLIP 1 PROMPT\n```text\nA stable opening shot.\n```")
            ->assertDontSee('test-provider-secret');

        Http::assertSent(function (ClientRequest $request): bool {
            $body = $request->data();
            $systemInstruction = data_get($body, 'systemInstruction.parts.0.text', '');

            return str_contains($request->url(), 'models/gemini-2.5-flash:generateContent')
                && $request->hasHeader('x-goog-api-key', 'test-provider-secret')
                && str_contains($systemInstruction, 'AI VIDEO STORYTELLER — MASTER SPECIFICATION')
                && str_contains($systemInstruction, 'MANDATORY CONTINUATION INSTRUCTION')
                && str_contains(data_get($body, 'contents.0.parts.0.text', ''), 'Why do bees visit flowers?');
        });
    }

    public function test_openai_compatible_provider_uses_the_admin_configured_endpoint_and_bearer_key(): void
    {
        Http::fake([
            'https://api.compatible-provider.com/*' => Http::response([
                'choices' => [[
                    'message' => ['content' => '## PROJECT OVERVIEW'."\n".'A connected story.'],
                ]],
            ], 200),
        ]);

        AiSetting::create([
            'provider' => 'openai',
            'model' => 'gpt-4.1-mini',
            'base_url' => 'https://api.compatible-provider.com/v1',
            'api_key' => 'test-compatible-secret',
        ]);

        $this->postJson(route('studio.generate'), $this->storyBrief())
            ->assertOk()
            ->assertJsonPath('content', "## PROJECT OVERVIEW\nA connected story.")
            ->assertJsonPath('saved_to_history', false)
            ->assertJsonPath('history_error', false);

        Http::assertSent(function (ClientRequest $request): bool {
            return $request->url() === 'https://api.compatible-provider.com/v1/chat/completions'
                && $request->hasHeader('Authorization', 'Bearer test-compatible-secret')
                && data_get($request->data(), 'model') === 'gpt-4.1-mini';
        });
    }

    public function test_signed_in_generation_is_saved_to_private_history_and_remains_owner_scoped(): void
    {
        $generatedContent = "## PROJECT OVERVIEW\nA connected story.\n\n### COPY CLIP 1 PROMPT\n```text\nA stable opening shot.\n```";
        Http::fake([
            'https://generativelanguage.googleapis.com/*' => Http::response([
                'candidates' => [['content' => ['parts' => [['text' => $generatedContent]]]]],
            ], 200),
        ]);
        AiSetting::create([
            'provider' => 'gemini',
            'model' => 'gemini-2.5-flash',
            'api_key' => 'test-provider-secret',
        ]);

        $owner = User::factory()->create();
        $otherUser = User::factory()->create();
        $payload = [...$this->storyBrief(), 'project_title' => 'Bee garden story'];

        $response = $this->actingAs($owner)->postJson(route('studio.generate'), $payload)
            ->assertOk()
            ->assertJsonPath('saved_to_history', true)
            ->assertJsonPath('history_error', false)
            ->assertJsonPath('project.title', 'Bee garden story')
            ->assertJsonPath('content', $generatedContent);

        $this->assertDatabaseCount('prompt_projects', 1);
        $project = $owner->promptProjects()->firstOrFail();
        $this->assertSame($payload['topic'], $project->brief['topic']);
        $this->assertArrayNotHasKey('reference_frame', $project->brief);
        $this->assertSame('gemini', $project->provider);
        $this->assertSame('gemini-2.5-flash', $project->model);
        $this->assertFalse($project->reference_frame_attached);
        $this->assertSame(route('history.show', $project, false), $response->json('project.url'));

        $this->actingAs($otherUser)
            ->get(route('history.show', $project))
            ->assertNotFound();
        $this->actingAs($owner)
            ->get(route('history.show', $project))
            ->assertOk()
            ->assertSee('Bee garden story')
            ->assertSee('A stable opening shot.');
    }

    public function test_only_an_administrator_can_open_the_control_room(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->get(route('admin.settings'))->assertForbidden();
        $this->actingAs($user)->put(route('admin.settings.update'), [])->assertForbidden();
    }

    public function test_admin_credentials_are_encrypted_and_never_rendered_back_to_the_browser(): void
    {
        $admin = User::factory()->create();
        $admin->is_admin = true;
        $admin->save();
        $secret = 'AIza-test-secret-value';

        $this->actingAs($admin)
            ->put(route('admin.settings.update'), [
                'provider' => 'gemini',
                'model' => 'gemini-2.5-flash',
                'api_key' => $secret,
            ])
            ->assertRedirect(route('admin.settings', [], false));

        $setting = AiSetting::query()->firstOrFail();
        $this->assertSame($secret, $setting->api_key);
        $this->assertStringNotContainsString($secret, $setting->getRawOriginal('api_key'));

        $this->actingAs($admin)
            ->get(route('admin.settings'))
            ->assertOk()
            ->assertDontSee($secret)
            ->assertSee('NEVER DISPLAYED AFTER SAVING');
    }

    private function storyBrief(): array
    {
        return [
            'mode' => 'story',
            'topic' => 'Why do bees visit flowers?',
            'learning_objective' => 'Explain how pollination moves pollen between flowers.',
            'audience' => 'Curious kids and families',
            'visual_style' => '3d-family-animation',
            'character_idea' => 'A tiny golden bee with a blue satchel',
            'clip_count' => 4,
            'clip_duration' => 8,
            'aspect_ratio' => '16:9',
            'educational_depth' => 'clear',
            'narration' => '1',
            'language' => 'English',
            'narrator_voice' => 'feminine',
            'narrator_accent' => 'American English',
            'narrator_tone' => 'warm and friendly',
            'music' => '0',
            'sound_effects' => '1',
        ];
    }
}
