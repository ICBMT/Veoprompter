<?php

namespace App\Http\Controllers;

use App\Models\AiSetting;
use App\Models\StudioProject;
use App\Services\PromptGenerationException;
use App\Services\PromptGenerationService;
use App\Support\ProjectSettings;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Throwable;

class StudioController extends Controller
{
    public function generate(Request $request, StudioProject $studioProject, PromptGenerationService $generator): JsonResponse
    {
        abort_unless($studioProject->user_id === $request->user()?->id, 404);

        $durationOptions = ProjectSettings::durationOptionsFor($request->user());
        $projectSettings = ProjectSettings::applyDurationOptions($studioProject->settings ?? [], $durationOptions);
        $requestMode = $request->input('mode');
        $hasVideoTypeOverride = $requestMode === 'story' && $request->exists('video_type');
        $videoTypeOverride = $request->input('video_type');

        if ($requestMode === 'extend') {
            $projectSettings['clip_duration'] = $projectSettings['extend_duration'];
        }
        $request->merge($projectSettings);
        if ($hasVideoTypeOverride) {
            $request->merge(['video_type' => $videoTypeOverride]);
        }

        $validated = $request->validate([
            'mode' => ['required', Rule::in(['story', 'extend'])],
            'project_title' => ['nullable', 'string', 'max:120'],
            'video_type' => ['required', Rule::in(array_keys(ProjectSettings::videoTypes()))],
            'topic' => ['nullable', 'required_if:mode,story', 'string', 'max:250'],
            'learning_objective' => ['nullable', 'string', 'max:500'],
            'creative_direction' => ['nullable', 'string', 'max:1500'],
            'next_action' => ['nullable', 'required_if:mode,extend', 'string', 'max:800'],
            'visible_start' => [
                'nullable',
                Rule::requiredIf($request->input('mode') === 'extend' && ! $request->hasFile('reference_frame')),
                'string',
                'max:1500',
            ],
            'audience' => ['required', 'string', 'max:160'],
            'visual_style' => ['required', Rule::in(array_keys(ProjectSettings::visualStyleOptions()))],
            'style_detail' => ['nullable', 'required_if:visual_style,custom', 'string', 'max:500'],
            'character_type' => ['required', Rule::in(['ai-choice', 'human-male', 'human-female', 'robot', 'fictional'])],
            'character_details' => ['nullable', 'required_if:character_type,fictional', 'string', 'max:500'],
            'character_idea' => ['nullable', 'string', 'max:500'],
            'clip_count' => ['nullable', 'required_if:mode,story', Rule::in([ProjectSettings::AI_CHOICE, ProjectSettings::AI_QUALITY_CLIP_COUNT, ...range(ProjectSettings::MANUAL_CLIP_COUNT_MIN, ProjectSettings::MANUAL_CLIP_COUNT_MAX)])],
            'clip_duration' => ['required', Rule::in([ProjectSettings::AI_CHOICE, ...range(ProjectSettings::MANUAL_DURATION_MIN, ProjectSettings::MANUAL_DURATION_MAX)])],
            'aspect_ratio' => ['required', Rule::in(['16:9', '9:16', '1:1', '4:3'])],
            'educational_depth' => ['required', Rule::in(['clear', 'balanced', 'deep-dive'])],
            'narration' => ['required', Rule::in(['1', '0'])],
            'speech_mode' => ['nullable', Rule::in([ProjectSettings::SPEECH_MODE_VOICEOVER, ProjectSettings::SPEECH_MODE_CHARACTER_DIALOGUE])],
            'language' => ['nullable', 'required_if:narration,1', Rule::in(['English', 'Urdu', 'Spanish', 'French', 'Arabic', 'Hindi'])],
            'narrator_voice' => ['nullable', 'required_if:narration,1', Rule::in(['feminine', 'masculine'])],
            'narrator_accent' => ['nullable', 'required_if:narration,1', Rule::in(['American English', 'British English', 'Pakistani English', 'Australian English', 'Neutral'])],
            'narrator_tone' => ['nullable', 'required_if:narration,1', Rule::in(['warm and friendly', 'curious and lively', 'calm and thoughtful', 'confident and clear'])],
            'music' => ['nullable', 'boolean'],
            'sound_effects' => ['nullable', 'boolean'],
            'reference_frame' => ['nullable', 'prohibited_unless:mode,extend', 'image', 'mimes:jpg,jpeg,png,webp', 'max:8192'],
        ], [
            'visible_start.required' => 'For Extend Video, attach a final-frame image or describe the visible starting state.',
        ]);

        $settings = AiSetting::query()->find(1);

        if (! $settings?->isReady()) {
            return response()->json([
                'message' => 'The prompt studio is waiting for an AI connection. An administrator can finish setup in the Control room.',
            ], 503);
        }

        $referenceFrame = $request->file('reference_frame');

        try {
            $generation = $generator->generateWithQualityReview($settings, $validated, $referenceFrame);
            $content = $generation['content'];
            $qualityReview = $generation['quality_review'];
        } catch (PromptGenerationException $exception) {
            return response()->json(['message' => $exception->getMessage()], 502);
        } catch (Throwable $exception) {
            report($exception);

            return response()->json([
                'message' => 'Something unexpected interrupted prompt generation. Please try again; if it continues, ask an administrator to check the server logs.',
            ], 500);
        }

        $run = null;
        $historyError = false;

        try {
            $brief = Arr::except($validated, ['reference_frame']);
            $title = trim((string) ($validated['project_title'] ?? ''));
            $title = $title !== ''
                ? $title
                : (string) ($validated['mode'] === 'extend' ? ($validated['next_action'] ?? 'Video extension') : ($validated['topic'] ?? 'Video prompt'));

            $run = $request->user()->promptProjects()->create([
                'studio_project_id' => $studioProject->id,
                'title' => Str::limit($title, 160, ''),
                'mode' => $validated['mode'],
                'brief' => $brief,
                'content' => $content,
                'quality_review' => $qualityReview,
                'provider' => $settings->provider,
                'model' => $settings->model,
                'reference_frame_attached' => $referenceFrame !== null,
            ]);

            $studioProject->touch();
        } catch (Throwable $exception) {
            report($exception);
            $historyError = true;
        }

        return response()->json([
            'content' => $content,
            'quality_review' => $qualityReview,
            'mode' => $validated['mode'],
            'saved_to_history' => $run !== null,
            'history_error' => $historyError,
            'run' => $run ? [
                'id' => $run->id,
                'title' => $run->title,
                'url' => route('history.show', $run, false),
            ] : null,
        ]);
    }
}
