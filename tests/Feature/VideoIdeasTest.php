<?php

namespace Tests\Feature;

use App\Models\AiSetting;
use App\Models\User;
use App\Services\Ideas\LiveTrendFeed;
use App\Services\Ideas\VideoIdeaGenerator;
use App\Services\PromptGenerationException;
use App\Support\ProjectSettings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\TestCase;

class VideoIdeasTest extends TestCase
{
    use RefreshDatabase;

    private function setupUser(): void
    {
        $this->travelTo(now()->setDate(2026, 10, 15)->setTime(10, 0));
        $this->actingAs(User::factory()->create());
        AiSetting::create(['id' => 1, 'provider' => 'openai', 'model' => 'fixture', 'api_key' => 'never-visible', 'base_url' => 'https://api.example.test/v1']);
    }

    private function rss(): string
    {
        return '<rss><channel><item><title>Clean energy</title><pubDate>'.now()->toRfc2822String().'</pubDate></item></channel></rss>';
    }

    private function suggestions(): array
    {
        return ['ideas' => array_map(fn ($i) => ['title' => 'Creative angle '.$i, 'hook' => 'A clear visual hook', 'angle' => 'An editable suggestion, not a verified news fact', 'source_id' => 1,
            'answers' => ['topic' => 'Energy angle '.$i, 'learning_objective' => 'Explain a useful concept', 'creative_direction' => 'Show the real mechanism with clear visuals', 'character_idea' => 'A turbine']], range(1, 10))];
    }

    public function test_full_idea_selection_prefills_wizard_then_empty_studio_fields_without_generating(): void
    {
        $this->setupUser();
        Http::preventStrayRequests();
        Http::fake(['trends.google.com/*' => Http::response($this->rss(), 200, ['Content-Type' => 'application/rss+xml']),
            'api.example.test/*' => Http::response(['choices' => [['message' => ['content' => json_encode($this->suggestions())], 'finish_reason' => 'stop']]], 200, ['Content-Type' => 'application/json'])]);
        $body = ['region' => 'PK', 'video_type' => 'educational', 'audience' => 'New learners', 'request_id' => (string) Str::uuid()];
        $this->post(route('ideas.generate'), $body)->assertRedirect(route('ideas.index'));
        $batch = session('video_ideas.batch');
        $this->assertCount(10, $batch['ideas']);
        $this->assertSame('https://trends.google.com/trending?geo=PK&q=Clean%20energy', $batch['ideas'][0]['source']['url']);
        $this->get(route('ideas.index'))->assertOk()->assertSee('Creative angle 1')->assertDontSee('never-visible');
        $this->post(route('ideas.generate'), $body)->assertRedirect(route('ideas.index'));
        Http::assertSentCount(2); // Replayed POST never bills a second AI call.
        $this->post(route('ideas.choose'), ['batch_id' => $batch['id'], 'index' => 0])->assertRedirect();
        $draft = session('video_ideas.draft');
        $this->get(route('projects.create', ['idea_draft' => $draft['token']]))->assertOk()->assertSee('Creative angle 1')->assertSee('New learners');
        $settings = ProjectSettings::defaults();
        $response = $this->post(route('projects.store'), [...$settings, 'name' => $draft['title'], 'audience' => $draft['audience'], 'idea_draft' => $draft['token']]);
        $response->assertRedirect();
        $this->get($response->headers->get('Location'))->assertOk()->assertSee('data-idea-prefill', false)->assertSee('Energy angle 1');
        $this->assertDatabaseCount('studio_projects', 1);
        $this->assertDatabaseCount('prompt_projects', 0);
        Http::assertSentCount(2);
    }

    public function test_feed_failure_never_calls_the_model_or_invents_today_trends(): void
    {
        $this->setupUser();
        Http::preventStrayRequests();
        Http::fake(['trends.google.com/*' => Http::response('Unavailable', 503)]);
        $this->from(route('ideas.index'))->post(route('ideas.generate'), ['region' => 'PK', 'video_type' => 'travel', 'audience' => 'Travellers', 'request_id' => (string) Str::uuid()])->assertSessionHasErrors('ideas');
        $this->assertNull(session('video_ideas.batch'));
        Http::assertSentCount(1);
    }

    public function test_feed_filters_stale_future_duplicate_and_entity_content(): void
    {
        $this->setupUser();
        $feed = new LiveTrendFeed;
        $rss = '<rss><channel>';
        foreach ([['Today', now()], ['today', now()], ['Yesterday', now()->subDays(2)], ['Future', now()->addDays(2)]] as [$title, $date]) {
            $rss .= '<item><title>'.$title.'</title><pubDate>'.$date->toRfc2822String().'</pubDate></item>';
        }
        $this->assertCount(1, $feed->parse($rss.'</channel></rss>', 'PK'));
        $this->assertSame([], $feed->parse('<!DOCTYPE rss [<!ENTITY x SYSTEM "file:///etc/passwd">]><rss/>', 'PK'));
        $this->assertSame([], $feed->parse('<broken', 'PK'));
    }

    public function test_all_video_types_are_supported_without_accepting_invented_sources(): void
    {
        $this->setupUser();
        $service = new VideoIdeaGenerator;
        $feed = ['evidence' => [['id' => 1, 'topic' => 'Clean energy', 'url' => 'https://trends.google.com/', 'published_at' => now()->toIso8601String()]]];
        foreach (array_keys(ProjectSettings::videoTypes()) as $type) {
            $this->assertCount(10, $service->validate(json_encode($this->suggestions()), $feed, $type));
        }
        $bad = $this->suggestions(); $bad['ideas'][0]['source_id'] = 999;
        $this->expectException(PromptGenerationException::class);
        $service->validate(json_encode($bad), $feed, 'educational');
    }

    public function test_expired_or_other_session_ideas_cannot_create_drafts(): void
    {
        $this->setupUser();
        $this->post(route('ideas.choose'), ['batch_id' => (string) Str::uuid(), 'index' => 0])->assertConflict();
        $this->assertDatabaseCount('studio_projects', 0);
    }
}
