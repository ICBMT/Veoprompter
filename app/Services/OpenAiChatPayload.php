<?php

namespace App\Services;

/** Protocol options, not a model catalogue or a promise of account access. */
final class OpenAiChatPayload
{
    public static function make(string $baseUrl, string $model, array $messages, int $outputTokens, float $temperature): array
    {
        $payload = ['model' => $model, 'messages' => $messages];
        // Native OpenAI reasoning families require completion-token budgets and
        // may reject temperature. Gate by endpoint: aggregators such as xKiro
        // document max_tokens and handle their own vendor/model translation.
        $nativeReasoning = strtolower(rtrim($baseUrl, '/')) === 'https://api.openai.com/v1'
            && preg_match('/\A(?:o[134](?:-|\z)|gpt-5(?:[.-]|\z))/', $model);
        if ($nativeReasoning) {
            $payload['max_completion_tokens'] = $outputTokens;
        } else {
            $payload['max_tokens'] = $outputTokens;
            $payload['temperature'] = $temperature;
        }

        return $payload;
    }
}
