<?php

namespace Tests\Feature;

use App\Models\SubtitleVideo;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use Tests\TestCase;

class ThrottleIsolationTest extends TestCase
{
    use RefreshDatabase;

    public function test_every_operation_has_an_independent_throttle_scope(): void
    {
        $scopes = [];
        foreach (Route::getRoutes() as $route) {
            foreach ($route->gatherMiddleware() as $middleware) {
                if (! str_starts_with($middleware, 'throttle:')) {
                    continue;
                }
                $parts = explode(',', substr($middleware, strlen('throttle:')));
                $this->assertCount(3, $parts, 'Missing scope on '.$route->getName());
                $this->assertNotEmpty($parts[2]);
                $this->assertNotContains($parts[2], $scopes, 'Shared operation quota on '.$route->getName());
                $scopes[] = $parts[2];
            }
        }
        $this->assertCount(18, $scopes);
    }

    public function test_presence_quota_does_not_consume_voice_or_subtitle_polling_quota(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user);
        $video = SubtitleVideo::create([
            'user_id' => $user->id, 'upload_token' => (string) Str::uuid(),
            'original_name' => 'qa.mp4', 'size_bytes' => 20, 'status' => 'uploaded',
            'settings' => [],
        ]);
        $this->postJson(route('presence.ping'))->assertSuccessful();
        $this->postJson(route('presence.ping'))->assertSuccessful();
        $this->postJson(route('presence.ping'))->assertStatus(429);
        for ($i = 0; $i < 12; $i++) {
            $this->getJson(route('subtitles.status', $video))->assertOk();
        }
        $this->postJson(route('voice-samples.store'), [])->assertStatus(422);
    }
}
