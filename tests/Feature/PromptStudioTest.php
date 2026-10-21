<?php

namespace Tests\Feature;

use App\Models\AiSetting;
use App\Models\StudioProject;
use App\Models\User;
use App\Support\ProjectSettings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request as ClientRequest;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class PromptStudioTest extends TestCase
{
    use RefreshDatabase;

    public function test_prompt_projects_and_generation_require_login(): void
    {
        $this->get(route('studio'))->assertRedirect('/login');
        $this->get(route('projects.create'))->assertRedirect('/login');

        $owner = User::factory()->create();
        $project = $this->makeProject($owner);
        $this->postJson(route('studio.generate', $project), $this->storyBrief())
            ->assertUnauthorized()
            ->assertJsonPath('message', 'Sign in is required to use this feature.');

        $this->actingAs($owner)
            ->get(route('projects.show', $project))
            ->assertOk()
            ->assertSee($project->name)
            ->assertSee('What should we make next?')
            ->assertSee('Edit project settings')
            ->assertSee('data-send-to-flow', false)
            ->assertSee('Send to Flow');
    }

    public function test_project_creation_uses_a_guided_wizard_while_editing_keeps_the_full_settings_form(): void
    {
        $owner = User::factory()->create();
        $project = $this->makeProject($owner);

        $this->actingAs($owner)
            ->get(route('projects.create'))
            ->assertOk()
            ->assertSee('data-project-wizard', false)
            ->assertSee('data-wizard-step', false)
            ->assertSee('data-wizard-when="narration:1"', false)
            ->assertSee('data-voice-preview-button', false)
            ->assertSee('data-wizard-title="Choose a video type"', false)
            ->assertSee('Cinematic story / short film')
            ->assertSee('style-3d.jpg')
            ->assertSee('style-stylized-3d-family.jpg')
            ->assertSee('style-japanese-anime.jpg')
            ->assertSee('style-2d.jpg')
            ->assertSee('style-cinematic.jpg')
            ->assertSee('style-stop-motion.jpg')
            ->assertSee('Disney/Pixar-style 3D animation')
            ->assertSee('Japanese anime-style animation')
            ->assertSee('Preview this language')
            ->assertSee('Preview this voice')
            ->assertSee('Preview this accent')
            ->assertSee('Preview the complete setup')
            ->assertSee('data-wizard-review-voice-preview', false);

        $this->get(route('projects.settings.edit', $project))
            ->assertOk()
            ->assertDontSee('data-project-wizard', false)
            ->assertSee('project-settings-grid', false)
            ->assertSee('Save project settings');
    }

    public function test_prompt_generation_explains_when_an_admin_has_not_configured_an_ai(): void
    {
        $owner = User::factory()->create();
        $project = $this->makeProject($owner);

        $this->actingAs($owner)
            ->postJson(route('studio.generate', $project), $this->storyBrief())
            ->assertStatus(503)
            ->assertJsonPath('message', 'The prompt studio is waiting for an AI connection. An administrator can finish setup in the Control room.');
    }

    public function test_configured_gemini_receives_master_spec_saved_preferences_and_returns_copyable_text(): void
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
        $owner = User::factory()->create();
        $project = $this->makeProject($owner, [
            'audience' => 'Saved family audience',
            'character_type' => 'robot',
            'clip_count' => 'auto',
        ]);
        $payload = [...$this->storyBrief(), 'audience' => 'Untrusted browser override'];

        $this->actingAs($owner)
            ->postJson(route('studio.generate', $project), $payload)
            ->assertOk()
            ->assertJsonPath('mode', 'story')
            ->assertJsonPath('content', "## PROJECT OVERVIEW\nA short story.\n\n### COPY CLIP 1 PROMPT\n```text\nA stable opening shot.\n```")
            ->assertJsonPath('saved_to_history', true)
            ->assertDontSee('test-provider-secret');

        Http::assertSent(function (ClientRequest $request): bool {
            $body = $request->data();
            $systemInstruction = data_get($body, 'systemInstruction.parts.0.text', '');
            $brief = data_get($body, 'contents.0.parts.0.text', '');

            return str_contains($request->url(), 'models/gemini-2.5-flash:generateContent')
                && $request->hasHeader('x-goog-api-key', 'test-provider-secret')
                && str_contains($systemInstruction, 'AI VIDEO STORYTELLER — MASTER SPECIFICATION')
                && str_contains($systemInstruction, 'MANDATORY CONTINUATION INSTRUCTION')
                && str_contains($systemInstruction, 'TRANSITION CLIP SYSTEM')
                && str_contains($systemInstruction, 'Type A direct continuation, Type B natural action transition, or Type C narrative/temporal transition')
                && str_contains($systemInstruction, 'do not include it in a Type C transition clip')
                && str_contains($systemInstruction, 'CLIP COUNT — AI DIRECTS: Choose the minimum practical number of connected clips needed to communicate the idea clearly and give it a complete arc.')
                && str_contains($systemInstruction, 'no arbitrary four-clip or eight-clip ceiling for this AI-selected choice')
                && str_contains($systemInstruction, 'CLIP DURATION — AI DIRECTS: Choose the shortest practical exact whole-second duration for each clip independently')
                && str_contains($systemInstruction, 'Short visual beats may be about 3 seconds')
                && str_contains($brief, 'Aspect ratio: 9:16')
                && str_contains($brief, 'Clip duration: AI decides; choose the shortest practical exact whole-second duration for the action and narration, up to 10 seconds')
                && str_contains($brief, 'Number of clips: AI decides; choose the minimum practical total number of connected clips')
                && str_contains($brief, 'Transition planning: Classify every incoming handoff as Type A (direct continuation), Type B (visible natural action), or Type C (intentional narrative/temporal transition with before → change → after)')
                && str_contains($brief, 'Background music: Include subtle music that never overpowers speech')
                && str_contains($brief, 'Sound effects: Use only restrained, action-matched sound effects')
                && str_contains($brief, 'Why do bees visit flowers?')
                && str_contains($brief, 'Saved family audience')
                && ! str_contains($brief, 'Untrusted browser override')
                && str_contains($brief, 'Main character type: Robot or animated machine')
                && str_contains($brief, 'Visual style: 3d-family-animation')
                && ! str_contains($brief, 'Visual style direction:');
        });

        $this->assertSame('auto', $owner->promptProjects()->firstOrFail()->brief['clip_count']);
    }

    public function test_new_visual_styles_guide_generated_prompts_and_are_saved_with_readable_history_labels(): void
    {
        Http::fake([
            'https://generativelanguage.googleapis.com/*' => Http::response([
                'candidates' => [['content' => ['parts' => [['text' => "## PROJECT OVERVIEW\nA visual story.\n\n### COPY CLIP 1 PROMPT\n```text\nA consistent opening shot.\n```"]]]]],
            ], 200),
        ]);
        AiSetting::create([
            'provider' => 'gemini',
            'model' => 'gemini-2.5-flash',
            'api_key' => 'test-provider-secret',
        ]);
        $owner = User::factory()->create();
        $styles = [
            'disney-pixar-inspired' => [
                'label' => 'Disney/Pixar-style 3D animation',
                'direction' => 'Use an original, polished 3D family-feature animation look associated with Disney/Pixar',
            ],
            'japanese-anime' => [
                'label' => 'Japanese anime-style animation',
                'direction' => 'Use Japanese anime-inspired visual language: clean expressive linework',
            ],
        ];

        foreach ($styles as $style => $expected) {
            $project = $this->makeProject($owner, ['visual_style' => $style]);
            $this->actingAs($owner)
                ->postJson(route('studio.generate', $project), $this->storyBrief())
                ->assertOk()
                ->assertJsonPath('saved_to_history', true);

            $run = $owner->promptProjects()->orderByDesc('id')->firstOrFail();
            $this->assertSame($style, $run->brief['visual_style']);
            Http::assertSent(function (ClientRequest $request) use ($expected): bool {
                $brief = data_get($request->data(), 'contents.0.parts.0.text', '');

                return str_contains($brief, 'Visual style: '.$expected['label'])
                    && str_contains($brief, 'Visual style direction: '.$expected['direction']);
            });

            $this->get(route('history.show', $run))
                ->assertOk()
                ->assertSee($expected['label']);
        }
    }

    public function test_cinematic_story_mode_uses_a_general_video_brief_without_changing_the_project_default(): void
    {
        Http::fake([
            'https://generativelanguage.googleapis.com/*' => Http::response([
                'candidates' => [['content' => ['parts' => [['text' => "## VIDEO OVERVIEW\nA quiet mystery.\n\n### COPY CLIP 1 PROMPT\n```text\nA lighthouse beam cuts through the rain.\n```"]]]]],
            ], 200),
        ]);
        AiSetting::create([
            'provider' => 'gemini',
            'model' => 'gemini-2.5-flash',
            'api_key' => 'test-provider-secret',
        ]);
        $owner = User::factory()->create();
        $project = $this->makeProject($owner, ['video_type' => 'educational']);
        $payload = [
            ...$this->storyBrief(),
            'video_type' => 'cinematic-story',
            'topic' => 'A lighthouse keeper finds an impossible signal',
            'creative_direction' => 'Quiet coastal mystery, restrained dialogue, blue-hour rain.',
        ];

        $this->actingAs($owner)
            ->postJson(route('studio.generate', $project), $payload)
            ->assertOk()
            ->assertJsonPath('saved_to_history', true);

        Http::assertSent(function (ClientRequest $request): bool {
            $body = $request->data();
            $systemInstruction = data_get($body, 'systemInstruction.parts.0.text', '');
            $brief = data_get($body, 'contents.0.parts.0.text', '');

            return str_contains($systemInstruction, 'NON-EDUCATIONAL VIDEO MODE — SELECTED FORMAT: Cinematic story / short film')
                && str_contains($systemInstruction, 'clear goal, obstacle, escalation, and satisfying ending')
                && str_contains($brief, 'Video type: Cinematic story / short film')
                && str_contains($brief, 'Video idea / subject: A lighthouse keeper finds an impossible signal')
                && str_contains($brief, 'Creative direction: Quiet coastal mystery')
                && ! str_contains($brief, 'Learning objective:')
                && ! str_contains($brief, 'Educational depth:');
        });

        $this->assertSame('educational', $project->fresh()->settings['video_type']);
        $this->assertSame('cinematic-story', $owner->promptProjects()->firstOrFail()->brief['video_type']);
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
        $owner = User::factory()->create();
        $project = $this->makeProject($owner);

        $this->actingAs($owner)
            ->postJson(route('studio.generate', $project), $this->storyBrief())
            ->assertOk()
            ->assertJsonPath('content', "## PROJECT OVERVIEW\nA connected story.")
            ->assertJsonPath('saved_to_history', true)
            ->assertJsonPath('history_error', false);

        Http::assertSent(function (ClientRequest $request): bool {
            return $request->url() === 'https://api.compatible-provider.com/v1/chat/completions'
                && $request->hasHeader('Authorization', 'Bearer test-compatible-secret')
                && data_get($request->data(), 'model') === 'gpt-4.1-mini';
        });
    }

    public function test_generated_prompt_kits_are_saved_under_the_selected_project_and_remain_owner_scoped(): void
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
        $studioProject = $this->makeProject($owner);
        $payload = [...$this->storyBrief(), 'project_title' => 'Bee garden story'];

        $response = $this->actingAs($owner)->postJson(route('studio.generate', $studioProject), $payload)
            ->assertOk()
            ->assertJsonPath('saved_to_history', true)
            ->assertJsonPath('history_error', false)
            ->assertJsonPath('run.title', 'Bee garden story')
            ->assertJsonPath('content', $generatedContent);

        $this->assertDatabaseCount('prompt_projects', 1);
        $run = $owner->promptProjects()->firstOrFail();
        $this->assertSame($payload['topic'], $run->brief['topic']);
        $this->assertArrayNotHasKey('reference_frame', $run->brief);
        $this->assertSame($studioProject->id, $run->studio_project_id);
        $this->assertSame('gemini', $run->provider);
        $this->assertSame('gemini-2.5-flash', $run->model);
        $this->assertFalse($run->reference_frame_attached);
        $this->assertSame(route('history.show', $run, false), $response->json('run.url'));
        $this->assertSame(1, $studioProject->promptProjects()->count());

        $this->actingAs($otherUser)->get(route('history.show', $run))->assertNotFound();
        $this->actingAs($owner)
            ->get(route('history.show', $run))
            ->assertOk()
            ->assertSee('Bee garden story')
            ->assertSee('A stable opening shot.')
            ->assertSee('Copy all')
            ->assertSee('Save .txt')
            ->assertSee('Send to Flow')
            ->assertSee('data-send-to-flow', false)
            ->assertSee('data-prompt-target="#saved-prompt-output"', false)
            ->assertSee('/js/extension-handoff.js');

        $administrator = User::factory()->create();
        $administrator->is_admin = true;
        $administrator->save();
        $this->actingAs($administrator)
            ->get(route('admin.users.show', $owner))
            ->assertOk()
            ->assertSee('Bee garden story')
            ->assertSee('A stable opening shot.')
            ->assertSee('Generated prompt kits');
    }

    public function test_a_user_cannot_open_or_generate_from_another_users_project(): void
    {
        $owner = User::factory()->create();
        $otherUser = User::factory()->create();
        $project = $this->makeProject($owner);

        $this->actingAs($otherUser)->get(route('projects.show', $project))->assertNotFound();
        $this->actingAs($otherUser)->postJson(route('studio.generate', $project), $this->storyBrief())->assertNotFound();
    }

    public function test_extension_uses_the_projects_separate_saved_extension_duration(): void
    {
        Http::fake([
            'https://generativelanguage.googleapis.com/*' => Http::response([
                'candidates' => [['content' => ['parts' => [['text' => '## EXTENSION PROMPT'."\\n".'Continue from the visible frame.']]]]],
            ], 200),
        ]);
        AiSetting::create([
            'provider' => 'gemini',
            'model' => 'gemini-2.5-flash',
            'api_key' => 'test-provider-secret',
            'allow_clip_duration_choice' => true,
            'allow_extension_duration_choice' => true,
        ]);
        $owner = User::factory()->create();
        $project = $this->makeProject($owner, ['clip_duration' => 10, 'extend_duration' => 6]);

        $this->actingAs($owner)
            ->postJson(route('studio.generate', $project), [
                'mode' => 'extend',
                'next_action' => 'The character moves toward the flower.',
                'visible_start' => 'A small bee hovers by a yellow flower.',
            ])
            ->assertOk()
            ->assertJsonPath('mode', 'extend')
            ->assertJsonPath('saved_to_history', true);

        Http::assertSent(fn (ClientRequest $request): bool => str_contains(
            data_get($request->data(), 'contents.0.parts.0.text', ''),
            'Clip duration: 6 seconds',
        ));
    }

    public function test_ai_selects_a_bounded_extension_duration_when_global_duration_questions_are_off(): void
    {
        Http::fake([
            'https://generativelanguage.googleapis.com/*' => Http::response([
                'candidates' => [['content' => ['parts' => [['text' => '## EXTENSION PROMPT'."\n".'Continue from the visible frame.']]]]],
            ], 200),
        ]);
        AiSetting::create([
            'provider' => 'gemini',
            'model' => 'gemini-2.5-flash',
            'api_key' => 'test-provider-secret',
        ]);
        $owner = User::factory()->create();
        $project = $this->makeProject($owner, ['extend_duration' => 'auto']);

        $this->actingAs($owner)
            ->postJson(route('studio.generate', $project), [
                'mode' => 'extend',
                'next_action' => 'The character moves toward the flower.',
                'visible_start' => 'A small bee hovers by a yellow flower.',
            ])
            ->assertOk()
            ->assertJsonPath('saved_to_history', true);

        Http::assertSent(function (ClientRequest $request): bool {
            $systemInstructions = data_get($request->data(), 'systemInstruction.parts.0.text', '');
            $brief = data_get($request->data(), 'contents.0.parts.0.text', '');

            return str_contains($systemInstructions, 'EXTENSION DURATION — AI DIRECTS: Choose the shortest practical exact whole-second duration')
                && str_contains($brief, 'Clip duration: AI decides; choose the shortest practical exact whole-second duration for the action and narration, up to 10 seconds')
                && str_contains($brief, 'Workflow: Extend an existing video');
        });

        $this->assertSame('auto', $owner->promptProjects()->firstOrFail()->brief['clip_duration']);
    }

    public function test_provider_http_503_is_reported_as_temporary_unavailability_not_as_an_api_key_error(): void
    {
        Http::fake([
            'https://generativelanguage.googleapis.com/*' => Http::response(['error' => ['message' => 'temporary overload']], 503),
        ]);
        AiSetting::create([
            'provider' => 'gemini',
            'model' => 'gemini-2.5-flash',
            'api_key' => 'test-provider-secret',
        ]);
        $owner = User::factory()->create();
        $project = $this->makeProject($owner);

        $this->actingAs($owner)
            ->postJson(route('studio.generate', $project), $this->storyBrief())
            ->assertStatus(502)
            ->assertJsonPath('message', 'The AI provider is temporarily unavailable (HTTP 503). Wait a moment and try again. If it continues, check the provider status and quota; the API key is not necessarily the cause.');
    }

    public function test_only_an_administrator_can_open_the_control_room_and_user_management(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->get(route('admin.settings'))->assertForbidden();
        $this->actingAs($user)->put(route('admin.settings.update'), [])->assertForbidden();
        $this->actingAs($user)->get(route('admin.users.index'))->assertForbidden();
        $this->actingAs($user)->post(route('admin.users.test-accounts.store'), [])->assertForbidden();
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

    private function makeProject(User $owner, array $settings = []): StudioProject
    {
        return $owner->studioProjects()->create([
            'name' => 'Bee garden project',
            'settings' => array_replace(ProjectSettings::defaults(), $settings),
        ]);
    }

    private function storyBrief(): array
    {
        return [
            'mode' => 'story',
            'topic' => 'Why do bees visit flowers?',
            'learning_objective' => 'Explain how pollination moves pollen between flowers.',
            'character_idea' => 'A tiny golden bee with a blue satchel',
            'project_title' => 'Bee prompt kit',
        ];
    }
}
