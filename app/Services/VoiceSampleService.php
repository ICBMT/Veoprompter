<?php

namespace App\Services;

use App\Models\AiSetting;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

class VoiceSampleService
{
    private const MODEL_DEFAULT = 'gemini-2.5-flash-preview-tts';

    private const SAMPLE_VERSION = 'garden-seed-v1';

    /** @var array<string, string> */
    public const SAMPLE_LINES = [
        'English' => 'A tiny seed drifts into the garden. With sunlight, water, and a little patience, it begins a remarkable journey.',
        'Urdu' => 'ایک ننھا سا بیج باغ میں گرتا ہے۔ دھوپ، پانی اور تھوڑے صبر سے اس کا حیرت انگیز سفر شروع ہوتا ہے۔',
        'Spanish' => 'Una pequeña semilla llega al jardín. Con luz, agua y un poco de paciencia, comienza un viaje extraordinario.',
        'French' => 'Une petite graine arrive dans le jardin. Avec du soleil, de l’eau et un peu de patience, une belle aventure commence.',
        'Arabic' => 'تصل بذرة صغيرة إلى الحديقة. ومع ضوء الشمس والماء والقليل من الصبر، تبدأ رحلة رائعة.',
        'Hindi' => 'एक छोटा सा बीज बगीचे में आता है। धूप, पानी और थोड़े धैर्य के साथ, इसकी अद्भुत यात्रा शुरू होती है.',
    ];

    /** @var list<string> */
    public const VOICES = ['feminine', 'masculine'];

    /** @var list<string> */
    public const ACCENTS = ['American English', 'British English', 'Pakistani English', 'Australian English', 'Neutral'];

    /** @var list<string> */
    public const TONES = ['warm and friendly', 'curious and lively', 'calm and thoughtful', 'confident and clear'];

