<?php

namespace App\Services;

use Illuminate\Http\Client\Response;

/** Parse final user-facing text, never thought parts or provider-supplied diagnostic prose. */
final class GeminiTextResponse
{
    public static function text(Response $response): string
    {
        $parts = $response->json('candidates.0.content.parts', []);
        if (! is_array($parts)) {
            return '';
        }
        $text = [];
        foreach ($parts as $part) {
            if (is_array($part) && ($part['thought'] ?? false) !== true
                && is_string($part['text'] ?? null) && trim($part['text']) !== '') {
                $text[] = $part['text'];
            }
        }

        return trim(implode("\n", $text));
    }

    public static function failure(Response $response): ?string
    {
        if ($response->json('promptFeedback.blockReason')) {
            return 'Gemini blocked the request before returning a candidate. No output was accepted. Review the request against the provider policies; changing the API key is not necessarily the solution.';
        }
        $reason = $response->json('candidates.0.finishReason');
        if ($reason === 'MAX_TOKENS') {
            return 'The provider reached its response-length limit before finishing the prompt kit. No partial response was accepted. Ask an administrator to raise the output-token budget within the selected model’s supported limit, or use a model with a larger output capacity.';
        }
        if ($reason !== null && $reason !== 'STOP') {
            return 'Gemini did not finish a normal text response (blocked, unsupported output, or provider termination). No output was accepted. Check the selected text model and request in the Control room.';
        }

        return null;
    }
}
