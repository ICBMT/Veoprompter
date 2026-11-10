<?php

namespace App\Http\Controllers;

use App\Models\AiSetting;
use App\Services\Ideas\LiveTrendFeed;
use App\Services\Ideas\VideoIdeaGenerator;
use App\Services\PromptGenerationException;
use App\Support\ProjectSettings;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class VideoIdeaController extends Controller
{
    public function index(Request $request)
    {
        $batch = $request->session()->get('video_ideas.batch');
        if ($batch && now()->timestamp >= $batch['expires_at']) $batch = null;
        return view('ideas.index', ['batch' => $batch, 'regions' => LiveTrendFeed::REGIONS,
            'videoTypes' => ProjectSettings::videoTypes(), 'aiReady' => AiSetting::find(1)?->isReady() ?? false]);
    }

    public function generate(Request $request, LiveTrendFeed $feed, VideoIdeaGenerator $generator)
    {
        $data = $request->validate(['region' => ['required', Rule::in(array_keys(LiveTrendFeed::REGIONS))],
            'video_type' => ['required', Rule::in(array_keys(ProjectSettings::videoTypes()))], 'audience' => 'required|string|max:160', 'request_id' => 'required|uuid']);
        $settings = AiSetting::find(1);
        if (! $settings?->isReady()) return back()->withErrors(['ideas' => 'An administrator must configure the AI connection first.'])->withInput();
        try {
            $result = Cache::lock('video-ideas:user:'.$request->user()->id, 960)->get(function () use ($request, $data, $settings, $feed, $generator) {
                $hash = hash('sha256', json_encode([$data['region'], $data['video_type'], $data['audience']]));
                $resultKey = 'video-ideas:result:'.$request->user()->id.':'.$data['request_id'];
                $previous = Cache::get($resultKey);
                if ($previous) {
                    abort_unless(($previous['request_hash'] ?? null) === $hash, 409, 'This request ID belongs to different idea inputs. Reload before generating again.');
                    $request->session()->put('video_ideas.batch', $previous);
                    return true;
                }
                $evidence = $feed->fetch($data['region']);
                $ideas = $generator->generate($settings, $evidence, $data['video_type'], $data['audience']);
                if (\Carbon\CarbonImmutable::now($evidence['timezone'])->toDateString() !== $evidence['date']) {
                    throw new PromptGenerationException('The local date changed during generation. Generate again for the new day; no stale list was saved.');
                }
                $batch = ['id' => (string) Str::uuid(), 'request_id' => $data['request_id'], 'request_hash' => $hash,
                    'generated_at' => now()->toIso8601String(), 'expires_at' => now()->addHours(3)->timestamp,
                    'audience' => $data['audience'], 'feed' => $evidence, 'ideas' => $ideas];
                Cache::put($resultKey, $batch, now()->addHours(3));
                $request->session()->put('video_ideas.batch', $batch);
                return true;
            });
            if (! $result) return back()->withErrors(['ideas' => 'An idea request is already running. Wait before trying again.'])->withInput();
        } catch (PromptGenerationException $exception) {
            return back()->withErrors(['ideas' => $exception->getMessage()])->withInput();
        } catch (\Symfony\Component\HttpKernel\Exception\HttpExceptionInterface $exception) { throw $exception;
        } catch (\Throwable $exception) {
            Log::warning('Video idea discovery could not finish.', ['exception' => $exception::class]);
            return back()->withErrors(['ideas' => 'Idea discovery was interrupted. Your existing projects and previous ideas were not changed. Please try again.'])->withInput();
        }
        return redirect()->route('ideas.index');
    }

    public function choose(Request $request)
    {
        $data = $request->validate(['batch_id' => 'required|uuid', 'index' => 'required|integer|between:0,9']);
        $batch = $request->session()->get('video_ideas.batch');
        abort_unless($batch && $batch['id'] === $data['batch_id'] && now()->timestamp < $batch['expires_at'], 409, 'These ideas expired or were replaced. Generate a fresh list.');
        $idea = $batch['ideas'][(int) $data['index']];
        $request->session()->put('video_ideas.draft', ['token' => (string) Str::uuid(), 'expires_at' => now()->addHours(3)->timestamp,
            'title' => $idea['title'], 'audience' => $batch['audience'], 'video_type' => $idea['video_type'], 'answers' => $idea['answers'], 'source' => $idea['source']]);
        return redirect()->to(route('projects.create', [], false).'?idea_draft='.$request->session()->get('video_ideas.draft.token'));
    }

    public static function draft(Request $request, ?int $projectId = null): ?array
    {
        $token = $projectId === null ? $request->input('idea_draft') : $request->query('idea_draft');
        if (! is_string($token) || $token === '') return null;
        $draft = $request->session()->get('video_ideas.draft');
        if (! $draft || ! hash_equals($draft['token'], $token) || now()->timestamp >= $draft['expires_at']) return null;
        if ($projectId !== null && ($draft['project_id'] ?? null) !== $projectId) return null;
        return $draft;
    }
}
