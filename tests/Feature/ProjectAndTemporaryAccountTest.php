<?php

namespace Tests\Feature;

use App\Models\StudioProject;
use App\Models\User;
use App\Support\ProjectSettings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ProjectAndTemporaryAccountTest extends TestCase
{
    use RefreshDatabase;

    public function test_project_creation_saves_preferences_and_the_owner_can_update_them_later(): void
    {
        $owner = User::factory()->create();
        $payload = [
            'name' => 'Urdu space stories',
            'audience' => 'Urdu-speaking young learners',
            'video_type' => 'educational',
            'visual_style' => 'custom',
            'style_detail' => 'Soft paper-cut planets with warm light',
            'character_type' => 'fictional',
            'character_details' => 'A shy moon fox with a silver tail',
            'clip_count' => '5',
            'clip_duration' => '10',
            'extend_duration' => '6',
            'aspect_ratio' => '9:16',
            'educational_depth' => 'balanced',
            'narration' => '1',
            'language' => 'Urdu',
            'narrator_voice' => 'feminine',
            'narrator_accent' => 'Pakistani English',
            'narrator_tone' => 'calm and thoughtful',
            'music' => '1',
            'sound_effects' => '0',
        ];

        $this->actingAs($owner)
            ->post(route('projects.store'), $payload)
            ->assertRedirect();

        $project = $owner->studioProjects()->firstOrFail();
        $this->assertSame('Urdu space stories', $project->name);
        $this->assertSame('A shy moon fox with a silver tail', $project->settings['character_details']);
        $this->assertSame('Urdu', $project->settings['language']);
        $this->assertSame('educational', $project->settings['video_type']);
        $this->assertSame(6, $project->settings['extend_duration']);
        $this->assertTrue($project->settings['music']);
        $this->assertFalse($project->settings['sound_effects']);

        $this->actingAs($owner)
            ->get(route('projects.settings.edit', $project))
            ->assertOk()
            ->assertSee('Urdu space stories')
            ->assertSee('A shy moon fox with a silver tail');

        $updated = [...$payload, 'name' => 'Moon fox learning', 'video_type' => 'cinematic-story', 'clip_duration' => '12', 'narration' => '0'];
        unset($updated['language'], $updated['narrator_voice'], $updated['narrator_accent'], $updated['narrator_tone']);
        $this->actingAs($owner)
            ->put(route('projects.settings.update', $project), $updated)
            ->assertRedirect(route('projects.show', $project, false));

        $project->refresh();
        $this->assertSame('Moon fox learning', $project->name);
        $this->assertSame(12, $project->settings['clip_duration']);
        $this->assertSame('0', $project->settings['narration']);
        $this->assertSame('cinematic-story', $project->settings['video_type']);
        $this->assertSame('Urdu', $project->settings['language'], 'Disabled voice controls keep their saved values when narration is turned off.');
    }

    public function test_project_is_private_to_its_owner(): void
    {
        $owner = User::factory()->create();
        $other = User::factory()->create();
        $project = $owner->studioProjects()->create([
            'name' => 'Private project',
            'settings' => ProjectSettings::defaults(),
        ]);

        $this->actingAs($other)->get(route('projects.show', $project))->assertNotFound();
        $this->actingAs($other)->get(route('projects.settings.edit', $project))->assertNotFound();
        $this->actingAs($other)->put(route('projects.settings.update', $project), [])->assertNotFound();
    }

    public function test_administrator_can_create_customer_and_admin_test_accounts_that_expire_after_exactly_three_hours(): void
    {
        $admin = User::factory()->create();
        $admin->is_admin = true;
        $admin->save();

        $response = $this->actingAs($admin)->post(route('admin.users.test-accounts.store'), [
            'name' => 'Temporary reviewer',
            'email' => 'temp-admin@example.test',
            'account_type' => 'admin',
        ])->assertRedirect(route('admin.users.index', [], false));

        $testAccount = User::query()->where('email', 'temp-admin@example.test')->firstOrFail();
        $this->assertTrue($testAccount->is_admin);
        $this->assertNotNull($testAccount->expires_at);
        $this->assertSame(3 * 60 * 60, $testAccount->expires_at->timestamp - $testAccount->created_at->timestamp);
        $temporaryAdminPassword = session('test_account_credentials.password');
        $this->assertTrue(password_verify($temporaryAdminPassword, $testAccount->password));
        $this->assertSessionHas('test_account_credentials.email', 'temp-admin@example.test');
        $this->get(route('admin.users.index'))->assertOk()->assertSee('Temporary password')->assertSee($temporaryAdminPassword);

        $this->post(route('admin.users.test-accounts.store'), [
            'name' => 'Temporary customer',
            'email' => 'temp-customer@example.test',
            'account_type' => 'customer',
        ])->assertRedirect();
        $customer = User::query()->where('email', 'temp-customer@example.test')->firstOrFail();
        $this->assertFalse($customer->is_admin);
        $this->assertSame(3 * 60 * 60, $customer->expires_at->timestamp - $customer->created_at->timestamp);
        $this->assertNotSame($testAccount->password, session('test_account_credentials.password'));

        $this->post(route('logout'))->assertRedirect('/');
        $this->post(route('login.store'), [
            'email' => $testAccount->email,
            'password' => $temporaryAdminPassword,
        ])->assertRedirect('/');
        $this->assertAuthenticatedAs($testAccount);
        $this->get(route('admin.settings'))->assertOk();

        $this->travelTo($testAccount->expires_at);
        $this->get(route('admin.settings'))
            ->assertRedirect('/login')
            ->assertSessionHasErrors('email');
        $this->assertGuest();
        $this->travelBack();
    }

    public function test_expired_test_users_are_logged_out_and_cannot_sign_in_again(): void
    {
        $expired = User::factory()->create([
            'password' => 'TemporaryPass123!',
            'expires_at' => now()->subSecond(),
        ]);

        $this->actingAs($expired)
            ->get(route('projects.index'))
            ->assertRedirect('/login')
            ->assertSessionHasErrors('email');
        $this->assertGuest();

        $this->post(route('login.store'), [
            'email' => $expired->email,
            'password' => 'TemporaryPass123!',
        ])->assertRedirect(route('login', [], false))->assertSessionHasErrors('email');
        $this->assertGuest();
    }

    public function test_admin_presence_reports_recent_users_online_and_expired_users_offline(): void
    {
        $admin = User::factory()->create();
        $admin->is_admin = true;
        $admin->save();
        $online = User::factory()->create(['last_seen_at' => now()->subMinute()]);
        $offline = User::factory()->create(['last_seen_at' => now()->subMinutes(8)]);
        $expired = User::factory()->create(['last_seen_at' => now(), 'expires_at' => now()->subSecond()]);

        $this->actingAs($admin)->getJson(route('admin.users.presence'))
            ->assertOk()
            ->assertJsonPath('users.'.$online->id.'.online', true)
            ->assertJsonPath('users.'.$offline->id.'.online', false)
            ->assertJsonPath('users.'.$expired->id.'.online', false);
    }
}
