<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

class AccountAndHistoryTest extends TestCase
{
    use RefreshDatabase;

    public function test_registration_creates_a_regular_user_and_signs_them_in(): void
    {
        $this->post(route('register.store'), [
            'name' => 'Riley Morgan',
            'email' => 'RILEY@example.test',
            'password' => 'StudioPass123!',
            'password_confirmation' => 'StudioPass123!',
            'is_admin' => true,
        ])->assertRedirect('/');

        $user = User::query()->where('email', 'riley@example.test')->firstOrFail();
        $this->assertAuthenticatedAs($user);
        $this->assertFalse($user->is_admin);
        $this->assertTrue(Hash::check('StudioPass123!', $user->password));
        $this->assertSame(0, $user->promptProjects()->count());

        $this->get(route('history.index'))
            ->assertOk()
            ->assertSee('Your prompt library')
            ->assertSee('Your next story starts here.');
    }

    public function test_private_library_and_account_pages_require_authentication(): void
    {
        $this->get(route('history.index'))->assertRedirect('/login');
        $this->get(route('account.edit'))->assertRedirect('/login');
    }

    public function test_sign_in_and_sign_out_work_for_regular_accounts(): void
    {
        $user = User::factory()->create(['password' => 'ExistingPass123!']);

        $this->post(route('login.store'), [
            'email' => strtoupper($user->email),
            'password' => 'ExistingPass123!',
            'remember' => '1',
        ])->assertRedirect('/');

        $this->assertAuthenticatedAs($user);
        $this->post(route('logout'))
            ->assertRedirect('/');
        $this->assertGuest();
    }

    public function test_account_updates_profile_and_password_without_granting_admin_access(): void
    {
        $user = User::factory()->create([
            'password' => 'ExistingPass123!',
            'email_verified_at' => now(),
        ]);

        $this->actingAs($user)
            ->put(route('account.profile.update'), [
                'name' => 'Riley Updated',
                'email' => 'RILEY.NEW@example.test',
                'is_admin' => true,
            ])
            ->assertRedirect(route('account.edit', [], false));

        $user->refresh();
        $this->assertSame('Riley Updated', $user->name);
        $this->assertSame('riley.new@example.test', $user->email);
        $this->assertNull($user->email_verified_at);
        $this->assertFalse($user->is_admin);

        $this->put(route('account.password.update'), [
            'current_password' => 'ExistingPass123!',
            'password' => 'AnotherStudio456!',
            'password_confirmation' => 'AnotherStudio456!',
        ])->assertRedirect(route('account.edit', [], false));

        $user->refresh();
        $this->assertTrue(Hash::check('AnotherStudio456!', $user->password));
        $this->assertAuthenticatedAs($user);
    }

    public function test_password_reset_uses_the_secure_notification_token(): void
    {
        Notification::fake();
        $user = User::factory()->create();

        $this->post(route('password.email'), ['email' => strtoupper($user->email)])
            ->assertRedirect();

        $token = null;
        Notification::assertSentTo($user, ResetPassword::class, function (ResetPassword $notification) use (&$token): bool {
            $token = $notification->token;

            return true;
        });
        $this->assertIsString($token);

        $this->post(route('password.update'), [
            'token' => $token,
            'email' => $user->email,
            'password' => 'RecoveredPass789!',
            'password_confirmation' => 'RecoveredPass789!',
        ])->assertRedirect(route('login', [], false));

        $this->assertTrue(Hash::check('RecoveredPass789!', $user->fresh()->password));
        $this->assertGuest();
    }

    public function test_library_search_and_deletion_are_scoped_to_the_signed_in_owner(): void
    {
        $owner = User::factory()->create();
        $otherUser = User::factory()->create();
        $project = $owner->promptProjects()->create($this->projectData('Flowers for bees', 'Why bees visit flowers'));
        $owner->promptProjects()->create($this->projectData('Rain for gardens', 'How rain helps gardens'));

        $this->actingAs($owner)
            ->get(route('history.index', ['q' => 'Flowers']))
            ->assertOk()
            ->assertSee('Flowers for bees')
            ->assertDontSee('Rain for gardens');

        $this->actingAs($otherUser)
            ->delete(route('history.destroy', $project))
            ->assertNotFound();
        $this->assertDatabaseHas('prompt_projects', ['id' => $project->id]);

        $this->actingAs($owner)
            ->delete(route('history.destroy', $project))
            ->assertRedirect(route('history.index', [], false));
        $this->assertDatabaseMissing('prompt_projects', ['id' => $project->id]);
    }

    private function projectData(string $title, string $topic): array
    {
        return [
            'title' => $title,
            'mode' => 'story',
            'brief' => [
                'topic' => $topic,
                'audience' => 'Families',
                'visual_style' => '3d-family-animation',
                'clip_count' => 4,
                'clip_duration' => 8,
                'aspect_ratio' => '16:9',
            ],
            'content' => "## PROJECT OVERVIEW\n{$topic}",
            'provider' => 'gemini',
            'model' => 'gemini-2.5-flash',
            'reference_frame_attached' => false,
        ];
    }
}
