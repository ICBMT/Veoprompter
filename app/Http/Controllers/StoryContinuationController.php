<?php

namespace App\Http\Controllers;

use App\Models\AiSetting;
use App\Models\PromptProject;
use App\Services\ContinuationOpeningException;
use App\Services\PromptGenerationException;
use App\Services\PromptGenerationService;
use App\Services\StoryContinuationService;
use App\Support\ProjectSettings;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Throwable;

class StoryContinuationController extends Controller
{
    public function store(Request $request, PromptProject $project, StoryContinuationService $stories, PromptGenerationService $generator): JsonResponse
    {
        abort_unless((int) $project->user_id === (int) $request->user()->id, 404);
        $data = $request->validate([
            'intent' => ['required', Rule::in(['continue', 'finish', 'sequel'])],
            'direction' => [Rule::requiredIf($request->boolean('allow_new_subjects')), 'nullable', 'string', 'max:4000'],
            'allow_new_subjects' => ['sometimes', 'boolean'],
            'batch_size' => ['required', Rule::in(['set', 'single'])],
            'request_id' => ['required', 'uuid'],
        ]);
        $key = hash('sha256', $project->user_id.':'.$project->id.':'.$data['request_id']);
        $requestHash = hash('sha256', json_encode([$data['intent'], trim($data['direction'] ?? ''), $data['batch_size'], $request->boolean('allow_new_subjects')], JSON_THROW_ON_ERROR));
        $replay = function () use ($request, $key, $requestHash) {
            $existing = $request->user()->promptProjects()->where('continuation_key', $key)->first();
            abort_if($existing && ($existing->brief['continuation_request_hash'] ?? '') !== $requestHash, 409, 'This request ID already belongs to a different direction. Reload before starting a different batch.');

            return $existing;
        };
        $respond = fn (PromptProject $child) => response()->json(['url' => route('history.show', $child, false), 'id' => $child->id]);
        if ($existing = $replay()) {
            return $respond($existing);
        }
        try {
            $result = Cache::lock('story-generation:'.$key, 360)->get(function () use ($request, $project, $stories, $generator, $data, $key, $requestHash, $replay, $respond) {
                if ($existing = $replay()) {
                    return $respond($existing);
                }
                $project->refresh();
                $sourceHash = hash('sha256', $project->content);
                $input = $stories->prepare($project->content, $project->brief ?? [], $project->story_context ?? [],
                    $project->story_status, $data['intent'], trim($data['direction'] ?? ''), $data['batch_size'], $request->boolean('allow_new_subjects'));
                $input['_story_context']['part'] = $project->story_part + ($data['intent'] === 'sequel' ? 1 : 0);
                $input['_story_context']['batch'] = $data['intent'] === 'sequel' ? 1 : $project->story_batch + 1;
                // Permission switches still govern durations; the recipe otherwise comes from the saved run, not browser overrides.
                $input = ProjectSettings::applyDurationOptions($input, ProjectSettings::durationOptionsFor($request->user()));
                $settings = AiSetting::query()->find(1);
                if (! $settings?->isReady()) {
                    return response()->json(['message' => 'An administrator must finish the AI connection before you can continue.'], 503);
                }
                $generation = $generator->generateWithQualityReview($settings, $input);
                $context = $stories->accept($generation['content'], $input);
                $advisory = StoryContinuationService::advisoryBoundary($input);
                $generation['quality_review']['checks'][] = ['id' => 'story_boundary', 'label' => 'Cross-batch opening and story memory', 'status' => $advisory ? 'needs_review' : 'passed',
                    'detail' => $advisory
                        ? 'Saved for user review against source kit '.$project->id.'. Boundary comparisons are advisory, not proof of a match or mismatch. Memory/status structure was checked.'
                        : 'Opening state and presentation match source kit '.$project->id.' literally; memory/status structure was checked. This is text evidence, not a rendered-video or plot-factuality guarantee.'];
                $child = DB::transaction(function () use ($request, $project, $sourceHash, $input, $generation, $context, $settings, $data, $key, $requestHash) {
                    $source = PromptProject::query()->whereKey($project->id)->lockForUpdate()->first();
                    abort_unless($source && (int) $source->user_id === (int) $request->user()->id && hash('sha256', $source->content) === $sourceHash
                        && $source->story_status === $project->story_status && $source->story_context === $project->story_context
                        && $source->brief === $project->brief, 409,
                        'The source was edited or deleted during generation. Reload it and retry with the current ending.');
                    $part = $source->story_part + ($data['intent'] === 'sequel' ? 1 : 0);
                    $batch = $data['intent'] === 'sequel' ? 1 : $source->story_batch + 1;

                    return $request->user()->promptProjects()->create([
                        'studio_project_id' => $source->studio_project_id,
                        'parent_prompt_id' => $source->id,
                        'story_part' => $part, 'story_batch' => $batch,
                        'story_status' => $input['_story_context']['target_status'],
                        'story_context' => $context, 'continuation_key' => $key,
                        'title' => Str::limit(($input['topic'] ?? 'Story').' · Part '.$part.' · Batch '.$batch, 160, ''),
                        'mode' => 'story',
                        'brief' => [...Arr::except($input, ['_story_context']), 'continuation_intent' => $data['intent'], 'continuation_direction' => $data['direction'] ?? '', 'continuation_size' => $data['batch_size'], 'continuation_request_hash' => $requestHash, 'allow_new_subjects' => $request->boolean('allow_new_subjects')],
                        'content' => $generation['content'], 'original_content' => $generation['content'],
                        'quality_review' => $generation['quality_review'], 'repair_history' => [],
                        'provider' => $settings->provider, 'model' => $settings->model, 'reference_frame_attached' => false,
                    ]);
                });

                return $respond($child);
            });

            return $result ?: response()->json(['message' => 'This batch is already generating. Wait, then retry the same request.'], 409);
        } catch (ContinuationOpeningException $exception) {
            return response()->json(['message' => $exception->getMessage(), 'code' => 'continuation_opening_mismatch', 'opening_issues' => $exception->issues], 502);
        } catch (PromptGenerationException $exception) {
            return response()->json(['message' => $exception->getMessage()], 502);
        } catch (HttpExceptionInterface $exception) {
            throw $exception;
        } catch (Throwable $exception) {
            report($exception);

            return response()->json(['message' => 'Story generation was interrupted. Retry this same request; existing prompts have not been replaced.'], 500);
        }
    }
}
