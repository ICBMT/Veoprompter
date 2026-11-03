<?php

namespace App\Services;

use GuzzleHttp\Psr7\Response as PsrResponse;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Psr\Http\Message\StreamInterface;
use RuntimeException;

/** Buffer provider SSE server-side; never publish an unchecked partial kit. */
class ProviderTextStream
{
    public function request(string $endpoint, string $apiKey, array $payload, string $protocol = 'openai'): Response
    {
        $timeout = max(120, min(900, (int) config('prompt_generation.provider_timeout', 300)));
        $started = microtime(true);
        $request = $protocol === 'gemini'
            ? Http::withHeaders(['x-goog-api-key' => $apiKey])
            : Http::withToken($apiKey);
        $response = $request
            ->withHeaders(['Accept' => 'text/event-stream'])
            ->connectTimeout(15)
            ->timeout($timeout)
            ->withOptions(['stream' => true, 'read_timeout' => $timeout])
            ->post($endpoint, $protocol === 'gemini' ? $payload : [...$payload, 'stream' => true]);
        $body = $response->toPsrResponse()->getBody();
        try {
            if (! $response->successful()) {
                return $response; // Caller maps status without exposing the upstream error body.
            }
            $remaining = max(0, (int) floor($timeout - (microtime(true) - $started)));
            if ($remaining === 0) {
                $this->failedStream();
            }
            $type = strtolower($response->header('Content-Type'));
            if (str_contains($type, 'text/event-stream')) {
                $completion = $this->read($body, $remaining, $protocol);
            } elseif (str_contains($type, 'application/json')) {
                // Some compatible gateways ignore streaming and return one complete JSON response.
                // Consume that same response; never resend a paid POST as a fallback.
                $json = '';
                $deadline = microtime(true) + $remaining;
                while (! $body->eof()) {
                    $chunk = $body->read(8192);
                    if (microtime(true) > $deadline || ($chunk === '' && ! $body->eof())) {
                        $this->failedStream();
                    }
                    $json .= $chunk;
                    if (strlen($json) > 8 * 1024 * 1024) {
                        $this->failedStream();
                    }
                }
                try {
                    $completion = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
                } catch (\JsonException) {
                    $this->failedStream();
                }
                if (! is_array($completion) || isset($completion['error'])) {
                    $this->failedStream();
                }
            } else {
                throw new PromptGenerationException('The AI provider returned an unsupported response format. Select a text-generation model using Gemini or OpenAI Chat Completions. No output was accepted.');
            }

            return new Response(new PsrResponse(200, ['Content-Type' => 'application/json'], json_encode($completion, JSON_THROW_ON_ERROR)));
        } catch (RuntimeException $exception) {
            if ($exception instanceof PromptGenerationException) {
                throw $exception;
            }
            throw new PromptGenerationException('The AI provider response stream was interrupted or timed out. The provider may already have used tokens. No partial output was accepted and this request was not automatically retried.', previous: $exception);
        } finally {
            $body->close();
        }
    }

