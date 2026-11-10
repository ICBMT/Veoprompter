<?php

namespace App\Http\Controllers;

use App\Models\StudioProject;
use App\Support\ProjectSettings;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class StudioProjectController extends Controller
{
    public function index(Request $request): View
    {
        $durationOptions = ProjectSettings::durationOptionsFor($request->user());
        $projects = $request->user()
            ->studioProjects()
            ->withCount('promptProjects')
            ->latest('updated_at')
            ->paginate(9);

        return view('projects.index', [
            'projects' => $projects,
            'videoTypes' => ProjectSettings::videoTypes(),
            'durationOptions' => $durationOptions,
        ]);
    }

    public function create(Request $request): View
    {
        $durationOptions = ProjectSettings::durationOptionsFor($request->user());

        $ideaDraft = VideoIdeaController::draft($request);
        $defaults = $ideaDraft ? [...ProjectSettings::defaults(), 'audience' => $ideaDraft['audience'], 'video_type' => $ideaDraft['video_type']] : ProjectSettings::defaults();

        return view('projects.create', [
            'ideaDraft' => $ideaDraft,
            'settings' => ProjectSettings::applyDurationOptions($defaults, $durationOptions),
            'durationOptions' => $durationOptions,
            'videoTypes' => ProjectSettings::videoTypes(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $durationOptions = ProjectSettings::durationOptionsFor($request->user());
        $request->merge(ProjectSettings::forcedDurationFields($durationOptions));
        $validated = $request->validate([
            'name' => ['required', 'string', 'min:2', 'max:100'],
            ...ProjectSettings::rules($durationOptions),
        ]);

        if ($request->filled('idea_draft')) {
            abort_unless(VideoIdeaController::draft($request), 409, 'This idea draft expired or was replaced. Return to Ideas and select it again; no project was created.');
        }

        $project = $request->user()->studioProjects()->create([
            'name' => trim($validated['name']),
            'settings' => ProjectSettings::fromValidated($validated, [], $durationOptions),
        ]);

        $ideaDraft = VideoIdeaController::draft($request);
        $destination = route('projects.show', $project, false);
        if ($ideaDraft) {
            $request->session()->put('video_ideas.draft', [...$ideaDraft, 'project_id' => (int) $project->id]);
            $destination .= '?idea_draft='.$ideaDraft['token'];
        }

        return redirect()->to($destination)
            ->with('success', 'Your project is ready. Its saved preferences will be reused for each new prompt.');
    }

    public function show(Request $request, StudioProject $studioProject): View
    {
        $this->ensureOwner($request, $studioProject);
        $runs = $studioProject->promptProjects()->latest()->paginate(5);
        $durationOptions = ProjectSettings::durationOptionsFor($request->user());

        return view('studio.index', [
            'ideaPrefill' => VideoIdeaController::draft($request, (int) $studioProject->id),
            'project' => $studioProject,
            'settings' => ProjectSettings::applyDurationOptions($studioProject->settings ?? [], $durationOptions),
            'durationOptions' => $durationOptions,
            'videoTypes' => ProjectSettings::videoTypes(),
            'runs' => $runs,
            'aiReady' => \App\Models\AiSetting::query()->find(1)?->isReady() ?? false,
            'isAdmin' => (bool) $request->user()->is_admin,
        ]);
    }

    public function edit(Request $request, StudioProject $studioProject): View
    {
        $this->ensureOwner($request, $studioProject);
        $durationOptions = ProjectSettings::durationOptionsFor($request->user());

        return view('projects.edit', [
            'project' => $studioProject,
            'settings' => ProjectSettings::applyDurationOptions($studioProject->settings ?? [], $durationOptions),
            'durationOptions' => $durationOptions,
            'videoTypes' => ProjectSettings::videoTypes(),
        ]);
    }

    public function update(Request $request, StudioProject $studioProject): RedirectResponse
    {
        $this->ensureOwner($request, $studioProject);
        $durationOptions = ProjectSettings::durationOptionsFor($request->user());
        $request->merge(ProjectSettings::forcedDurationFields($durationOptions));

        $validated = $request->validate([
            'name' => ['required', 'string', 'min:2', 'max:100'],
            ...ProjectSettings::rules($durationOptions),
        ]);

        $studioProject->update([
            'name' => trim($validated['name']),
            'settings' => ProjectSettings::fromValidated($validated, $studioProject->settings ?? [], $durationOptions),
        ]);

        return redirect()->to(route('projects.show', $studioProject, false))
            ->with('success', 'Project settings saved. Future prompts will use your updated preferences.');
    }

    private function ensureOwner(Request $request, StudioProject $studioProject): void
    {
        abort_unless($studioProject->user_id === $request->user()->id, 404);
    }
}
