<?php

namespace App\Http\Controllers;

use App\Models\PromptProject;
use App\Services\PromptOutputAuditor;
use App\Services\PromptRepairService;
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

    public function show(Request $request, PromptProject $project, PromptOutputAuditor $auditor): View
    {
        abort_unless((int) $project->user_id === (int) $request->user()->id, 404);

        $project->load('studioProject');
        $qualityReview = is_array($project->quality_review)
            ? $project->quality_review
            : $auditor->audit((string) $project->content, is_array($project->brief) ? $project->brief : []);

        return view('history.show', [
            'project' => $project,
            'summary' => $this->summary($project),
            'qualityReview' => $qualityReview,
            'manualRepairAttempts' => PromptRepairService::manualAttempts($project->repair_history ?? []),
            'maxRepairAttempts' => PromptRepairService::MAX_MANUAL_ATTEMPTS,
            'hasQaIssues' => ! empty($qualityReview['issues']),
            'canRepair' => PromptRepairService::manualAttempts($project->repair_history ?? []) < PromptRepairService::MAX_MANUAL_ATTEMPTS
                && PromptRepairService::hasSupportedIssues($qualityReview),
            'canRestoreOriginal' => is_string($project->original_content) && $project->content !== $project->original_content,
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
