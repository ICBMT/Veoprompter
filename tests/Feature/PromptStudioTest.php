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
            ->assertSee('Send to Flow')
            ->assertSee('one is required to establish the starting state')
            ->assertSee('OPTIONAL IF IMAGE ATTACHED');
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
            ->assertSee('name="clip_count" value="quality"', false)
            ->assertSee('AI decides · maximum quality')
            ->assertSee('data-wizard-when="narration:1"', false)
            ->assertSee('data-wizard-when="narration:1&amp;speech_mode:voiceover"', false)
            ->assertSee('name="speech_mode"', false)
            ->assertSee('Characters speak in-scene')
            ->assertSee('action visibly performed in that clip')
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
            ->assertSee('Preview this narrator voice')
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

        $savedRun = $owner->promptProjects()->latest('id')->firstOrFail();
        $this->assertSame("## PROJECT OVERVIEW\nA short story.\n\n### COPY CLIP 1 PROMPT\n```text\nA stable opening shot.\n```", $savedRun->original_content);
        $this->assertSame([], $savedRun->repair_history);

        Http::assertSent(function (ClientRequest $request): bool {
            $body = $request->data();
            $systemInstruction = data_get($body, 'systemInstruction.parts.0.text', '');
            $brief = data_get($body, 'contents.0.parts.0.text', '');

            return str_contains($request->url(), 'models/gemini-2.5-flash:generateContent')
                && $request->hasHeader('x-goog-api-key', 'test-provider-secret')
                && str_contains($systemInstruction, 'AI VIDEO STORYTELLER — MASTER SPECIFICATION')
                && str_contains($systemInstruction, 'MANDATORY CONTINUATION INSTRUCTION')
                && str_contains($systemInstruction, 'only when the actual previous rendered final frame will be supplied to the target video model as an image input')
                && str_contains($systemInstruction, 'CONTINUATION SCOPE:')
                && str_contains($systemInstruction, 'the quote\'s clothing, gesture, step, and light-shift examples are illustrative only')
                && str_contains($systemInstruction, 'If no prior image will be supplied—or frame use is unconfirmed—omit this quote from the copyable prompt')
                && str_contains($systemInstruction, 'TRANSITION CLIP SYSTEM')
                && str_contains($systemInstruction, 'Type A direct continuation, Type B natural action transition, or Type C narrative/temporal transition')
                && str_contains($systemInstruction, 'CHARACTER IDENTITY ENFORCEMENT — THE CANONICAL CHARACTER BIBLE IS THE SINGLE SOURCE OF TRUTH')
                && str_contains($systemInstruction, 'SPEECH/VISUAL ACTION LOCK — Map every exact voice-over or dialogue line to a timed action')
                && str_contains($systemInstruction, 'MATCHED HANDOFF CONTRACT — Compare each prior ending with the next opening')
                && str_contains($systemInstruction, 'Do not choose Type A merely because openings match: Type A continues an already-in-progress action with no separate bridge to depict; Type B also opens on the exact prior state, then begins a new visible physical action and shows the complete physical bridge after that opening.')
                && str_contains($systemInstruction, 'destination clip must begin from the exact prior ending in both continuity and presentation')
                && str_contains($systemInstruction, 'copy the prior `ENDING PRESENTATION STATE:` verbatim into `OPENING PRESENTATION STATE:`')
                && str_contains($systemInstruction, 'Do not cut directly to the changed destination state or presentation.')
                && str_contains($systemInstruction, 'For multi-shot clips, give every shot its exact cast/count plus a full repeated identity lock')
                && str_contains($systemInstruction, 'do not include it in a Type C transition clip')
                && str_contains($systemInstruction, 'SPOKEN AUDIO MODE — VOICE-OVER: Use one consistent off-screen narrator')
                && str_contains($systemInstruction, 'CLIP COUNT — AI DIRECTS: Choose the minimum practical number of connected clips needed to communicate the idea clearly and give it a complete arc.')
                && str_contains($systemInstruction, 'no arbitrary four-clip or eight-clip ceiling for this AI-selected choice')
                && str_contains($systemInstruction, 'CLIP DURATION — AI DIRECTS: Choose an exact whole-second duration independently for each clip based on its real complexity, not a shortest-by-default rule')
                && str_contains($systemInstruction, 'Use about 2–3 seconds for one simple reveal or movement')
                && str_contains($systemInstruction, '7–10 seconds when coordinated beats, multiple subjects, a transformation')
                && str_contains($brief, 'Aspect ratio: 9:16')
                && str_contains($brief, 'Speech mode: Voice-over narration by one consistent off-screen narrator')
                && str_contains($brief, 'Speech-to-visual alignment: For every exact narration or dialogue line, use a clip-relative time row')
                && str_contains($brief, 'Spoken language: English')
                && str_contains($brief, 'Clip duration: AI decides from complexity: about 2–3 seconds for a simple beat, 4–6 for a moderately layered action, and 7–10 when coordinated action, transformations, camera moves, multiple subjects, or speech genuinely need room')
                && str_contains($brief, 'Number of clips: AI decides; choose the minimum practical total number of connected clips')
                && str_contains($brief, 'Logical visual/process continuity: Track identity and changing state for every important character, material, component, object, quantity, location, orientation, condition, and process stage—not only visual appearance.')
                && str_contains($brief, 'Character identity enforcement: NEW-SEQUENCE CHARACTER LOCK: Respect the saved robot/animated-machine character type.')
                && str_contains($brief, 'SHOT N VISIBLE CHARACTER CAST:')
                && str_contains($brief, 'SHOT N CHARACTER IDENTITY LOCK:')
                && str_contains($brief, 'Saved character type: robot.')
                && str_contains($brief, 'Saved character idea: A tiny golden bee with a blue satchel.')
                && str_contains($brief, 'Transition planning: Before drafting, compare every prior ending with the next opening across exact cast/identity, poses and positions, objects, materials/process state, environment, camera/motion, lighting, and audible action. Match each prior `OUTGOING HANDOFF TYPE:` exactly to the destination `TRANSITION TYPE:`.')
                && str_contains($brief, 'Do not choose Type A merely because openings match: Type A continues an already-in-progress action with no separate bridge to depict; Type B also opens on the exact prior state, then begins a new visible physical action and shows the complete physical bridge after that opening.')
                && str_contains($brief, 'Copy every preceding `ENDING CONTINUITY STATE:` value verbatim into the next clip\'s `OPENING CONTINUITY STATE:`; for Type A and Type B, also copy the prior `ENDING PRESENTATION STATE:` verbatim into the opening.')
                && str_contains($brief, 'its complete visible physical bridge happens only after the matching opening, never as a cut directly to a changed state or presentation')
                && str_contains($brief, 'Continuity input note: For a multi-clip sequence, include the concise Section 1 CONTINUITY INPUT NOTE outside all copyable prompts.')
                && str_contains($brief, 'advise the user to provide each actual previous rendered final frame to anchor the next clip opening')
                && str_contains($brief, 'Unless the user explicitly confirms that an actual prior frame will be attached when generating the video, treat frame input as unconfirmed: omit the verbatim continuation quote from new-sequence Type A/B prompts and use text-only opening-state wording.')
                && str_contains($brief, 'No prior rendered frame was supplied to this new-sequence request')
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

    public function test_spoken_audio_off_requires_a_none_speech_map_and_no_spoken_words(): void
    {
        Http::fake([
            'https://generativelanguage.googleapis.com/*' => Http::response([
                'candidates' => [['content' => ['parts' => [['text' => "## PROJECT OVERVIEW\nA silent bee story.\n"]]]]],
            ], 200),
        ]);
        AiSetting::create([
            'provider' => 'gemini',
            'model' => 'gemini-2.5-flash',
            'api_key' => 'test-provider-secret',
        ]);
        $owner = User::factory()->create();
        $project = $this->makeProject($owner, ['narration' => '0']);

        $this->actingAs($owner)
            ->postJson(route('studio.generate', $project), $this->storyBrief())
            ->assertOk()
            ->assertJsonPath('saved_to_history', true);

        Http::assertSent(function (ClientRequest $request): bool {
            $systemInstruction = data_get($request->data(), 'systemInstruction.parts.0.text', '');
            $brief = data_get($request->data(), 'contents.0.parts.0.text', '');

            return str_contains($systemInstruction, 'SPOKEN AUDIO MODE — OFF: Include no voice-over narrator, character dialogue, lyrics, or other spoken words')
                && str_contains($systemInstruction, '`SPEECH/VISUAL MAP: None — spoken audio is off`')
                && str_contains($brief, 'Spoken audio: Off')
                && str_contains($brief, 'Speech-to-visual alignment: Speech is off; write `SPEECH/VISUAL MAP: None — spoken audio is off` in every clip and include no spoken words.');
        });

        Http::assertSentCount(1);
    }

    public function test_quality_focused_clip_count_builds_logically_sequenced_process_stages_and_plausible_handoffs(): void
    {
        Http::fake([
            'https://generativelanguage.googleapis.com/*' => Http::response([
                'candidates' => [['content' => ['parts' => [['text' => "## PROJECT OVERVIEW\nA quality-focused vehicle-manufacturing sequence.\n"]]]]],
            ], 200),
        ]);
        AiSetting::create([
            'provider' => 'gemini',
            'model' => 'gemini-2.5-flash',
            'api_key' => 'test-provider-secret',
        ]);
        $owner = User::factory()->create();
        $project = $this->makeProject($owner, ['clip_count' => ProjectSettings::AI_QUALITY_CLIP_COUNT]);
        $brief = [...$this->storyBrief(), 'topic' => 'How are vehicles manufactured?'];

        $this->actingAs($owner)
            ->postJson(route('studio.generate', $project), $brief)
            ->assertOk()
            ->assertJsonPath('saved_to_history', true);

        $savedRun = $owner->promptProjects()->firstOrFail();
        $this->assertSame(ProjectSettings::AI_QUALITY_CLIP_COUNT, $savedRun->brief['clip_count']);

        Http::assertSent(function (ClientRequest $request): bool {
            $systemInstruction = data_get($request->data(), 'systemInstruction.parts.0.text', '');
            $creativeBrief = data_get($request->data(), 'contents.0.parts.0.text', '');

            return str_contains($systemInstruction, 'CLIP COUNT — AI DIRECTS FOR MAXIMUM QUALITY: Optimize the sequence for logical clarity, visible cause and effect, correct chronology, complete process stages')
                && str_contains($systemInstruction, 'LOGICAL AND PROCESS-STATE CONTINUITY')
                && str_contains($systemInstruction, 'Do not imply that flat sheets instantly become a finished car')
                && str_contains($systemInstruction, 'If no physically and temporally plausible handoff exists, revise the beat order')
                && str_contains($creativeBrief, 'Number of clips: AI decides for maximum quality; use enough connected clips to make every meaningful cause-and-effect beat')
                && str_contains($creativeBrief, 'Logical visual/process continuity: Track identity and changing state for every important character, material, component')
                && str_contains($creativeBrief, 'How are vehicles manufactured?');
        });

        Http::assertSentCount(1);
    }

    public function test_character_dialogue_uses_distinct_character_voice_profiles_and_never_requests_a_narrator(): void
    {
        Http::fake([
            'https://generativelanguage.googleapis.com/*' => Http::response([
                'candidates' => [['content' => ['parts' => [['text' => "## PROJECT OVERVIEW\nTwo characters discuss pollination."]]]]],
            ], 200),
        ]);
        AiSetting::create([
            'provider' => 'gemini',
            'model' => 'gemini-2.5-flash',
            'api_key' => 'test-provider-secret',
        ]);
        $owner = User::factory()->create();
        $project = $this->makeProject($owner, [
            'narration' => '1',
            'speech_mode' => ProjectSettings::SPEECH_MODE_CHARACTER_DIALOGUE,
            'language' => 'Urdu',
            'character_type' => 'fictional',
            'character_details' => 'Mina, a cautious young inventor with copper goggles.',
        ]);

        $this->actingAs($owner)
            ->postJson(route('studio.generate', $project), $this->storyBrief())
            ->assertOk()
            ->assertJsonPath('saved_to_history', true);

        $run = $owner->promptProjects()->firstOrFail();
        $savedBrief = $run->brief;
        $this->assertSame(ProjectSettings::SPEECH_MODE_CHARACTER_DIALOGUE, $savedBrief['speech_mode']);
        $this->assertSame('Urdu', $savedBrief['language']);
        $this->get(route('history.show', $run))
            ->assertOk()
            ->assertSee('On-screen character dialogue')
            ->assertSee('Urdu');

        Http::assertSent(function (ClientRequest $request): bool {
            $body = $request->data();
            $systemInstruction = data_get($body, 'systemInstruction.parts.0.text', '');
            $brief = data_get($body, 'contents.0.parts.0.text', '');

            return str_contains($systemInstruction, 'SPOKEN AUDIO MODE — ON-SCREEN CHARACTER DIALOGUE')
                && str_contains($systemInstruction, 'No off-screen narrator or voice-over')
                && str_contains($systemInstruction, 'Give each speaking character an individually specified, distinctive voice identity')
                && str_contains($systemInstruction, 'Keep each named speaker visibly on screen during the line and map every exact line to the action/result visibly performed in that same clip')
                && str_contains($systemInstruction, 'Include `SPEECH/VISUAL MAP:` and relevant character voice profiles in every standalone clip prompt')
                && str_contains($brief, 'Speech mode: On-screen character dialogue; no narrator')
                && str_contains($brief, 'Speech-to-visual alignment: For every exact narration or dialogue line, use a clip-relative time row')
                && str_contains($brief, 'Spoken language: Urdu')
                && str_contains($brief, 'Narration: Off; no voice-over narrator')
                && str_contains($brief, 'Character dialogue: On; on-screen speakers in Urdu')
                && str_contains($brief, 'Character voice direction: Create a distinct, detailed voice identity')
                && str_contains($brief, 'Character identity enforcement: NEW-SEQUENCE CHARACTER LOCK: The saved fictional character is canonical and must not be redesigned.')
                && str_contains($brief, 'Mina, a cautious young inventor with copper goggles.');
        });

        Http::assertSentCount(1);
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

    public function test_opening_visual_beat_and_narration_overrun_are_corrected_once_and_reaudited(): void
    {
        $state = 'cast=Bee (CHAR-01) x1; object=pollen grain P-01 on left hind leg; process=transfer in progress; environment=flower bed; motion=hovering';
        $presentation = 'camera=macro close-up, static; lighting=soft morning sun; audio=quiet garden ambience';
        $draft = "## PROJECT OVERVIEW\nOne clear pollination beat.\nClip 1: 4 seconds\nApproximate total runtime: 4 seconds\n\n### COPY CLIP 1 PROMPT\n```text\nTRANSITION TYPE: INITIAL CLIP — NO PREVIOUS HANDOFF\nVISIBLE CHARACTER CAST: Bee (CHAR-01) ×1\nCHARACTER IDENTITY LOCK: Canonical Bee (CHAR-01), gold body with exact blue wing markings; same individual throughout this clip.\nCLIP DURATION: 4 seconds\nPRIMARY IDEA: Pollen transfers to the bee.\nVISUAL REPRESENTATION: LITERAL SCENE\nVISIBLE CAUSE → ACTION → RESULT: The bee brushes a pollen-bearing anther and pollen sticks to its hind leg.\nOPENING CONTINUITY STATE: {$state}\nENDING CONTINUITY STATE: {$state}\nOPENING PRESENTATION STATE: {$presentation}\nENDING PRESENTATION STATE: {$presentation}\nSPEECH/VISUAL MAP:\n- 0.0–1.0s | VISIBLE ACTION: pollen visibly transfers to the bee’s hind leg | SPEAKER: Narrator | LINE: “The tiny bee carries bright yellow pollen from this flower to another flower.”\nOUTGOING HANDOFF TYPE: FINAL — NO NEXT HANDOFF\n```";
        $corrected = str_replace(
            '- 0.0–1.0s | VISIBLE ACTION: pollen visibly transfers to the bee’s hind leg | SPEAKER: Narrator | LINE: “The tiny bee carries bright yellow pollen from this flower to another flower.”',
            '- 0.2–1.8s | VISIBLE ACTION: pollen visibly transfers to the bee’s hind leg | SPEAKER: Narrator | LINE: “Bee carries pollen.”',
            $draft,
        );
        Http::fake([
            'https://generativelanguage.googleapis.com/*' => Http::sequence()
                ->push(['candidates' => [['content' => ['parts' => [['text' => $draft]]]]]], 200)
                ->push(['candidates' => [['content' => ['parts' => [['text' => $corrected]]]]]], 200),
        ]);
        AiSetting::create([
            'provider' => 'gemini',
            'model' => 'gemini-2.5-flash',
            'api_key' => 'test-provider-secret',
        ]);
        $owner = User::factory()->create();
        $project = $this->makeProject($owner);

        $response = $this->actingAs($owner)
            ->postJson(route('studio.generate', $project), $this->storyBrief())
            ->assertOk()
            ->assertJsonPath('quality_review.status', 'passed')
            ->assertJsonPath('quality_review.corrected', true)
            ->assertJsonPath('quality_review.repair_attempted', true)
            ->assertJsonPath('content', $corrected)
            ->assertJsonPath('saved_to_history', true);

        $this->assertSame([], $response->json('quality_review.issues'));
        Http::assertSentCount(2);
        Http::assertSent(function (ClientRequest $request): bool {
            $correctionBrief = data_get($request->data(), 'contents.0.parts.0.text', '');

            return str_contains($correctionBrief, 'AUTOMATIC QUALITY-CONTROL CORRECTION')
                && str_contains($correctionBrief, 'Shorten it or allocate more AI-selected time')
                && str_contains($correctionBrief, 'Clip 1 starts speech at 0 seconds; reserve at least 0.2 seconds for the opening visual beat.')
                && str_contains($correctionBrief, 'For an opening-beat issue, start the first speech row no earlier than the stated minimum');
        });

        $run = $owner->promptProjects()->firstOrFail();
        $this->assertSame($corrected, $run->content);
        $this->assertTrue($run->quality_review['corrected']);
        $this->assertSame('passed', $run->quality_review['status']);
        $this->assertSame([], $run->quality_review['issues']);
        $this->actingAs($owner)->get(route('history.show', $run))
            ->assertOk()
            ->assertSee('AUTOMATED TEXT QA')
            ->assertSee('TARGETED CORRECTION APPLIED')
            ->assertSee('Not independently verified');
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

    public function test_extension_requires_a_frame_image_or_written_starting_state_before_generation(): void
    {
        $owner = User::factory()->create();
        $project = $this->makeProject($owner);

        $this->actingAs($owner)
            ->postJson(route('studio.generate', $project), [
                'mode' => 'extend',
                'next_action' => 'The character moves toward the flower.',
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['visible_start'])
            ->assertJsonPath('errors.visible_start.0', 'For Extend Video, attach a final-frame image or describe the visible starting state.');

        Http::assertNothingSent();
    }

    public function test_extension_uses_the_projects_separate_saved_extension_duration(): void
    {
        Http::fake([
            'https://generativelanguage.googleapis.com/*' => Http::response([
                'candidates' => [['content' => ['parts' => [['text' => '## EXTENSION PROMPT'."\\n".'Continue from the written starting state; no reference image was supplied.']]]]],
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

        Http::assertSent(function (ClientRequest $request): bool {
            $systemInstructions = data_get($request->data(), 'systemInstruction.parts.0.text', '');
            $brief = data_get($request->data(), 'contents.0.parts.0.text', '');

            return str_contains($brief, 'Clip duration: 6 seconds')
                && str_contains($brief, 'Supplied final-frame image: No. Use only the written starting-state description and requested action. Do not pretend to have seen a video or image.')
                && str_contains($systemInstructions, 'For Extend Video, include it only when an actual reference image is attached; otherwise use text-only starting-state wording.')
                && str_contains($systemInstructions, 'If neither a frame nor a written starting-state description is available, ask the user to provide one instead of drafting an invented continuation.');
        });
    }

    public function test_ai_selects_a_bounded_extension_duration_when_global_duration_questions_are_off(): void
    {
        Http::fake([
            'https://generativelanguage.googleapis.com/*' => Http::response([
                'candidates' => [['content' => ['parts' => [['text' => '## EXTENSION PROMPT'."\n".'Continue from the written starting state; no reference image was supplied.']]]]],
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

            return str_contains($systemInstructions, 'EXTENSION DURATION — AI DIRECTS: Choose an exact whole-second duration that gives this continuation enough time to read clearly')
                && str_contains($brief, 'Clip duration: AI decides from complexity: about 2–3 seconds for a simple beat, 4–6 for a moderately layered action, and 7–10 when coordinated action, transformations, camera moves, multiple subjects, or speech genuinely need room')
                && str_contains($brief, 'Workflow: Extend an existing video');
        });

        $this->assertSame('auto', $owner->promptProjects()->firstOrFail()->brief['clip_duration']);
    }

    public function test_character_dialogue_extension_preserves_saved_voice_mode_without_claiming_to_hear_unseen_audio(): void
    {
        Http::fake([
            'https://generativelanguage.googleapis.com/*' => Http::response([
                'candidates' => [['content' => ['parts' => [['text' => "## EXTENSION PROMPT\nMina continues in-scene.\n"]]]]],
            ], 200),
        ]);
        AiSetting::create([
            'provider' => 'gemini',
            'model' => 'gemini-2.5-flash',
            'api_key' => 'test-provider-secret',
        ]);
        $owner = User::factory()->create();
        $project = $this->makeProject($owner, [
            'narration' => '1',
            'speech_mode' => ProjectSettings::SPEECH_MODE_CHARACTER_DIALOGUE,
            'language' => 'Urdu',
            'character_type' => 'fictional',
            'character_details' => 'Mina, a thoughtful young inventor with copper goggles.',
        ]);

        $this->actingAs($owner)
            ->postJson(route('studio.generate', $project), [
                'mode' => 'extend',
                'next_action' => 'Mina gently lifts the lantern and says a short line to her robot companion.',
                'visible_start' => 'Mina stands beside her robot companion, holding an unlit lantern.',
            ])
            ->assertOk()
            ->assertJsonPath('mode', 'extend')
            ->assertJsonPath('saved_to_history', true);

        Http::assertSent(function (ClientRequest $request): bool {
            $systemInstructions = data_get($request->data(), 'systemInstruction.parts.0.text', '');
            $brief = data_get($request->data(), 'contents.0.parts.0.text', '');

            return str_contains($systemInstructions, 'SPOKEN AUDIO MODE — ON-SCREEN CHARACTER DIALOGUE: Do not add a narrator or voice-over')
                && str_contains($systemInstructions, 'map each exact line to the action/result visibly performed in this extension only')
                && str_contains($systemInstructions, 'A still frame reveals no voice')
                && str_contains($systemInstructions, 'never claim it matches unseen prior audio')
                && str_contains($brief, 'Character identity enforcement: EXTENSION CHARACTER LOCK: No final-frame image is attached; use only the written visible-state description.')
                && str_contains($brief, 'Include `VISIBLE CHARACTER CAST:` and `CHARACTER IDENTITY LOCK:` plus complete evidence-based identity details')
                && str_contains($brief, 'Requested next action: Mina gently lifts the lantern and says a short line to her robot companion.')
                && str_contains($brief, 'Speech mode: On-screen character dialogue; no narrator')
                && str_contains($brief, 'Speech-to-visual alignment: For every exact narration or dialogue line, use a clip-relative time row')
                && str_contains($brief, 'Extension speech continuity: No narrator. Preserve character voice profiles')
                && str_contains($brief, 'Keep the visible on-screen speaker present during each line and map dialogue only to an action/result visibly performed in this extension')
                && str_contains($brief, 'A still frame reveals no voice; do not claim to match unseen audio')
                && str_contains($brief, 'Mina, a thoughtful young inventor with copper goggles.');
        });

        Http::assertSentCount(1);
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
