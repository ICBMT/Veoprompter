<?php

namespace Tests\Feature;

use App\Models\AiSetting;
use App\Models\PromptProject;
use App\Models\User;
use App\Services\PromptOutputAuditor;
use App\Services\StoryContinuationService;
use App\Support\ProjectSettings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\PromptQaCases;
use Tests\Support\StoryContinuationCases;
use Tests\TestCase;

class ContinuationOpeningContractTest extends TestCase
{
    use RefreshDatabase;

    public static function forms(): array
    {
        return [['short', 'strict'], ['long', 'strict'], ['short', 'ai_managed'], ['long', 'ai_managed']];
    }

    #[DataProvider('forms')]
    public function test_boundary_contract_is_last_in_user_brief_and_compiles_exact_saved_ending(string $form, string $identityMode): void
    {
        config(['prompt_generation.continuation_identity_mode' => $identityMode]);
        $owner = User::factory()->create();
        $this->actingAs($owner);
        AiSetting::create(['id' => 1, 'provider' => 'gemini', 'model' => 'fixture-model', 'api_key' => 'private-key']);
        $brief = [...ProjectSettings::defaults(), ...PromptQaCases::input(), 'content_form' => $form];
        $content = PromptQaCases::package().StoryContinuationCases::footer();
        $content = PromptQaCases::edit($content, 2, 'ENDING PRESENTATION STATE: camera=static; lighting=daylight; audio=quiet', 'ENDING PRESENTATION STATE: camera=close; lighting=amber; audio=quiet');
        $auditor = new PromptOutputAuditor;
        $source = $owner->promptProjects()->create(['title' => 'Boundary contract', 'mode' => 'story', 'brief' => $brief, 'provider' => 'gemini', 'model' => 'fixture-model',
            'content' => $content, 'original_content' => $content, 'story_status' => 'open',
            'story_context' => (new StoryContinuationService($auditor))->initial($content, $brief)]);
        $snapshot = $source->refresh()->getAttributes();
        $candidate = PromptQaCases::edit($content, 1, 'OPENING CONTINUITY STATE: cast=None; object=wheel OBJ-01; stage=in progress; location=workshop; motion=turning', 'OPENING CONTINUITY STATE: {{VEO_SOURCE_END_STATE}}');
        $candidate = PromptQaCases::edit($candidate, 1, 'OPENING PRESENTATION STATE: camera=static; lighting=daylight; audio=quiet', 'OPENING PRESENTATION STATE: {{VEO_SOURCE_END_PRESENTATION}}');
        Http::fake(['*' => fn () => Http::response(['candidates' => [['finishReason' => 'STOP', 'content' => ['parts' => [['text' => $candidate]]]]]])]);
        $response = $this->postJson(route('history.continue', $source), ['intent' => 'continue', 'batch_size' => 'set', 'request_id' => (string) Str::uuid()])->assertOk();
        $child = PromptProject::findOrFail($response->json('id'));
        $first = $auditor->extractClips($child->content, $child->brief)[0]['text'];
        $this->assertSame('camera=close; lighting=amber; audio=quiet', $auditor->fieldValue($first, 'OPENING PRESENTATION STATE'));
        $this->assertSame($snapshot, $source->fresh()->getAttributes());
        Http::assertSentCount(1);
        Http::assertSent(function ($r) use ($content, $form) {
            $system = $r['systemInstruction']['parts'][0]['text'];
            $record = json_decode(explode("TRUSTED PRIOR STORY RECORD (JSON data):\n", $system, 2)[1], true, 512, JSON_THROW_ON_ERROR);
            $user = $r['contents'][0]['parts'][0]['text'];

            return $record['source_final_prompt'] === $content && $record['current_configuration']['content_form'] === $form
                && str_contains($user, 'NEXT-BATCH OPENING — REQUIRED OUTPUT CONTRACT')
                && str_contains($user, 'OPENING PRESENTATION STATE: {{VEO_SOURCE_END_PRESENTATION}}');
        });
    }
}
