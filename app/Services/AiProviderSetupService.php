<?php

namespace App\Services;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;

class AiProviderSetupService
{
    private const GEMINI_MODELS_URL = 'https://generativelanguage.googleapis.com/v1beta/models';

    /**
     * @return list<array{id: string, label: string}>
     */
    public function models(string $provider, ?string $baseUrl, string $apiKey): array
    {
        return $provider === 'gemini'
            ? $this->geminiModels($apiKey)
            : $this->openAiCompatibleModels((string) $baseUrl, $apiKey);
    }

    public function testConnection(string $provider, ?string $baseUrl, string $model, string $apiKey): string
    {
        try {
            $response = $provider === 'gemini'
                ? $this->testGemini($model, $apiKey)
                : $this->testOpenAiCompatible((string) $baseUrl, $model, $apiKey);
        } catch (ConnectionException $exception) {
            throw new AiProviderSetupException(
                'The provider could not be reached. Check the endpoint and server network, then try again.',
                502,
                $exception,
            );
        }

        if (! $response->successful()) {
            throw new AiProviderSetupException($this->failureMessage($response->status()), 502);
        }

        $reply = $provider === 'gemini'
            ? collect($response->json('candidates.0.content.parts', []))->pluck('text')->filter()->implode("\n")
            : $this->openAiReply($response);

        if (blank($reply)) {
            throw new AiProviderSetupException(
                'The selected model accepted the request but returned no text. Try a text-generation model from the list.',
                502,
            );
        }

        return trim($reply);
    }

    /**
     * @return list<array{id: string, label: string}>
     */
    private function geminiModels(string $apiKey): array
    {
        $models = [];
        $pageToken = null;

        try {
            for ($page = 0; $page < 5; $page++) {
                $query = ['pageSize' => 100];
                if ($pageToken) {
                    $query['pageToken'] = $pageToken;
                }

                $response = Http::acceptJson()
                    ->withHeaders(['x-goog-api-key' => $apiKey])
                    ->connectTimeout(10)
                    ->timeout(25)
                    ->get(self::GEMINI_MODELS_URL, $query);

                if (! $response->successful()) {
                    throw new AiProviderSetupException($this->failureMessage($response->status()), 502);
                }

                $listedModels = $response->json('models', []);
                foreach (is_array($listedModels) ? $listedModels : [] as $model) {
                    $methods = is_array($model) ? ($model['supportedGenerationMethods'] ?? []) : [];
                    if (! is_array($model) || ! is_array($methods) || ! in_array('generateContent', $methods, true)) {
                        continue;
                    }

                    $id = preg_replace('/\\Amodels\\//', '', (string) ($model['name'] ?? ''));
                    if (! $this->isSafeModelId($id)) {
                        continue;
                    }

                    $displayName = trim((string) ($model['displayName'] ?? ''));
                    $models[$id] = [
                        'id' => $id,
                        'label' => $displayName !== '' && $displayName !== $id ? $displayName.' — '.$id : $id,
                    ];
                }

                $pageToken = $response->json('nextPageToken');
                if (! is_string($pageToken) || $pageToken === '') {
                    break;
                }
            }
        } catch (ConnectionException $exception) {
            throw new AiProviderSetupException(
                'Google Gemini could not be reached. Check the server network and try again.',
                502,
                $exception,
            );
        }

        usort($models, fn (array $left, array $right): int => strnatcasecmp($left['id'], $right['id']));

        return array_values($models);
    }

    /**
     * @return list<array{id: string, label: string}>
     */
    private function openAiCompatibleModels(string $baseUrl, string $apiKey): array
    {
        try {
            $response = Http::acceptJson()
                ->withToken($apiKey)
                ->connectTimeout(10)
                ->timeout(25)
                ->get(rtrim($baseUrl, '/').'/models');
        } catch (ConnectionException $exception) {
            throw new AiProviderSetupException(
                'The configured API endpoint could not be reached. Check the URL and server network, then try again.',
                502,
                $exception,
            );
        }

        if (! $response->successful()) {
            throw new AiProviderSetupException($this->failureMessage($response->status()), 502);
        }

        $payload = $response->json();
        $items = data_get($payload, 'data', data_get($payload, 'models'));
        if (! is_array($items) && is_array($payload) && array_is_list($payload)) {
            $items = $payload;
        }

        $models = [];
        foreach (is_array($items) ? $items : [] as $item) {
            $id = is_array($item) ? (string) ($item['id'] ?? $item['name'] ?? '') : (is_string($item) ? $item : '');
            if (! $this->isSafeModelId($id)) {
                continue;
            }

            $models[$id] = ['id' => $id, 'label' => $id];
        }

        usort($models, fn (array $left, array $right): int => strnatcasecmp($left['id'], $right['id']));

        return array_values($models);
    }

    private function testGemini(string $model, string $apiKey): Response
    {
        $url = self::GEMINI_MODELS_URL.'/'.rawurlencode($model).':generateContent';

        return Http::acceptJson()
            ->withHeaders(['x-goog-api-key' => $apiKey])
            ->connectTimeout(10)
            ->timeout(30)
            ->post($url, [
                'systemInstruction' => ['parts' => [['text' => 'Reply with one short sentence confirming this connection test.']]],
                'contents' => [['role' => 'user', 'parts' => [['text' => 'Say: VeoPrompter connection test passed.']]]],
                'generationConfig' => ['temperature' => 0, 'maxOutputTokens' => 64],
            ]);
    }

    private function testOpenAiCompatible(string $baseUrl, string $model, string $apiKey): Response
    {
        return Http::acceptJson()
            ->withToken($apiKey)
            ->connectTimeout(10)
            ->timeout(30)
            ->post(rtrim($baseUrl, '/').'/chat/completions', [
                'model' => $model,
                'messages' => [
                    ['role' => 'system', 'content' => 'Reply with one short sentence confirming this connection test.'],
                    ['role' => 'user', 'content' => 'Say: VeoPrompter connection test passed.'],
                ],
                'temperature' => 0,
                'max_tokens' => 64,
            ]);
    }

    private function openAiReply(Response $response): string
    {
        $content = $response->json('choices.0.message.content', '');

        if (is_string($content)) {
            return $content;
        }

        if (is_array($content)) {
            return collect($content)
                ->pluck('text')
                ->filter(fn ($text) => is_string($text))
                ->implode("\n");
        }

        return '';
    }

    private function isSafeModelId(string $model): bool
    {
        return strlen($model) <= 150
            && preg_match('/\\A[A-Za-z0-9][A-Za-z0-9._\\/:\\-]*\\z/D', $model) === 1;
    }

    private function failureMessage(int $status): string
    {
        return match (true) {
            $status === 400 => 'The provider rejected this request. Check the API key, selected model, and model access permissions.',
            $status === 401, $status === 403 => 'The API key was rejected or does not have permission to use this provider. Check the key and account access.',
            $status === 404 => 'The provider could not find this endpoint or model. Check the API base URL and selected model.',
            $status === 429 => 'The provider rate limit or quota was reached. Check billing and quota, then retry after a short wait.',
            $status >= 500 => 'The provider is temporarily unavailable (HTTP '.$status.'). Wait a moment and try again; if it persists, check the provider status and quota.',
            default => 'The provider request failed (HTTP '.$status.'). Check the provider settings and try again.',
        };
    }
}
