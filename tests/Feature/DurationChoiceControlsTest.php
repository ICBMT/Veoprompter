<?php

namespace Tests\Feature;

use App\Models\AiSetting;
use App\Models\User;
use App\Support\ProjectSettings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class DurationChoiceControlsTest extends TestCase
{
    use RefreshDatabase;

    public function test_global_duration_questions_default_to_ai_selection_while_account_switches_remain_available(): void
    {
        $settings = AiSetting::current();
        $user = User::factory()->create();

        $this->assertFalse($settings->allow_clip_duration_choice);
        $this->assertFalse($settings->allow_extension_duration_choice);
        $this->assertTrue($user->can_choose_clip_duration);
        $this->assertTrue($user->can_choose_extension_duration);
        $this->assertSame([
            'clip_duration' => false,
            'extend_duration' => false,
        ], ProjectSettings::durationOptionsFor($user));

        $defaults = ProjectSettings::defaults();
        $this->assertSame('Curious children and families', $defaults['audience']);
        $this->assertSame('educational', $defaults['video_type']);
        $this->assertSame('3d-family-animation', $defaults['visual_style']);
        $this->assertSame('auto', $defaults['clip_count']);
        $this->assertSame('9:16', $defaults['aspect_ratio']);
        $this->assertSame('1', $defaults['narration']);
        $this->assertSame('American English', $defaults['narrator_accent']);
        $this->assertTrue($defaults['music']);
        $this->assertTrue($defaults['sound_effects']);

        $effective = ProjectSettings::applyDurationOptions($defaults, ProjectSettings::durationOptionsFor($user));
        $this->assertSame('auto', $effective['clip_count']);
        $this->assertSame('auto', $effective['clip_duration']);
        $this->assertSame('auto', $effective['extend_duration']);
    }

    public function test_only_an_administrator_can_update_the_global_duration_switches(): void
    {
        $admin = User::factory()->create();
        $admin->is_admin = true;
        $admin->save();

        $this->actingAs($admin)
            ->get(route('admin.settings'))
            ->assertOk()
            ->assertSee('Project duration choices')
            ->assertSee('allow_clip_duration_choice', false)
            ->assertSee('allow_extension_duration_choice', false);

        $this->actingAs($admin)
            ->put(route('admin.duration-options.update'), [
                'allow_clip_duration_choice' => '0',
                'allow_extension_duration_choice' => '1',
            ])
            ->assertRedirect(route('admin.settings', [], false));

        $settings = AiSetting::query()->firstOrFail();
        $this->assertFalse($settings->allow_clip_duration_choice);
        $this->assertTrue($settings->allow_extension_duration_choice);

        $customer = User::factory()->create();
        $this->actingAs($customer)
            ->put(route('admin.duration-options.update'), [
                'allow_clip_duration_choice' => '1',
                'allow_extension_duration_choice' => '1',
            ])
            ->assertForbidden();
    }

    public function test_an_admin_can_restrict_duration_choices_for_one_user_account(): void
    {
        $admin = User::factory()->create();
        $admin->is_admin = true;
        $admin->save();
        $settings = AiSetting::current();
        $settings->allow_clip_duration_choice = true;
        $settings->allow_extension_duration_choice = true;
        $settings->save();
        $account = User::factory()->create();

        $this->actingAs($admin)
            ->get(route('admin.users.show', $account))
            ->assertOk()
            ->assertSee('Duration access for this account')
            ->assertSee('can_choose_clip_duration', false)
            ->assertSee('can_choose_extension_duration', false);

        $this->actingAs($admin)
            ->put(route('admin.users.duration-options.update', $account), [
                'can_choose_clip_duration' => '0',
                'can_choose_extension_duration' => '1',
            ])
            ->assertRedirect(route('admin.users.show', $account, false));

        $account->refresh();
        $this->assertFalse($account->can_choose_clip_duration);
        $this->assertTrue($account->can_choose_extension_duration);
        $this->assertSame([
            'clip_duration' => false,
            'extend_duration' => true,
        ], ProjectSettings::durationOptionsFor($account));

        $this->actingAs($account)
            ->get(route('projects.create'))
            ->assertOk()
            ->assertDontSee('How long should each video clip be?')
            ->assertSee('How long should a video extension be?');

        $otherAccount = User::factory()->create();
        $this->actingAs($otherAccount)
            ->put(route('admin.users.duration-options.update', $account), [
                'can_choose_clip_duration' => '1',
                'can_choose_extension_duration' => '1',
            ])
            ->assertForbidden();
    }

    public function test_disabled_global_duration_question_is_hidden_and_ai_selection_is_forced(): void
    {
        $settings = AiSetting::current();
        $settings->allow_clip_duration_choice = false;
        $settings->allow_extension_duration_choice = true;
        $settings->save();
        $owner = User::factory()->create();

        $this->actingAs($owner)
            ->get(route('projects.create'))
            ->assertOk()
            ->assertDontSee('How long should each video clip be?')
            ->assertSee('How long should a video extension be?')
            ->assertSee('name="clip_duration" value="auto"', false)
            ->assertSee('Let AI decide', false);

        $this->actingAs($owner)
            ->post(route('projects.store'), $this->projectPayload([
                'clip_duration' => '20',
                'extend_duration' => '6',
            ]))
            ->assertRedirect();

        $project = $owner->studioProjects()->firstOrFail();
        $this->assertSame('auto', $project->settings['clip_duration']);
        $this->assertSame(6, $project->settings['extend_duration']);

        $this->actingAs($owner)
            ->get(route('projects.settings.edit', $project))
            ->assertOk()
            ->assertSee('Simple beats stay short; complex action or speech can use up to 10 seconds without padding.')
            ->assertSee('AI decides · up to 10 seconds')
            ->assertSee('id="extend_duration"', false);

        $this->put(route('projects.settings.update', $project), $this->projectPayload([
            'name' => 'Updated duration policy project',
            'clip_duration' => '15',
            'extend_duration' => '10',
        ]))->assertRedirect(route('projects.show', $project, false));

        $project->refresh();
        $this->assertSame('auto', $project->settings['clip_duration']);
        $this->assertSame(10, $project->settings['extend_duration']);
    }

    public function test_existing_projects_generate_with_ai_selected_duration_when_duration_access_is_disabled(): void
    {
        Http::fake([
            'https://generativelanguage.googleapis.com/*' => Http::response([
                'candidates' => [[
                    'content' => [
                        'parts' => [['text' => "## PROJECT OVERVIEW\nA short story with a fixed duration."]],
                    ],
                ]],
            ], 200),
        ]);

        $settings = AiSetting::current();
        $settings->api_key = 'duration-test-provider-key';
        $settings->allow_clip_duration_choice = true;
        $settings->allow_extension_duration_choice = true;
        $settings->save();

        $owner = User::factory()->create();
        $owner->can_choose_clip_duration = false;
        $owner->save();
        $project = $owner->studioProjects()->create([
            'name' => 'Existing long-clip project',
            'settings' => array_replace(ProjectSettings::defaults(), [
                'clip_duration' => 18,
                'extend_duration' => 6,
            ]),
        ]);

        $this->actingAs($owner)
            ->postJson(route('studio.generate', $project), [
                'mode' => 'story',
                'topic' => 'A rain cloud helps a garden',
            ])
            ->assertOk()
            ->assertJsonPath('saved_to_history', true);

        $story = $owner->promptProjects()->orderByDesc('id')->firstOrFail();
        $this->assertSame('auto', $story->brief['clip_duration']);
        $effectiveSettings = ProjectSettings::applyDurationOptions(
            $project->fresh()->settings,
            ProjectSettings::durationOptionsFor($owner),
        );
        $this->assertSame('auto', $effectiveSettings['clip_duration'], 'The project page displays the same AI-selected default used for generation.');

        $settings->allow_extension_duration_choice = false;
        $settings->save();
        $this->actingAs($owner)
            ->postJson(route('studio.generate', $project), [
                'mode' => 'extend',
                'next_action' => 'The rain cloud moves on while the garden glows.',
                'visible_start' => 'A small garden shines after a gentle rain.',
            ])
            ->assertOk()
            ->assertJsonPath('saved_to_history', true);

        $extension = $owner->promptProjects()->orderByDesc('id')->firstOrFail();
        $this->assertSame('auto', $extension->brief['clip_duration']);
    }

    public function test_ai_can_choose_the_clip_count_and_both_duration_values_in_project_setup(): void
    {
        $settings = AiSetting::current();
        $settings->allow_clip_duration_choice = true;
        $settings->allow_extension_duration_choice = true;
        $settings->save();
        $owner = User::factory()->create();

        $this->actingAs($owner)
            ->get(route('projects.create'))
            ->assertOk()
            ->assertSee('name="clip_count" value="auto"', false)
            ->assertSee('name="clip_duration" value="auto"', false)
            ->assertSee('name="extend_duration" value="auto"', false)
            ->assertSee('Let AI decide')
            ->assertSee('An AI-selected count is not capped at eight.');

        $this->actingAs($owner)
            ->post(route('projects.store'), $this->projectPayload([
                'clip_count' => 'auto',
                'clip_duration' => 'auto',
                'extend_duration' => 'auto',
            ]))
            ->assertRedirect();

        $project = $owner->studioProjects()->firstOrFail();
        $this->assertSame('auto', $project->settings['clip_count']);
        $this->assertSame('auto', $project->settings['clip_duration']);
        $this->assertSame('auto', $project->settings['extend_duration']);
    }

    public function test_manual_clip_count_choices_remain_bounded_from_two_through_eight(): void
    {
        $owner = User::factory()->create();

        foreach ([2, 8] as $count) {
            $this->actingAs($owner)
                ->post(route('projects.store'), $this->projectPayload(['clip_count' => (string) $count]))
                ->assertRedirect();

            $project = $owner->studioProjects()->latest('id')->firstOrFail();
            $this->assertSame($count, $project->settings['clip_count']);
        }

        $this->actingAs($owner)
            ->from(route('projects.create'))
            ->post(route('projects.store'), $this->projectPayload(['clip_count' => '9']))
            ->assertSessionHasErrors('clip_count');
    }

    /** @param array<string, string> $overrides @return array<string, string> */
    private function projectPayload(array $overrides = []): array
    {
        return array_replace([
            'name' => 'Fixed duration project',
            'audience' => 'Curious families',
            'video_type' => 'educational',
            'visual_style' => '3d-family-animation',
            'character_type' => 'ai-choice',
            'clip_count' => '4',
            'clip_duration' => '10',
            'extend_duration' => '12',
            'aspect_ratio' => '16:9',
            'educational_depth' => 'clear',
            'narration' => '0',
        ], $overrides);
    }
}