    public function read(StreamInterface $body, int $timeout = 300, string $protocol = 'openai'): array
    {
        $buffer = '';
        $text = '';
        $finish = null;
        $wireBytes = 0;
        $deadline = microtime(true) + $timeout;
        while (! $body->eof()) {
            $chunk = $body->read(8192);
            if (microtime(true) > $deadline || ($chunk === '' && ! $body->eof())) {
                throw new PromptGenerationException('The AI provider response stream timed out. The provider may already have used tokens. No partial output was accepted; no automatic retry was made.');
            }
            $wireBytes += strlen($chunk);
            if ($wireBytes > 8 * 1024 * 1024) {
                throw new PromptGenerationException('The AI provider response stream exceeded the safe response size. No output was accepted.');
            }
            $buffer .= $chunk;
            while (preg_match('/\r?\n\r?\n/', $buffer, $separator, PREG_OFFSET_CAPTURE)) {
                $offset = $separator[0][1];
                $event = substr($buffer, 0, $offset);
                $buffer = substr($buffer, $offset + strlen($separator[0][0]));
                $data = [];
                $errorEvent = false;
                foreach (preg_split('/\r?\n/', $event) as $line) {
                    if (str_starts_with($line, 'data:')) {
                        $data[] = substr($line, 5);
                    }
                    if (trim($line) === 'event: error') {
                        $errorEvent = true;
                    }
                }
                if ($errorEvent) {
                    $this->failedStream();
                }
                if ($data === []) {
                    continue;
                } // Heartbeat/comment event.
                $payload = trim(implode("\n", $data));
                if ($payload === '[DONE]') {
                    if (! in_array($finish, ['stop', 'length'], true) || trim($text) === '') {
                        $this->failedStream();
                    }

                    return ['choices' => [['message' => ['content' => $text], 'finish_reason' => $finish]]];
                }
                try {
                    $frame = json_decode($payload, true, 512, JSON_THROW_ON_ERROR);
                } catch (\JsonException) {
                    $this->failedStream();
                }
                if (! is_array($frame) || isset($frame['error'])) {
                    $this->failedStream();
                }
                if ($protocol === 'gemini') {
                    if (! empty($frame['promptFeedback']['blockReason'])) {
                        $this->failedStream();
                    }
                    $candidates = $frame['candidates'] ?? [];
                    if (! is_array($candidates)) {
                        $this->failedStream();
                    }
                    foreach ($candidates as $position => $candidate) {
                        if (! is_array($candidate)) {
                            $this->failedStream();
                        }
                        if (($candidate['index'] ?? $position) !== 0) {
                            continue;
                        }
                        $parts = $candidate['content']['parts'] ?? [];
                        if (! is_array($parts)) {
                            $this->failedStream();
                        }
                        foreach ($parts as $part) {
                            if (! is_array($part)) {
                                $this->failedStream();
                            }
                            if (($part['thought'] ?? false) === true) {
                                continue;
                            }
                            $delta = $part['text'] ?? '';
                            if (! is_string($delta) || ($finish !== null && $delta !== '')) {
                                $this->failedStream();
                            }
                            $text .= $delta;
                            if (strlen($text) > 2 * 1024 * 1024) {
                                $this->failedStream();
                            }
                        }
                        $reason = $candidate['finishReason'] ?? null;
                        if ($reason !== null) {
                            if (! in_array($reason, ['STOP', 'MAX_TOKENS'], true) || ($finish !== null && $finish !== $reason)) {
                                $this->failedStream();
                            }
                            $finish = $reason;
                        }
                    }

                    continue;
                }
                $choices = $frame['choices'] ?? null;
                if (! is_array($choices)) {
                    $this->failedStream();
                }
                foreach ($choices as $position => $choice) {
                    if (! is_array($choice)) {
                        $this->failedStream();
                    }
                    if (($choice['index'] ?? $position) !== 0) {
                        continue;
                    }
                    if (! empty($choice['delta']['refusal']) || ! empty($choice['delta']['tool_calls'])) {
                        $this->failedStream();
                    }
                    $delta = $choice['delta']['content'] ?? '';
                    if (! is_string($delta) || ($finish !== null && $delta !== '')) {
                        $this->failedStream();
                    }
                    // Reasoning/tool-call deltas are never copied into final prompt text.
                    $text .= $delta;
                    if (strlen($text) > 2 * 1024 * 1024) {
                        $this->failedStream();
                    }
                    $reason = $choice['finish_reason'] ?? null;
                    if ($reason !== null) {
                        if (! in_array($reason, ['stop', 'length'], true) || ($finish !== null && $finish !== $reason)) {
                            $this->failedStream();
                        }
                        $finish = $reason;
                    }
                }
            }
            if (strlen($buffer) > 1024 * 1024) {
                $this->failedStream();
            }
        }
        // Gemini's SSE protocol ends at EOF, with a terminal candidate finishReason.
        // OpenAI Chat Completions additionally requires its [DONE] marker.
        if ($protocol === 'gemini' && trim($buffer) === '' && in_array($finish, ['STOP', 'MAX_TOKENS'], true) && trim($text) !== '') {
            return ['candidates' => [['finishReason' => $finish, 'content' => ['parts' => [['text' => $text]]]]]];
        }
        $this->failedStream();
    }

    private function failedStream(): never
    {
        throw new PromptGenerationException('AI provider returned an incomplete, invalid or failed response stream. The provider may already have used tokens. No partial output was accepted and no automatic retry was made.');
    }
}
