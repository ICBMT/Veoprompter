<?php

namespace Tests\Unit;

use App\Services\PromptGenerationException;
use App\Services\ProviderTextStream;
use GuzzleHttp\Psr7\FnStream;
use GuzzleHttp\Psr7\Utils;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class ProviderTextStreamTest extends TestCase
{
    private static function frame(array $payload): string
    {
        return 'data: '.json_encode($payload, JSON_UNESCAPED_UNICODE)."\n\n";
    }

    private static function delta(string $content, ?string $reason = null): string
    {
        return self::frame(['choices' => [['index' => 0, 'delta' => ['content' => $content], 'finish_reason' => $reason]]]);
    }

    public static function chunkSizes(): array
    {
        return [[1], [2], [7], [8192]];
    }

    #[DataProvider('chunkSizes')]
    public function test_chunk_boundaries_utf8_heartbeats_usage_and_private_reasoning(int $chunkSize): void
    {
        $wire = ": heartbeat\n\n".self::frame(['choices' => [['index' => 0, 'delta' => ['reasoning_content' => 'PRIVATE'], 'finish_reason' => null]]])
            .self::delta('A wheel — ').self::delta('لاہور').self::delta('', 'stop')
            .self::frame(['choices' => [], 'usage' => ['total_tokens' => 31711]])."data: [DONE]\n\n";
        $body = Utils::streamFor(str_replace("\n", "\r\n", $wire));
        $chunks = FnStream::decorate($body, ['read' => fn ($length) => $body->read(min($length, $chunkSize))]);
        $result = (new ProviderTextStream)->read($chunks);
        $this->assertSame('A wheel — لاہور', $result['choices'][0]['message']['content']);
        $this->assertSame('stop', $result['choices'][0]['finish_reason']);
    }

    public static function failures(): array
    {
        return [
            'missing done' => [self::delta('Partial', 'stop')],
            'missing finish' => [self::delta('Partial')."data: [DONE]\n\n"],
            'empty' => [self::delta('', 'stop')."data: [DONE]\n\n"],
            'midstream error' => [self::delta('Partial').self::frame(['error' => ['message' => 'SECRET']])."data: [DONE]\n\n"],
            'malformed json' => ["data: {broken}\n\n"],
            'safety block' => [self::delta('Blocked', 'content_filter')."data: [DONE]\n\n"],
            'tool result' => [self::delta('Not final', 'tool_calls')."data: [DONE]\n\n"],
            'content after finish' => [self::delta('Text', 'stop').self::delta('Extra')."data: [DONE]\n\n"],
            'oversize event' => [str_repeat('x', 1024 * 1024 + 1)],
        ];
    }

    #[DataProvider('failures')]
    public function test_incomplete_or_failed_streams_are_not_successes(string $wire): void
    {
        $this->expectException(PromptGenerationException::class);
        (new ProviderTextStream)->read(Utils::streamFor($wire));
    }

    public function test_length_finish_is_preserved_for_existing_truncation_gate(): void
    {
        $result = (new ProviderTextStream)->read(Utils::streamFor(self::delta('Partial', 'length')."data: [DONE]\n\n"));
        $this->assertSame('length', $result['choices'][0]['finish_reason']);
    }

    public function test_deadline_is_enforced_even_with_a_readable_stream(): void
    {
        $this->expectException(PromptGenerationException::class);
        (new ProviderTextStream)->read(Utils::streamFor(self::delta('Text', 'stop')."data: [DONE]\n\n"), -1);
    }

    public function test_gemini_assembles_fragmented_final_text_without_thoughts(): void
    {
        $wire = self::frame(['candidates' => [['content' => ['parts' => [['thought' => true, 'text' => 'PRIVATE']]]]]])
            .self::frame(['candidates' => [['content' => ['parts' => [['text' => 'Hello ']]]]]])
            .self::frame(['candidates' => [['finishReason' => 'STOP', 'content' => ['parts' => [['text' => 'لاہور']]]]]])
            .self::frame(['usageMetadata' => ['totalTokenCount' => 31711]]);
        $body = Utils::streamFor($wire);
        $chunks = FnStream::decorate($body, ['read' => fn ($length) => $body->read(1)]);
        $result = (new ProviderTextStream)->read($chunks, 300, 'gemini');
        $this->assertSame('Hello لاہور', $result['candidates'][0]['content']['parts'][0]['text']);
        $this->assertSame('STOP', $result['candidates'][0]['finishReason']);
    }

    public static function geminiFailures(): array
    {
        return [
            'no finish' => [self::frame(['candidates' => [['content' => ['parts' => [['text' => 'Partial']]]]]])],
            'blocked' => [self::frame(['promptFeedback' => ['blockReason' => 'SAFETY']])],
            'safety stop' => [self::frame(['candidates' => [['finishReason' => 'SAFETY', 'content' => ['parts' => [['text' => 'Partial']]]]]])],
            'invalid candidates' => [self::frame(['candidates' => 'invalid'])],
            'invalid parts' => [self::frame(['candidates' => [['content' => ['parts' => 'invalid']]]])],
            'private thoughts only' => [self::frame(['candidates' => [['finishReason' => 'STOP', 'content' => ['parts' => [['thought' => true, 'text' => 'PRIVATE']]]]]])],
        ];
    }

    #[DataProvider('geminiFailures')]
    public function test_failed_gemini_streams_are_not_accepted(string $wire): void
    {
        $this->expectException(PromptGenerationException::class);
        (new ProviderTextStream)->read(Utils::streamFor($wire), 300, 'gemini');
    }

    public function test_gemini_token_limit_is_preserved_for_the_rejection_gate(): void
    {
        $wire = self::frame(['candidates' => [['finishReason' => 'MAX_TOKENS', 'content' => ['parts' => [['text' => 'Partial']]]]]]);
        $result = (new ProviderTextStream)->read(Utils::streamFor($wire), 300, 'gemini');
        $this->assertSame('MAX_TOKENS', $result['candidates'][0]['finishReason']);
    }

    public function test_multiple_openai_candidates_are_not_merged(): void
    {
        $wire = self::frame(['choices' => [
            ['delta' => ['content' => 'Selected'], 'finish_reason' => 'stop'],
            ['delta' => ['content' => 'Other identity'], 'finish_reason' => 'stop'],
        ]])."\ndata: [DONE]\n\n";
        $result = (new ProviderTextStream)->read(Utils::streamFor($wire));
        $this->assertSame('Selected', $result['choices'][0]['message']['content']);
    }

    public function test_multiple_gemini_candidates_are_not_merged(): void
    {
        $wire = self::frame(['candidates' => [
            ['content' => ['parts' => [['text' => 'Selected']]], 'finishReason' => 'STOP'],
            ['content' => ['parts' => [['text' => 'Other identity']]], 'finishReason' => 'STOP'],
        ]]);
        $result = (new ProviderTextStream)->read(Utils::streamFor($wire), 300, 'gemini');
        $this->assertSame('Selected', $result['candidates'][0]['content']['parts'][0]['text']);
    }
}