    /**
     * Generate once for each language, voice, accent, tone, and model combination,
     * then keep the WAV privately on the local disk for subsequent previews.
     *
     * @param  array{language: string, narrator_voice: string, narrator_accent: string, narrator_tone: string}  $configuration
     */
    public function generate(AiSetting $settings, array $configuration): string
    {
        $model = trim((string) ($settings->tts_model ?: self::MODEL_DEFAULT));
        $cacheKey = hash('sha256', json_encode([
            'version' => self::SAMPLE_VERSION,
            'model' => $model,
            ...$configuration,
        ], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
        $path = 'voice-samples/'.$cacheKey.'.wav';
        $disk = Storage::disk('local');

        if ($disk->exists($path)) {
            return $disk->get($path);
        }

        try {
            // Read the encrypted cast once and pass the plaintext only to the provider request.
            $ttsApiKey = $settings->tts_api_key;
        } catch (DecryptException $exception) {
            throw new VoiceSampleGenerationException('The saved Google TTS key could not be decrypted. Enter it again in the Control room.', 503, $exception);
        }

        if (! filled($ttsApiKey)) {
            throw new VoiceSampleGenerationException('Voice samples are not configured yet. Ask an administrator to add a Google TTS key in the Control room.', 503);
        }

        try {
            return Cache::lock('voice-sample:'.$cacheKey, 150)->block(20, function () use ($disk, $path, $ttsApiKey, $configuration, $model): string {
                if ($disk->exists($path)) {
                    return $disk->get($path);
                }

                $audio = $this->requestGeminiAudio($ttsApiKey, $model, $configuration);
                $wav = $this->wrapPcmAsWav($audio['bytes'], $audio['mimeType']);
                if (! $disk->put($path, $wav)) {
                    throw new VoiceSampleGenerationException('The generated sample could not be saved. Please try again.', 500);
                }

                return $wav;
            });
        } catch (LockTimeoutException $exception) {
            throw new VoiceSampleGenerationException('This sample is being prepared by another request. Please wait a moment and try again.', 429, $exception);
        }
    }

    /**
     * @param  array{language: string, narrator_voice: string, narrator_accent: string, narrator_tone: string}  $configuration
     * @return array{bytes: string, mimeType: string}
     */
    private function requestGeminiAudio(string $ttsApiKey, string $model, array $configuration): array
    {
        $language = $configuration['language'];
        $gender = $configuration['narrator_voice'];
        $accent = $configuration['narrator_accent'];
        $tone = $configuration['narrator_tone'];
        $voiceName = $gender === 'feminine' ? 'Kore' : 'Puck';
        $line = self::SAMPLE_LINES[$language];
        $instructions = match ($tone) {
            'curious and lively' => 'bright, curious, and lively, with energetic but natural emphasis',
            'calm and thoughtful' => 'calm, reflective, measured, and gently paced',
            'confident and clear' => 'confident, clear, steady, and assured',
            default => 'warm, friendly, gentle, and welcoming',
        };
        $accentDirection = $language === 'English'
            ? match ($accent) {
                'British English' => 'Use a natural contemporary British English pronunciation.',
                'Pakistani English' => 'Use a natural Pakistani English pronunciation.',
                'Australian English' => 'Use a natural Australian English pronunciation.',
                'Neutral' => 'Use a broadly neutral English pronunciation.',
                default => 'Use a natural General American English pronunciation.',
            }
            : "The selected accent is {$accent}; preserve natural, fluent {$language} pronunciation and only reflect that accent where it is linguistically appropriate.";
        $spokenText = "Speak exactly the following {$language} sample. Use a {$gender}-sounding adult narrator with a {$instructions} delivery. {$accentDirection} Do not translate, preface, add words, or repeat anything.\n\n{$line}";
        $url = 'https://generativelanguage.googleapis.com/v1beta/models/'.rawurlencode($model).':generateContent';

        try {
            $response = Http::acceptJson()
                ->withHeaders(['x-goog-api-key' => $ttsApiKey])
                ->connectTimeout(15)
                ->timeout(120)
                ->post($url, [
                    'contents' => [[
                        'role' => 'user',
                        'parts' => [['text' => $spokenText]],
                    ]],
                    'generationConfig' => [
                        'responseModalities' => ['AUDIO'],
                        'speechConfig' => [
                            'voiceConfig' => [
                                'prebuiltVoiceConfig' => [
                                    'voiceName' => $voiceName,
                                ],
                            ],
                        ],
                    ],
                ]);
        } catch (ConnectionException $exception) {
            Log::warning('The configured Google TTS service could not be reached.', [
                'exception' => $exception::class,
            ]);

            throw new VoiceSampleGenerationException('Google TTS could not be reached. Check the server connection and try again.', 502, $exception);
        }

        if (! $response->successful()) {
            Log::warning('The configured Google TTS service returned an error.', [
                'status' => $response->status(),
                'model' => $model,
            ]);

            throw new VoiceSampleGenerationException($this->providerFailureMessage($response->status()), 502);
        }

        $audio = $this->extractAudio($response);
        if ($audio === null) {
            throw new VoiceSampleGenerationException('Google TTS returned no audio. Check that the configured model supports speech generation.', 502);
        }

        return $audio;
    }

    /** @return array{bytes: string, mimeType: string}|null */
    private function extractAudio(Response $response): ?array
    {
        $parts = $response->json('candidates.0.content.parts', []);
        if (! is_array($parts)) {
            return null;
        }

        $chunks = [];
        $mimeType = '';
        foreach ($parts as $part) {
            if (! is_array($part)) {
                continue;
            }

            $inlineData = $part['inlineData'] ?? $part['inline_data'] ?? null;
            if (! is_array($inlineData) || ! is_string($inlineData['data'] ?? null)) {
                continue;
            }

            $chunk = base64_decode($inlineData['data'], true);
            if ($chunk === false) {
                continue;
            }

            $chunks[] = $chunk;
            $mimeType = (string) ($inlineData['mimeType'] ?? $inlineData['mime_type'] ?? $mimeType);
        }

        if ($chunks === []) {
            return null;
        }

        $bytes = implode('', $chunks);
        if (strlen($bytes) > 8 * 1024 * 1024) {
            throw new VoiceSampleGenerationException('Google TTS returned an unexpectedly large sample. Please try again.', 502);
        }

        return ['bytes' => $bytes, 'mimeType' => $mimeType];
    }

    private function wrapPcmAsWav(string $bytes, string $mimeType): string
    {
        if (str_starts_with($bytes, 'RIFF')) {
            return $bytes;
        }

        if (! str_contains(strtolower($mimeType), 'audio/')) {
            throw new VoiceSampleGenerationException('Google TTS returned an unsupported audio format.', 502);
        }

        preg_match('/rate\s*=\s*(\d+)/i', $mimeType, $matches);
        $sampleRate = isset($matches[1]) ? (int) $matches[1] : 24000;
        if ($sampleRate < 8000 || $sampleRate > 96000 || strlen($bytes) < 2) {
            throw new VoiceSampleGenerationException('Google TTS returned invalid audio data.', 502);
        }

        // Gemini speech previews are 16-bit, mono PCM. Add a standard RIFF/WAVE header for browsers.
        $channels = 1;
        $bitsPerSample = 16;
        $blockAlign = $channels * intdiv($bitsPerSample, 8);
        $byteRate = $sampleRate * $blockAlign;
        $header = 'RIFF'.pack('V', 36 + strlen($bytes))
            .'WAVEfmt '.pack('V', 16)
            .pack('v', 1)
            .pack('v', $channels)
            .pack('V', $sampleRate)
            .pack('V', $byteRate)
            .pack('v', $blockAlign)
            .pack('v', $bitsPerSample)
            .'data'.pack('V', strlen($bytes));

        return $header.$bytes;
    }

    private function providerFailureMessage(int $status): string
    {
        return match (true) {
            $status === 400 => 'Google rejected the sample request. Check that the selected Gemini TTS model is enabled for this API key.',
            $status === 401, $status === 403 => 'Google rejected the TTS key or denied access to the selected speech model. Re-enter the key in the Control room.',
            $status === 404 => 'Google could not find the configured speech model. Verify the TTS model ID in the Control room.',
            $status === 429 => 'The Google TTS quota or rate limit was reached. Check the project quota, then retry after a short wait.',
            $status >= 500 => 'Google TTS is temporarily unavailable. Wait a moment and try again.',
            default => 'Google TTS returned HTTP '.$status.'. Check the speech model and key in the Control room.',
        };
    }
}
