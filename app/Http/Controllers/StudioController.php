<?php

namespace App\Http\Controllers;

use App\Models\AiSetting;
use App\Services\PromptGenerationException;
use App\Services\PromptGenerationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Throwable;

class StudioController extends Controller
{
    public function index()
    {
        $settings = AiSetting::query()->find(1);

        return view('studio.index', [
            'aiReady' => $settings?->isReady() ?? false,
            'isAdmin' => (bool) auth()->user()?->is_admin,
        ]);
    }

    public function generate(Request $request, PromptGenerationService $generator): JsonResponse
    {
        $validated = $request->validate([
            'mode' => ['required', Rule::in(['story', 'extend'])],
            'topic' => ['nullable', 'required_if:mode,story', 'string', 'max:250'],
            'learning_objective' => ['nullable', 'string', 'max:500'],
            'next_action' => ['nullable', 'required_if:mode,extend', 'string', 'max:800'],
            'visible_start' => ['nullable', 'string', 'max:1500'],
            'audience' => ['required', 'string', 'max:160'],
            'visual_style' => ['required', Rule::in(['3d-family-animation', '2d-illustration', 'cinematic-realism', 'stop-motion', 'custom'])],
            'style_detail' => ['nullable', 'required_if:visual_style,custom', 'string', 'max:500'],
            'character_idea' => ['nullable', 'string', 'max:500'],
            'clip_count' => ['nullable', 'required_if:mode,story', 'integer', 'min:2', 'max:8'],
            'clip_duration' => ['required', 'integer', 'min:4', 'max:20'],
            'aspect_ratio' => ['required', Rule::in(['16:9', '9:16', '1:1', '4:3'])],
            'educational_depth' => ['required', Rule::in(['clear', 'balanced', 'deep-dive'])],
            'narration' => ['required', Rule::in(['1', '0'])],
            'language' => ['nullable', 'required_if:narration,1', Rule::in(['English', 'Urdu', 'Spanish', 'French', 'Arabic', 'Hindi'])],
            'narrator_voice' => ['nullable', 'required_if:narration,1', Rule::in(['feminine', 'masculine'])],
            'narrator_accent' => ['nullable', 'required_if:narration,1', Rule::in(['American English', 'British English', 'Pakistani English', 'Australian English', 'Neutral'])],
            'narrator_tone' => ['nullable', 'required_if:narration,1', Rule::in(['warm and friendly', 'curious and lively', 'calm and thoughtful', 'confident and clear'])],
            'music' => ['nullable', 'boolean'],
            'sound_effects' => ['nullable', 'boolean'],
            'reference_frame' => ['nullable', 'prohibited_unless:mode,extend', 'image', 'mimes:jpg,jpeg,png,webp', 'max:8192'],
        ]);

        $settings = AiSetting::query()->find(1);

        if (! $settings?->isReady()) {
            return response()->json([
                'message' => 'The prompt studio is waiting for an AI connection. An administrator can finish setup in the Control room.',
            ], 503);
        }

        try {
            $content = $generator->generate($settings, $validated, $request->file('reference_frame'));
        } catch (PromptGenerationException $exception) {
            return response()->json(['message' => $exception->getMessage()], 502);
        } catch (Throwable $exception) {
            report($exception);

            return response()->json([
                'message' => 'Something unexpected interrupted prompt generation. Please try again; if it continues, ask an administrator to check the server logs.',
            ], 500);
        }

        return response()->json([
            'content' => $content,
            'mode' => $validated['mode'],
        ]);
    }
}
