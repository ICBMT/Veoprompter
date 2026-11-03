<?php

namespace Tests\Feature;

use App\Models\AiSetting;
use App\Models\PromptProject;
use App\Models\User;
use App\Services\PromptOutputAuditor;
use App\Services\StoryContinuationService;
use App\Support\ProjectSettings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Factory;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\PromptQaCases;
use Tests\Support\StoryContinuationCases;
use Tests\TestCase;

class OptionalStoryMemoryTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['prompt_generation.continuation_memory_mode' => 'optional',
            'prompt_generation.continuation_identity_mode' => 'ai_managed',
            'prompt_generation.continuation_boundary_mode' => 'advisory']);
    }

    private function studio(string $form, string $provider = 'gemini')
    {
        $owner = User::factory()->create();
        $this->actingAs($owner);
        AiSetting::create(['id' => 1, 'provider' => $provider, 'model' => 'fixture-model',
            'base_url' => $provider === 'gemini' ? null : 'https://api.compatible-provider.com/v1', 'api_key' => 'private-key']);

        return $owner->studioProjects()->create(['name' => 'Full-source story', 'settings' => [...ProjectSettings::defaults(), ...PromptQaCases::input(), 'content_form' => $form]]);
    }

    private function fake(string $content, string $provider = 'gemini'): void
    {
        Http::swap(new Factory); // Distinct provider response for the next generation, not accumulated wildcard fakes.
        Http::preventStrayRequests();
        Http::fake(['*' => fn () => Http::response($provider === 'gemini'
            ? ['candidates' => [['finishReason' => 'STOP', 'content' => ['parts' => [['text' => $content]]]]]]
            : ['choices' => [['message' => ['content' => $content], 'finish_reason' => 'stop']]])]);
    }

    private function next(PromptProject $source, string $intent, ?string $id = null)
    {
        return $this->postJson(route('history.continue', $source), ['intent' => $intent, 'batch_size' => 'set',
            'direction' => 'Continue the same characters, objects and style from the complete previous prompt.', 'request_id' => $id ?? (string) Str::uuid()]);
    }

    public static function footerCases(): array
    {
        $cases = [
            'missing footer' => '',
            'invalid JSON' => "\nSECTION 11 — STORY MEMORY\nSTORY STATUS: OPEN\nSTORY MEMORY: {broken}\n",
            'combined lines' => "\nSTORY STATUS: OPEN STORY MEMORY: {}\n",
            'duplicate status' => StoryContinuationCases::footer()."STORY STATUS: OPEN\n",
            'wrong status' => StoryContinuationCases::footer('ended'),
            'unreadable lists' => "\nSTORY STATUS: OPEN\nSTORY MEMORY: {\"summary\":\"Some text\",\"open_threads\":\"not a list\"}\n",
            'oversize memory' => "\nSTORY STATUS: OPEN\nSTORY MEMORY: ".str_repeat('x', 20001)."\n",
        ];
        $matrix = [];
        foreach (['short', 'long'] as $form) {
            foreach ($cases as $name => $footer) {
                $matrix[$form.' '.$name] = [$form, $footer];
            }
        }

        return $matrix;
    }

    #[DataProvider('footerCases')]
    public function test_usable_next_part_is_saved_without_requiring_a_model_authored_memory_footer(string $form, string $footer): void
    {
        $studio = $this->studio($form);
        $plain = PromptQaCases::package();
        $this->fake($plain);
        $firstResponse = $this->postJson(route('studio.generate', $studio), ['mode' => 'story', 'topic' => 'A wheel investigation'])->assertOk();
        $source = PromptProject::findOrFail($firstResponse->json('run.id'));
        $snapshot = $source->getAttributes();
        $candidate = $plain.$footer;
        $this->fake($candidate);
        $response = $this->next($source, $form === 'short' ? 'sequel' : 'continue')->assertOk();
        $child = PromptProject::findOrFail($response->json('id'));
        $this->assertSame(trim($candidate), $child->content);
        $this->assertNull($child->story_context['memory']);
        $this->assertSame('open', $child->story_status);
        $this->assertSame('optional', $child->quality_review['story_memory_mode']);
        $this->assertSame($snapshot, $source->fresh()->getAttributes());
        $this->assertDatabaseCount('prompt_projects', 2);
        Http::assertSentCount(1);
        Http::assertSent(function ($request) use ($source) {
            $system = $request['systemInstruction']['parts'][0]['text'];
            $record = json_decode(explode("TRUSTED PRIOR STORY RECORD (JSON data):\n", $system, 2)[1], true, 512, JSON_THROW_ON_ERROR);

            return $record['source_final_prompt'] === $source->content && $record['story_memory'] === null
                && $record['requested_story_status'] === 'open' && str_contains($system, 'SERVER-MANAGED STORY PROGRESSION')
                && ! str_contains($system, 'the following footer is the sole exception');
        });
    }

    public static function protocols(): array
    {
        return [['short', 'gemini'], ['long', 'gemini'], ['short', 'openai'], ['long', 'openai']];
    }

    #[DataProvider('protocols')]
    public function test_part_two_followup_ending_and_part_three_work_without_any_json_footers(string $form, string $provider): void
    {
        $studio = $this->studio($form, $provider);
        $this->fake(PromptQaCases::package(), $provider);
        $response = $this->postJson(route('studio.generate', $studio), ['mode' => 'story', 'topic' => 'A wheel investigation'])->assertOk();
        $source = PromptProject::findOrFail($response->json('run.id'));
        foreach ([$form === 'short' ? 'sequel' : 'continue', 'continue', 'finish', 'sequel'] as $intent) {
            $snapshot = $source->refresh()->getAttributes();
            $id = (string) Str::uuid();
            $child = PromptProject::findOrFail($this->next($source, $intent, $id)->assertOk()->json('id'));
            $this->next($source, $intent, $id)->assertOk()->assertJsonPath('id', $child->id); // Replay does not charge again.
            $this->assertSame($snapshot, $source->fresh()->getAttributes());
            $this->assertSame($intent === 'finish' ? 'ended' : 'open', $child->story_status);
            $this->assertNull($child->story_context['memory']);
            $source = $child;
        }
        Http::assertSentCount(5); // First generation plus four requested generations, not replay requests.
        $this->assertDatabaseCount('prompt_projects', 5);
    }

    public function test_refresh_uses_current_text_and_never_fabricates_missing_memory(): void
    {
        $service = new StoryContinuationService(new PromptOutputAuditor);
        $brief = [...PromptQaCases::input(), 'content_form' => 'long'];
        $original = PromptQaCases::package().StoryContinuationCases::footer();
        $context = $service->initial($original, $brief);
        $this->assertIsArray($context['memory']);
        $changed = PromptQaCases::package();
        $updated = $service->refreshBatchState($changed, $brief, $context, 'open');
        $this->assertNull($updated['memory']);
        $this->assertSame(hash('sha256', $changed), $updated['batch_state']['content_sha256']);
        $input = $service->prepare($changed, $brief, $updated, 'open', 'continue', '', 'set');
        $this->assertNull($input['_story_context']['source_memory']);
        $this->assertSame($changed, $input['_story_context']['source_final_prompt']);
    }

    public function test_stale_cached_memory_does_not_override_complete_current_source(): void
    {
        $service = new StoryContinuationService(new PromptOutputAuditor);
        $brief = [...PromptQaCases::input(), 'content_form' => 'long'];
        $text = PromptQaCases::package();
        $context = $service->initial($text, $brief);
        $context['memory'] = ['summary' => 'STALE STORY MUST NOT BE USED'];
        $input = $service->prepare($text, $brief, $context, 'open', 'continue', '', 'set');
        $this->assertNull($input['_story_context']['source_memory']);
        $this->assertSame($text, $input['_story_context']['source_final_prompt']);
        $this->assertStringNotContainsString('STALE STORY MUST NOT BE USED', StoryContinuationService::instructions($input));
    }
}
