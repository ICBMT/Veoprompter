<?php

namespace Tests\Feature;

use App\Models\AiSetting;
use App\Models\PromptProject;
use App\Models\User;
use App\Services\PromptOutputAuditor;
use App\Services\StoryContinuationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\PromptQaCases;
use Tests\Support\StoryContinuationCases;
use Tests\TestCase;

class ContinuationRosterLayoutTest extends TestCase
{
    use RefreshDatabase;

    public static function layouts(): array
    {
        return [
            'bullets' => ["CANONICAL CHARACTER ROSTER:\n- Mira (CHAR-01) ×1", false],
            'table' => ["CANONICAL CHARACTER ROSTER:\n| Character ID | Name | Count |\n| --- | --- | --- |\n| CHAR-01 | Mira | 1 |", false],
            'heading' => ["### CANONICAL CHARACTER ROSTER\n1. Mira (CHAR-01) ×1", false],
            'legacy bullets' => ["CANONICAL CHARACTER ROSTER:\n- Mira (CHAR-01) ×1", true],
            'legacy table' => ["CANONICAL CHARACTER ROSTER:\n| ID | Name | Count |\n| --- | --- | --- |\n| CHAR-01 | Mira | 1 |", true],
        ];
    }

    #[DataProvider('layouts')]
    public function test_explicit_block_rosters_continue_without_rewriting_the_saved_source(string $layout, bool $legacy): void
    {
        $owner = User::factory()->create();
        $this->actingAs($owner);
        $text = str_replace('CANONICAL CHARACTER ROSTER: CHAR-01 ×1', $layout,
            PromptQaCases::characters(PromptQaCases::package())).StoryContinuationCases::footer();
        $brief = [...PromptQaCases::input(), 'content_form' => 'long'];
        $auditor = new PromptOutputAuditor;
        $stories = new StoryContinuationService($auditor);
        $this->assertSame([], $auditor->audit($text, $brief)['issues']);
        $context = $stories->initial($text, $brief);
        $this->assertSame(['CHAR-01' => 1], $context['approved_cast']);
        if ($legacy) {
            $context['approved_cast'] = [];
        }
        $source = $owner->promptProjects()->create([
            'title' => 'Explicit roster', 'mode' => 'story', 'provider' => 'gemini', 'model' => 'fixture-model', 'brief' => $brief, 'content' => $text,
            'original_content' => $text, 'story_status' => 'open', 'story_context' => $context,
            'quality_review' => $auditor->audit($text, $brief),
        ])->refresh();
        $original = $source->getAttributes();
        AiSetting::create(['id' => 1, 'provider' => 'gemini', 'model' => 'fixture-model', 'api_key' => 'fake-key']);
        // The provider may repeat the block but omit the identity definition: compilation must not split the roster block.
        $draft = preg_replace('/^CANONICAL IDENTITY CHAR-01:.*\R/mu', '', $text);
        Http::fake(['*' => Http::response(['candidates' => [['content' => ['parts' => [['text' => $draft]]], 'finishReason' => 'STOP']]])]);
        $response = $this->postJson(route('history.continue', $source), [
            'intent' => 'continue', 'batch_size' => 'set', 'direction' => '', 'request_id' => (string) Str::uuid(),
        ])->assertOk();
        $child = PromptProject::findOrFail($response->json('id'));
        $this->assertSame(['CHAR-01' => 1], $child->story_context['approved_cast']);
        $this->assertSame([], $child->quality_review['issues']);
        $this->assertSame($original, $source->fresh()->getAttributes());
        Http::assertSentCount(1);
    }

    public function test_bulleted_identity_definitions_end_the_roster_rather_than_becoming_extra_roster_rows(): void
    {
        $text = str_replace('CANONICAL CHARACTER ROSTER: CHAR-01 ×1',
            "CANONICAL CHARACTER ROSTER:\n- Mira (CHAR-01) ×1", PromptQaCases::characters(PromptQaCases::package()));
        $text = preg_replace('/^CANONICAL IDENTITY/m', '- CANONICAL IDENTITY', $text);
        $auditor = new PromptOutputAuditor;
        $this->assertSame(['CHAR-01 ×1'], $auditor->fieldValues($text, 'CANONICAL CHARACTER ROSTER'));
        $this->assertSame([], $auditor->audit($text, PromptQaCases::input())['issues']);
    }

    public function test_table_counts_are_explicit_and_not_silently_defaulted_to_one(): void
    {
        $text = str_replace('CANONICAL CHARACTER ROSTER: CHAR-01 ×1',
            "CANONICAL CHARACTER ROSTER:\n| ID | Name | Count |\n| --- | --- | --- |\n| CHAR-01 | Mira | 2 |", PromptQaCases::characters(PromptQaCases::package()));
        $auditor = new PromptOutputAuditor;
        $this->assertSame(['CHAR-01 ×2'], $auditor->fieldValues($text, 'CANONICAL CHARACTER ROSTER'));
        $this->assertContains('cast_count_mismatch', array_column($auditor->audit($text, PromptQaCases::input())['issues'], 'code'));
    }

    public function test_unreadable_or_mixed_rosters_never_borrow_ids_from_later_identity_definitions(): void
    {
        foreach ([
            '- Mira', "- CHAR-01\n- Unidentified person", "- None\n- CHAR-01", "- CHAR-01\n- CHAR-01",
            "| ID | Count |\n| --- | --- |\n| CHAR-01 | unknown |", 'SECTION 4 — OTHER DATA',
        ] as $rows) {
            $text = str_replace('CANONICAL CHARACTER ROSTER: CHAR-01 ×1', "CANONICAL CHARACTER ROSTER:\n".$rows,
                PromptQaCases::characters(PromptQaCases::package()));
            $codes = array_column((new PromptOutputAuditor)->audit($text, PromptQaCases::input())['issues'], 'code');
            $this->assertContains('canonical_roster_unparseable', $codes, $rows);
        }
    }
}
