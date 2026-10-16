<?php

namespace App\Http\Controllers;

use App\Models\PromptProject;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\View\View;

class PromptHistoryController extends Controller
{
    public function index(Request $request): View
    {
        $validated = $request->validate([
            'q' => ['nullable', 'string', 'max:100'],
        ]);
        $search = trim((string) ($validated['q'] ?? ''));
        $projects = $request->user()->promptProjects()
            ->with('studioProject')
            ->when($search !== '', fn ($query) => $query->where('title', 'like', '%'.$search.'%'))
            ->latest()
            ->paginate(12)
            ->withQueryString();

        return view('history.index', [
            'projects' => $projects,
            'search' => $search,
            'totalProjects' => $request->user()->promptProjects()->count(),
        ]);
    }

    public function show(Request $request, PromptProject $project): View
    {
        abort_unless((int) $project->user_id === (int) $request->user()->id, 404);

        $project->load('studioProject');

        return view('history.show', [
            'project' => $project,
            'summary' => $this->summary($project),
        ]);
    }

    public function destroy(Request $request, PromptProject $project): RedirectResponse
    {
        abort_unless((int) $project->user_id === (int) $request->user()->id, 404);
        $project->delete();

        return redirect()->to(route('history.index', [], false))->with('success', 'That prompt has been removed from your library.');
    }

    private function summary(PromptProject $project): string
    {
        $brief = $project->brief ?? [];
        $subject = $project->mode === 'extend'
            ? ($brief['next_action'] ?? 'Video extension')
            : ($brief['topic'] ?? 'Video prompt');

        return Str::limit(trim((string) $subject), 180);
    }
}
