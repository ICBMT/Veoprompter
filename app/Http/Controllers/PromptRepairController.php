<?php

namespace App\Http\Controllers;

use App\Models\AiSetting;
use App\Models\PromptProject;
use App\Services\PromptOutputAuditor;
use App\Services\PromptRepairService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Throwable;

class PromptRepairController extends Controller
{
    public function repair(
        Request $request,
        PromptProject $project,
        PromptRepairService $repairer,
        PromptOutputAuditor $auditor,
    ): JsonResponse {
        abort_unless((int) $project->user_id === (int) $request->user()->id, 404);
        abort_if($request->hasAny(['prompt', 'content']), 422, 'Prompt text is never accepted by the repair endpoint.');

        $auditedContent = (string) $project->content;
        $brief = is_array($project->brief) ? $project->brief : [];
        $currentReview = $auditor->audit($auditedContent, $brief);
        if (! PromptRepairService::hasSupportedIssues($currentReview)) {
            $fresh = DB::transaction(function () use ($request, $project, $auditedContent, $currentReview): ?PromptProject {
                $locked = PromptProject::query()->whereKey($project->getKey())->lockForUpdate()->firstOrFail();
                abort_unless((int) $locked->user_id === (int) $request->user()->id, 404);
                if ($locked->content !== $auditedContent) {
                    return null;
                }
                $locked->quality_review = $currentReview;
                $locked->save();

                return $locked;
            });
            if ($fresh === null) {
                return response()->json([
                    'message' => 'This saved prompt changed while QA was running. Reload it before trying again.',
                ], 409);
            }

            $history = is_array($fresh->repair_history) ? $fresh->repair_history : [];
            $manualAttempts = PromptRepairService::manualAttempts($history);
            $issues = is_array($currentReview['issues'] ?? null) ? $currentReview['issues'] : [];
            $original = (string) ($fresh->original_content ?? $fresh->content);

            return response()->json([
                'content' => $fresh->content,
                'original_content' => $fresh->original_content,
                'quality_review' => $currentReview,
                'repair_report' => [
                    'outcome' => $issues === [] ? 'no_issues' : 'no_supported_issues',
                    'attempted' => false,
                    'applied' => false,
                    'fixed' => [],
                    'unresolved' => $issues,
                    'new_issues' => [],
                    'rejected_candidate_issues' => [],
                    'informational' => $issues === [] ? [] : ['No additional safe automatic repair applies to the remaining QA findings.'],
                    'notes' => $issues === [] ? [] : ['No additional safe automatic repair applies to the remaining QA findings.'],
                ],
                'manual_attempts' => $manualAttempts,
                'max_attempts' => PromptRepairService::MAX_MANUAL_ATTEMPTS,
                'has_supported_issues' => false,
                'can_repair' => false,
                'can_restore_original' => $fresh->content !== $original,
                'repair_url' => route('history.repair', $fresh, false),
                'restore_url' => route('history.restore', $fresh, false),
            ]);
        }

        $repairSignature = PromptRepairService::repairSignature($auditedContent, $brief, $currentReview);
        $reservation = DB::transaction(function () use ($request, $project, $auditedContent, $brief, $currentReview, $repairSignature): array {
            $locked = PromptProject::query()->whereKey($project->getKey())->lockForUpdate()->firstOrFail();
            abort_unless((int) $locked->user_id === (int) $request->user()->id, 404);

            $lockedBrief = is_array($locked->brief) ? $locked->brief : [];
            if ($locked->content !== $auditedContent || $lockedBrief !== $brief) {
                return ['stale' => true];
            }

            $history = is_array($locked->repair_history) ? $locked->repair_history : [];
            if (PromptRepairService::manualAttempts($history) >= PromptRepairService::MAX_MANUAL_ATTEMPTS) {
                return ['limit_reached' => true];
            }
            if (PromptRepairService::hasNonRetryableNoProgress($history, $repairSignature)) {
                return [
                    'blocked' => true,
                    'content' => (string) $locked->content,
                    'brief' => $lockedBrief,
                    'history' => $history,
                ];
            }

            $attemptId = (string) Str::uuid();
            $history[] = [
                'id' => $attemptId,
                'action' => 'repair',
                'status' => 'in_progress',
                'started_at' => now()->toIso8601String(),
                'repair_signature' => $repairSignature,
            ];
            $locked->repair_history = $history;
            $locked->save();

            return [
                'attempt_id' => $attemptId,
                'content' => (string) $locked->content,
                'brief' => $lockedBrief,
                'repair_signature' => $repairSignature,
            ];
        });

        if (($reservation['stale'] ?? false) === true) {
            return response()->json([
                'message' => 'The saved prompt or project brief changed while QA was running. Reload it before trying again.',
            ], 409);
        }
        if (($reservation['blocked'] ?? false) === true) {
            $history = $reservation['history'];
            $previousReport = [];
            foreach (array_reverse($history) as $entry) {
                if (is_array($entry) && ($entry['action'] ?? null) === 'repair'
                    && ($entry['repair_signature'] ?? null) === $repairSignature
                    && is_array($entry['report'] ?? null)) {
                    $previousReport = $entry['report'];
                    break;
                }
            }
            $manualAttempts = PromptRepairService::manualAttempts($history);
            $original = (string) ($project->original_content ?? $project->content);
            $informational = ['The same saved prompt and QA findings were already tried without verified progress. No attempt was consumed; change the relevant prompt/constraints or resolve the reported blocker before trying again.'];
            if (is_array($previousReport['informational'] ?? null)) {
                $informational = [...$previousReport['informational'], ...$informational];
            }

            return response()->json([
                'content' => $reservation['content'],
                'original_content' => $project->original_content,
                'quality_review' => $currentReview,
                'repair_report' => [
                    'outcome' => 'no_safe_changes',
                    'attempted' => false,
                    'retryable' => false,
                    'applied' => false,
                    'fixed' => [],
                    'unresolved' => $currentReview['issues'] ?? [],
                    'new_issues' => [],
                    'rejected_candidate_issues' => $previousReport['rejected_candidate_issues'] ?? [],
                    'informational' => array_values(array_unique($informational)),
                    'notes' => array_values(array_unique($informational)),
                ],
                'manual_attempts' => $manualAttempts,
                'max_attempts' => PromptRepairService::MAX_MANUAL_ATTEMPTS,
                'has_supported_issues' => true,
                'can_repair' => false,
                'can_restore_original' => $reservation['content'] !== $original,
                'repair_url' => route('history.repair', $project, false),
                'restore_url' => route('history.restore', $project, false),
            ]);
        }
        if (($reservation['limit_reached'] ?? false) === true) {
            return response()->json([
                'message' => 'The two automatic repair attempts for this saved prompt have been used. Review the remaining findings manually or restore the original prompt.',
                'manual_attempts' => PromptRepairService::MAX_MANUAL_ATTEMPTS,
                'max_attempts' => PromptRepairService::MAX_MANUAL_ATTEMPTS,
                'can_repair' => false,
            ], 409);
        }

        try {
            $settings = AiSetting::query()->find(1);
            $result = $repairer->repair($settings, $reservation['content'], $reservation['brief']);
        } catch (Throwable $exception) {
            report($exception);
            $this->finishReservation($project->getKey(), $reservation['attempt_id'], [
                'status' => 'failed',
                'completed_at' => now()->toIso8601String(),
            ]);

            return response()->json([
                'message' => 'The repair could not be completed. No prompt changes were saved; you can retry if an attempt remains.',
            ], 500);
        }

        $fresh = DB::transaction(function () use ($request, $project, $reservation, $result): ?PromptProject {
            $locked = PromptProject::query()->whereKey($project->getKey())->lockForUpdate()->firstOrFail();
            abort_unless((int) $locked->user_id === (int) $request->user()->id, 404);

            $history = is_array($locked->repair_history) ? $locked->repair_history : [];
            if ($locked->content !== $reservation['content']
                || (is_array($locked->brief) ? $locked->brief : []) !== $reservation['brief']) {
                foreach ($history as $index => $entry) {
                    if (is_array($entry) && ($entry['id'] ?? null) === $reservation['attempt_id']) {
                        $history[$index] = [...$entry, 'status' => 'stale', 'completed_at' => now()->toIso8601String()];
                        $locked->repair_history = $history;
                        $locked->save();
                        break;
                    }
                }

                return null;
            }

            $reservationIndex = null;
            foreach ($history as $index => $entry) {
                if (! is_array($entry) || ($entry['id'] ?? null) !== $reservation['attempt_id']) {
                    continue;
                }
                if (($entry['status'] ?? null) !== 'in_progress') {
                    return null;
                }
                $reservationIndex = $index;
                break;
            }
            if ($reservationIndex === null) {
                return null;
            }
            $history[$reservationIndex] = [
                ...$history[$reservationIndex],
                'status' => 'completed',
                'completed_at' => now()->toIso8601String(),
                'report' => $result['repair_report'],
            ];

            $locked->content = $result['content'];
            $locked->quality_review = $result['quality_review'];
            if ($locked->original_content === null) {
                // Older saved runs predate the original-content column; preserve their
                // current prompt as the baseline before applying the first repair.
                $locked->original_content = $reservation['content'];
            }
            $locked->repair_history = $history;
            $locked->save();

            return $locked;
        });

        if ($fresh === null) {
            return response()->json([
                'message' => 'This saved prompt changed or its repair reservation ended while repair was running. Reload it before trying again; the generated candidate was not saved.',
            ], 409);
        }

        $history = is_array($fresh->repair_history) ? $fresh->repair_history : [];
        $manualAttempts = PromptRepairService::manualAttempts($history);
        $original = (string) ($fresh->original_content ?? $fresh->content);
        $freshReview = is_array($fresh->quality_review) ? $fresh->quality_review : [];
        $freshBrief = is_array($fresh->brief) ? $fresh->brief : [];
        $freshSignature = PromptRepairService::repairSignature((string) $fresh->content, $freshBrief, $freshReview);
        $sameNoProgressRepairBlocked = PromptRepairService::hasNonRetryableNoProgress($history, $freshSignature);

        return response()->json([
            'content' => $fresh->content,
            'original_content' => $fresh->original_content,
            'quality_review' => $fresh->quality_review,
            'repair_report' => $result['repair_report'],
            'manual_attempts' => $manualAttempts,
            'max_attempts' => PromptRepairService::MAX_MANUAL_ATTEMPTS,
            'has_supported_issues' => PromptRepairService::hasSupportedIssues($freshReview),
            'can_repair' => $manualAttempts < PromptRepairService::MAX_MANUAL_ATTEMPTS
                && PromptRepairService::hasSupportedIssues($freshReview)
                && ! $sameNoProgressRepairBlocked,
            'can_restore_original' => $fresh->content !== $original,
            'repair_url' => route('history.repair', $fresh, false),
            'restore_url' => route('history.restore', $fresh, false),
        ]);
    }

