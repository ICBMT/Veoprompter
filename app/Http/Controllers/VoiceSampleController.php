<?php

namespace App\Http\Controllers;

use App\Models\AiSetting;
use App\Services\VoiceSampleGenerationException;
use App\Services\VoiceSampleService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Validation\Rule;

class VoiceSampleController extends Controller
{
    public function store(Request $request, VoiceSampleService $voiceSamples): JsonResponse|Response
    {
        $configuration = $request->validate([
            'language' => ['required', Rule::in(array_keys(VoiceSampleService::SAMPLE_LINES))],
            'narrator_voice' => ['required', Rule::in(VoiceSampleService::VOICES)],
            'narrator_accent' => ['required', Rule::in(VoiceSampleService::ACCENTS)],
            'narrator_tone' => ['required', Rule::in(VoiceSampleService::TONES)],
        ]);

        try {
            $audio = $voiceSamples->generate(AiSetting::current(), $configuration);
        } catch (VoiceSampleGenerationException $exception) {
            return response()->json(['message' => $exception->getMessage()], $exception->statusCode);
        }

        return response($audio, 200, [
            'Content-Type' => 'audio/wav',
            'Content-Disposition' => 'inline; filename="voice-preview.wav"',
            'Cache-Control' => 'private, no-store',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }
}
