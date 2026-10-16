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
        $projects = $request->user()
            ->studioProjects()
            ->withCount('promptProjects')
            ->latest('updated_at')
            ->paginate(9);

        return view('projects.index', compact('projects'));
    }

    public function create(): View
    {
        return view('projects.create', ['settings' => ProjectSettings::defaults()]);
    }

    public function store(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'min:2', 'max:100'],
            ...ProjectSettings::rules(),
        ]);

        $project = $request->user()->studioProjects()->create([
            'name' => trim($validated['name']),
            'settings' => ProjectSettings::fromValidated($validated),
        ]);

        return redirect()->to(route('projects.show', $project, false))
            ->with('success', 'Your project is ready. Its saved preferences will be reused for each new prompt.');
    }

    public function show(Request $request, StudioProject $studioProject): View
    {
        $this->ensureOwner($request, $studioProject);
        $runs = $studioProject->promptProjects()->latest()->paginate(5);

        return view('studio.index', [
            'project' => $studioProject,
            'settings' => array_replace(ProjectSettings::defaults(), $studioProject->settings ?? []),
            'runs' => $runs,
            'aiReady' => \App\Models\AiSetting::query()->find(1)?->isReady() ?? false,
            'isAdmin' => (bool) $request->user()->is_admin,
        ]);
    }

    public function edit(Request $request, StudioProject $studioProject): View
    {
        $this->ensureOwner($request, $studioProject);

        return view('projects.edit', [
            'project' => $studioProject,
            'settings' => array_replace(ProjectSettings::defaults(), $studioProject->settings ?? []),
        ]);
    }

    public function update(Request $request, StudioProject $studioProject): RedirectResponse
    {
        $this->ensureOwner($request, $studioProject);

        $validated = $request->validate([
            'name' => ['required', 'string', 'min:2', 'max:100'],
            ...ProjectSettings::rules(),
        ]);

        $studioProject->update([
            'name' => trim($validated['name']),
            'settings' => ProjectSettings::fromValidated($validated, $studioProject->settings ?? []),
        ]);

        return redirect()->to(route('projects.show', $studioProject, false))
            ->with('success', 'Project settings saved. Future prompts will use your updated preferences.');
    }

    private function ensureOwner(Request $request, StudioProject $studioProject): void
    {
        abort_unless($studioProject->user_id === $request->user()->id, 404);
    }
}