    public function restore(
        Request $request,
        PromptProject $project,
        PromptOutputAuditor $auditor,
    ): JsonResponse {
        abort_unless((int) $project->user_id === (int) $request->user()->id, 404);
        abort_if($request->hasAny(['prompt', 'content']), 422, 'Prompt text is never accepted by the restore endpoint.');

        $restored = DB::transaction(function () use ($request, $project, $auditor): ?array {
            $locked = PromptProject::query()->whereKey($project->getKey())->lockForUpdate()->firstOrFail();
            abort_unless((int) $locked->user_id === (int) $request->user()->id, 404);

            if (! is_string($locked->original_content)) {
                return null;
            }

            $original = $locked->original_content;
            $review = $auditor->audit($original, is_array($locked->brief) ? $locked->brief : []);
            if ($locked->content === $original) {
                $locked->quality_review = $review;
                $locked->save();

                return [
                    'project' => $locked,
                    'quality_review' => $review,
                    'changed' => false,
                ];
            }

            $history = is_array($locked->repair_history) ? $locked->repair_history : [];
            $history[] = [
                'id' => (string) Str::uuid(),
                'action' => 'restore',
                'status' => 'completed',
                'completed_at' => now()->toIso8601String(),
                'outcome' => 'restored_original',
            ];

            $locked->content = $original;
            $locked->quality_review = $review;
            $locked->repair_history = $history;
            $locked->save();

            return [
                'project' => $locked,
                'quality_review' => $review,
                'changed' => true,
            ];
        });

        if ($restored === null) {
            return response()->json([
                'message' => 'This older saved prompt has no stored original to restore.',
            ], 409);
        }

        $fresh = $restored['project'];
        $review = $restored['quality_review'];
        $report = [
            'outcome' => $restored['changed'] ? 'restored_original' : 'already_original',
            'attempted' => false,
            'applied' => $restored['changed'],
            'fixed' => [],
            'unresolved' => $review['issues'] ?? [],
            'new_issues' => [],
            'rejected_candidate_issues' => [],
            'informational' => [$restored['changed']
                ? 'The original generated prompt was restored and the full QA audit was rerun.'
                : 'The saved prompt already matches its original version; the full QA audit was rerun.'],
            'notes' => [$restored['changed']
                ? 'The original generated prompt was restored and the full QA audit was rerun.'
                : 'The saved prompt already matches its original version; the full QA audit was rerun.'],
        ];
        $history = is_array($fresh->repair_history) ? $fresh->repair_history : [];

        return response()->json([
            'content' => $fresh->content,
            'original_content' => $fresh->original_content,
            'quality_review' => $review,
            'repair_report' => $report,
            'manual_attempts' => PromptRepairService::manualAttempts($history),
            'max_attempts' => PromptRepairService::MAX_MANUAL_ATTEMPTS,
            'has_supported_issues' => PromptRepairService::hasSupportedIssues($review),
            'can_repair' => PromptRepairService::manualAttempts($history) < PromptRepairService::MAX_MANUAL_ATTEMPTS
                && PromptRepairService::hasSupportedIssues($review),
            'can_restore_original' => false,
            'repair_url' => route('history.repair', $fresh, false),
            'restore_url' => route('history.restore', $fresh, false),
        ]);
    }

    private function finishReservation(int|string $projectId, string $attemptId, array $attributes): void
    {
        DB::transaction(function () use ($projectId, $attemptId, $attributes): void {
            $project = PromptProject::query()->whereKey($projectId)->lockForUpdate()->first();
            if (! $project) {
                return;
            }

            $history = is_array($project->repair_history) ? $project->repair_history : [];
            foreach ($history as $index => $entry) {
                if (! is_array($entry) || ($entry['id'] ?? null) !== $attemptId) {
                    continue;
                }
                $history[$index] = [...$entry, ...$attributes];
                $project->repair_history = $history;
                $project->save();
                break;
            }
        });
    }
}
