<?php

namespace App\Services;

use App\Models\AiSetting;
use App\Support\ProjectSettings;

/**
 * Applies conservative, bounded repairs to audited prompt sections. The
 * PromptOutputAuditor remains the authority for every before/after QA result.
 */
class PromptRepairService
{
    public const MAX_MANUAL_ATTEMPTS = 2;

    private const MAX_AI_STATE_PAIRS = 7;

    private const SPEECH_REPAIR_CODES = [
        'speech_rows_overlap',
        'speech_exceeds_clip',
        'speech_time_range_invalid',
        'speech_overrun',
        'speech_budget_exceeded',
        'speech_opening_visual_beat_missing',
        'speech_inter_line_pause_missing',
        'speech_leaves_no_visual_tail',
    ];

    public function __construct(
        private readonly PromptOutputAuditor $auditor,
        private readonly PromptGenerationService $generation,
    ) {}

    public static function supportsIssueCode(string $code): bool
    {
        return in_array($code, [
            'continuity_state_mismatch',
            'presentation_state_mismatch',
            'overview_clip_duration_mismatch',
            'overview_total_runtime_mismatch',
            'clip_plan_duration_mismatch',
            'declared_duration_constraint_violation',
            'ai_duration_over_limit',
            'continuity_audit_overclaim',
            'timing_audit_overclaim',
            'speech_pacing_overclaim',
            ...self::SPEECH_REPAIR_CODES,
        ], true);
    }

    public static function hasSupportedIssues(array $review): bool
    {
        foreach ($review['issues'] ?? [] as $issue) {
            if (self::supportsIssueCode((string) ($issue['code'] ?? ''))) {
                return true;
            }
        }

        return false;
    }

    public static function manualAttempts(array $history): int
    {
        return count(array_filter(
            $history,
            static fn (mixed $entry): bool => is_array($entry) && ($entry['action'] ?? null) === 'repair',
        ));
    }

    public static function repairSignature(string $content, array $brief, array $review): string
    {
        $issues = array_map(
            static fn (array $issue): array => [
                'code' => (string) ($issue['code'] ?? ''),
                'category' => (string) ($issue['category'] ?? ''),
                'clip' => $issue['clip'] ?? null,
                'message' => (string) ($issue['message'] ?? ''),
            ],
            array_values(array_filter($review['issues'] ?? [], 'is_array')),
        );
        sort($issues);
        $encoded = json_encode(
            ['content' => $content, 'brief' => $brief, 'issues' => $issues],
            JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE,
        );

        return hash('sha256', $encoded === false ? serialize([$content, $brief, $issues]) : $encoded);
    }

    public static function hasNonRetryableNoProgress(array $history, string $signature): bool
    {
        foreach (array_reverse($history) as $entry) {
            if (! is_array($entry)
                || ($entry['action'] ?? null) !== 'repair'
                || ($entry['status'] ?? null) !== 'completed'
                || ($entry['repair_signature'] ?? null) !== $signature
                || ! is_array($entry['report'] ?? null)) {
                continue;
            }

            return ($entry['report']['applied'] ?? false) !== true
                && ($entry['report']['retryable'] ?? false) !== true;
        }

        return false;
    }

    /**
     * @return array{
     *   content: string,
     *   quality_review: array<string, mixed>,
     *   repair_report: array<string, mixed>
     * }
     */
    public function repair(?AiSetting $settings, string $content, array $input): array
    {
        $originalReview = $this->auditor->audit($content, $input);
        if ($originalReview['issues'] === []) {
            return $this->result($content, $originalReview, 'no_issues', false, [], [], [], ['No QA issues remain to repair.'], false);
        }

        if (! self::hasSupportedIssues($originalReview)) {
            return $this->result($content, $originalReview, 'no_supported_issues', false, [], $originalReview['issues'], [], [
                'The remaining findings require manual review and are not safe to repair automatically.',
            ], false);
        }

        $notes = [];
        $retryable = false;
        $candidate = $this->applyDeterministicRepairs($content, $input, $originalReview, $notes);
        $deterministicReview = $this->auditor->audit($candidate, $input);
        $deterministicRegressions = $this->addedIssues($originalReview, $deterministicReview);

        $rejectedCandidateIssues = $deterministicRegressions;
        if ($deterministicRegressions !== []) {
            $notes[] = 'A deterministic candidate introduced new QA findings, so all of those edits were discarded.';
            $candidate = $content;
            $workingReview = $originalReview;
        } else {
            $workingReview = $deterministicReview;
        }

        $statePairs = $this->stateMismatchPairs($candidate, $input, $workingReview);
        if ($statePairs === [] && ($input['mode'] ?? 'story') !== 'extend'
            && ($input['clip_count'] ?? '') === ProjectSettings::AI_QUALITY_CLIP_COUNT) {
            $this->proposeSpeechRepartition($settings, $candidate, $input, $workingReview, $notes, $retryable);
        }
        if ($statePairs !== []) {
            $prioritizedPairs = $this->prioritizeStatePairs($statePairs);
            $limitedPairs = array_slice($prioritizedPairs, 0, self::MAX_AI_STATE_PAIRS);
            $omittedPairs = array_slice($prioritizedPairs, self::MAX_AI_STATE_PAIRS);
            if ($omittedPairs !== []) {
                $omittedLabels = array_map(
                    static fn (array $pair): string => 'Clip '.$pair['prior_clip']['number'].'→'.$pair['next_clip']['number'],
                    $omittedPairs,
                );
                $notes[] = 'The bounded contextual review sent '.count($limitedPairs).' handoff pair(s); the final adjacent handoff was prioritized. These omitted pairs remain unresolved: '.implode(', ', $omittedLabels).'.';
            }

            $decisions = [];
            if ($settings?->isReady()) {
                try {
                    $response = $this->generation->requestTargetedRepair(
                        $settings,
                        $this->buildStateRepairBrief($limitedPairs, $input),
                    );
                    $decisions = $this->parseStateDecisions($response, $limitedPairs);
                } catch (\Throwable) {
                    $retryable = true;
                    $notes[] = 'Contextual continuity review was unavailable; ambiguous state mismatches were left for manual review. Retry only after provider availability changes.';
                }
            } else {
                $retryable = true;
                $notes[] = 'No ready AI provider was available for contextual continuity review; ambiguous state mismatches were left for manual review. Retry only after provider availability changes.';
            }

            $aiCandidate = $this->applyStateDecisions($candidate, $input, $limitedPairs, $decisions, $notes);

            if ($aiCandidate !== $candidate) {
                $aiReview = $this->auditor->audit($aiCandidate, $input);
                $aiRegressions = $this->addedIssues($workingReview, $aiReview);
                if ($aiRegressions !== []) {
                    $notes[] = 'The contextual candidate introduced new QA findings, so those state edits were discarded; any independently safe deterministic fixes were retained.';
                    $rejectedCandidateIssues = [...$rejectedCandidateIssues, ...$aiRegressions];
                } else {
                    $candidate = $aiCandidate;
                    $workingReview = $aiReview;
                }
            }
        }

        // Always finish with the complete auditor on the final candidate, even
        // when contextual review chose REVIEW and made no edit.
        $finalReview = $this->auditor->audit($candidate, $input);
        $finalRegressions = $this->addedIssues($originalReview, $finalReview);
        if ($finalRegressions !== []) {
            $rejectedCandidateIssues = [...$rejectedCandidateIssues, ...$finalRegressions];
            $notes[] = 'The final full QA pass found new issues, so the candidate was discarded and the original prompt was kept.';
            $candidate = $content;
            $workingReview = $originalReview;
        } else {
            $workingReview = $finalReview;
        }

        $fixed = $this->removedIssues($originalReview, $workingReview);
        $newIssues = $this->addedIssues($originalReview, $workingReview);
        $applied = $candidate !== $content && $fixed !== [] && $newIssues === [];

        if (! $applied) {
            if ($candidate !== $content && $fixed === []) {
                $notes[] = 'No original QA issue was fully resolved; the original prompt was kept unchanged.';
            }
            if ($newIssues !== []) {
                $notes[] = 'The candidate was rejected because it introduced new QA findings; the original prompt was kept unchanged.';
            }
            $candidate = $content;
            $workingReview = $originalReview;
            $fixed = [];
            $newIssues = [];
        }

        $outcome = $applied
            ? ($workingReview['issues'] === [] ? 'applied' : 'applied_with_remaining_issues')
            : ($rejectedCandidateIssues !== [] ? 'rejected_new_issues' : 'no_safe_changes');

        return $this->result(
            $candidate,
            $workingReview,
            $outcome,
            $applied,
            $fixed,
            $workingReview['issues'],
            $newIssues,
            $notes,
            true,
            $rejectedCandidateIssues,
            $retryable,
        );
    }

    private function applyDeterministicRepairs(string $content, array $input, array $review, array &$notes): string
    {
        $lineEnding = str_contains($content, "\r\n") ? "\r\n" : "\n";
        $lines = preg_split('/\R/u', str_replace("\r", '', $content)) ?: [];
        $plan = $this->auditor->canonicalProjectPlan($content, $input);
        $clips = $plan->clips;
        if ($clips === []) {
            return $content;
        }

        foreach ($clips as $index => $nextClip) {
            if ($index === 0) {
                continue;
            }

            $previousClip = $clips[$index - 1];
            $hasContinuityIssue = $this->hasIssue($review, 'continuity_state_mismatch', $nextClip['number']);
            if ($hasContinuityIssue) {
                $ending = $previousClip['ending_continuity_state'];
                $opening = $nextClip['opening_continuity_state'];
                if ($ending !== null && $opening !== null && $this->equivalentStateMaps($ending, $opening)) {
                    $openingProse = $nextClip['exact_opening_frame'];
                    $endingProse = $previousClip['exact_ending_state'];
                    $needsProseSync = $openingProse !== null
                        && $this->auditor->stateProseRelation($ending, $openingProse) === 'mismatch';
                    $endingProseRelation = $this->auditor->stateProseRelation($ending, $endingProse);
                    $canSyncProse = ! $needsProseSync
                        || ($endingProse !== null && in_array($endingProseRelation, ['exact', 'compatible'], true));

                    if (! $canSyncProse) {
                        $notes[] = 'Clip '.$nextClip['number'].' continuity metadata was left for review because its written opening conflicts with the safe structured-state source.';
                    } elseif ($this->replaceField($lines, $nextClip, 'OPENING CONTINUITY STATE', $ending)) {
                        if ($needsProseSync && $endingProse !== null) {
                            $this->replaceField($lines, $nextClip, 'EXACT OPENING FRAME', $endingProse);
                            $notes[] = 'Aligned Clip '.$nextClip['number'].' exact opening-state prose to the existing prior ending description; visible-action and dialogue text were preserved.';
                        }
                        $notes[] = 'Matched Clip '.$nextClip['number'].' continuity metadata deterministically because both key/value state maps contained the same facts.';
                    }
                }
            }

            $hasPresentationIssue = $this->hasIssue($review, 'presentation_state_mismatch', $nextClip['number']);
            if ($hasPresentationIssue) {
                $ending = $previousClip['ending_presentation_state'];
                $opening = $nextClip['opening_presentation_state'];
                if ($ending !== null && $opening !== null && $this->equivalentStateMaps($ending, $opening)) {
                    if ($this->replaceField($lines, $nextClip, 'OPENING PRESENTATION STATE', $ending)) {
                        $notes[] = 'Matched Clip '.$nextClip['number'].' presentation metadata deterministically because both key/value state maps contained the same facts.';
                    }
                }
            }
        }

        $content = implode($lineEnding, $lines);
        $plan = $this->auditor->canonicalProjectPlan($content, $input);
        $clips = $plan->clips;
        $speechIssueClips = [];
        $durationIssueClips = [];
        foreach ($review['issues'] as $issue) {
            if ($issue['clip'] === null) {
                continue;
            }
            if (in_array($issue['code'], self::SPEECH_REPAIR_CODES, true)) {
                $speechIssueClips[$issue['clip']] = true;
            }
            if (in_array($issue['code'], ['declared_duration_constraint_violation', 'ai_duration_over_limit'], true)) {
                $durationIssueClips[$issue['clip']] = true;
            }
        }

        foreach ($clips as $clip) {
            $hasSpeechIssue = isset($speechIssueClips[$clip['number']]);
            $hasDurationIssue = isset($durationIssueClips[$clip['number']]);
            if (! $hasSpeechIssue && ! $hasDurationIssue) {
                continue;
            }

            $timing = $this->repairSpeechTiming($clip, $plan, $input);
            if ($timing === null) {
                $notes[] = $hasSpeechIssue
                    ? 'Clip '.$clip['number'].' dialogue was preserved; its existing timing cannot fit the effective project and saved duration limits safely, so the findings remain for review. No words, speaker identities, existing delivery windows, visual setup or longer pauses were shortened. Replan the connected scene across more clips when count is AI-selected; a fixed count/duration requires an explicit user change.'
                    : 'Clip '.$clip['number'].' duration was left unchanged because no safe repair fits the effective project and saved duration limits.';
                continue;
            }
            if ($timing['duration'] !== null && $timing['duration'] !== $timing['old_duration']) {
                // Include earlier accepted clip edits in the project-wide budget.
                $content = implode($lineEnding, $lines);
                if (! $this->canSynchronizeDurationRepresentations($content, $input)) {
                    $notes[] = 'Clip '.$clip['number'].' duration and speech timing were left unchanged because not every declared timing representation can be synchronized safely.';
                    continue;
                }
                $currentPlan = $this->auditor->canonicalProjectPlan($content, $input);
                $runtimeMaximum = $currentPlan->projectRuntimeLimits['maximum'] ?? null;
                $currentRuntime = $currentPlan->totalRuntimeSeconds();
                $proposedRuntime = $currentRuntime === null
                    ? null
                    : $currentRuntime - $timing['old_duration'] + $timing['duration'];
                if ($runtimeMaximum !== null && ($proposedRuntime === null || $proposedRuntime > $runtimeMaximum + 0.01)) {
                    $notes[] = 'Clip '.$clip['number'].' duration was not changed: the proposed timing would exceed the saved project-wide maximum of '.$runtimeMaximum.' seconds; no dialogue or other clip duration was changed to make room.';
                    continue;
                }
                $runtimeMinimum = $currentPlan->projectRuntimeLimits['minimum'] ?? null;
                if ($runtimeMinimum !== null && ($proposedRuntime === null || $proposedRuntime < $runtimeMinimum - 0.01)) {
                    $notes[] = 'Clip '.$clip['number'].' duration was not changed: the proposed timing would fall below the saved project-wide minimum of '.$runtimeMinimum.' seconds; no other clip was padded to make room.';
                    continue;
                }
            }
            if ($timing['changed']) {
                foreach ($timing['rows'] as $row) {
                    $globalLine = $clip['start_line'] + $row['line_index'];
                    if (isset($lines[$globalLine])) {
                        $lines[$globalLine] = $row['replacement'];
                    }
                }
                if ($timing['duration'] !== null && $timing['duration'] !== $timing['old_duration']) {
                    $this->replaceField($lines, $clip, 'CLIP DURATION', $timing['duration'].' seconds');
                    $notes[] = 'Clip '.$clip['number'].' duration was changed to '.$timing['duration'].' seconds within the effective project and saved limits; unchanged dialogue was preserved.';
                }
                if ($hasSpeechIssue) {
                    $notes[] = 'Clip '.$clip['number'].' speech windows were re-timed without changing dialogue or visible-action text.';
                }
            }
        }

        $content = implode($lineEnding, $lines);
        $content = $this->synchronizeDurationRepresentations($content, $input, $notes);

        $finalAuditReview = $this->auditor->audit($content, $input);
        if ($this->hasIssue($finalAuditReview, 'continuity_audit_overclaim', null)
            || $this->hasIssue($finalAuditReview, 'timing_audit_overclaim', null)
            || $this->hasIssue($finalAuditReview, 'speech_pacing_overclaim', null)) {
            $content = $this->correctFinalContinuityAuditClaims($content, $notes);
        }

        return $content;
    }

    private function canSynchronizeDurationRepresentations(string $content, array $input): bool
    {
        if (($input['mode'] ?? 'story') === 'extend') {
            return true;
        }

        $plan = $this->auditor->canonicalProjectPlan($content, $input);
        $limits = $plan->durationLimits;
        if ($limits['conflict'] || ($plan->projectRuntimeLimits['conflict'] ?? false)) {
            return false;
        }
        if (! $plan->hasCompleteSequentialClipDurations()) {
            return false;
        }

        $durations = $plan->clipDurations();
        foreach ($plan->clips as $clip) {
            $duration = $clip['duration_seconds'] ?? null;
            if (! is_numeric($duration) || $duration <= 0 || floor((float) $duration) !== (float) $duration) {
                return false;
            }
            if ($limits['fixed_duration'] !== null && abs((float) $duration - $limits['fixed_duration']) > 0.01) {
                return false;
            }
        }

        if ($this->auditor->hasProjectOverviewSection($content)) {
            $references = $plan->overviewDurations;
            if ($references === [] || $plan->overviewRuntimes === []) {
                return false;
            }
            $referencedClips = array_unique(array_column($references, 'clip'));
            foreach (array_keys($durations) as $clipNumber) {
                if (! in_array($clipNumber, $referencedClips, true)) {
                    return false;
                }
            }
            foreach ($referencedClips as $clipNumber) {
                if (! array_key_exists($clipNumber, $durations)) {
                    return false;
                }
            }
        }

        if ($this->auditor->hasClipPlanSection($content)) {
            $references = $plan->clipPlanDurations;
            if ($references === []) {
                return false;
            }
            $referencedClips = array_unique(array_column($references, 'clip'));
            foreach (array_keys($durations) as $clipNumber) {
                if (! in_array($clipNumber, $referencedClips, true)) {
                    return false;
                }
            }
            foreach ($referencedClips as $clipNumber) {
                if (! array_key_exists($clipNumber, $durations)) {
                    return false;
                }
            }
        }

        return true;
    }

    private function synchronizeDurationRepresentations(string $content, array $input, array &$notes): string
    {
        if (($input['mode'] ?? 'story') === 'extend' || ! $this->canSynchronizeDurationRepresentations($content, $input)) {
            return $content;
        }

        $plan = $this->auditor->canonicalProjectPlan($content, $input);
        $durations = $plan->clipDurations();
        if ($durations === []) {
            return $content;
        }

        $lineEnding = str_contains($content, "\r\n") ? "\r\n" : "\n";
        $lines = preg_split('/\R/u', str_replace("\r", '', $content)) ?: [];
        $updates = [];
        foreach ($plan->overviewDurations as $reference) {
            if (array_key_exists($reference['clip'], $durations)) {
                $updates[] = [
                    ...$reference,
                    'replacement' => $this->overviewDurationValue($durations[$reference['clip']], $reference['unit'], $reference['value']),
                ];
            }
        }
        foreach ($plan->overviewRuntimes as $reference) {
            $updates[] = [
                ...$reference,
                'replacement' => $this->overviewDurationValue(array_sum($durations), $reference['unit'], $reference['value']),
            ];
        }
        foreach ($plan->clipPlanDurations as $reference) {
            if (array_key_exists($reference['clip'], $durations)) {
                $updates[] = [
                    ...$reference,
                    'replacement' => $this->overviewDurationValue($durations[$reference['clip']], $reference['unit'], $reference['value']),
                ];
            }
        }

        usort($updates, static fn (array $left, array $right): int => [$right['line_index'], $right['offset']] <=> [$left['line_index'], $left['offset']]);
        $changed = false;
        foreach ($updates as $update) {
            $lineIndex = $update['line_index'];
            if (! isset($lines[$lineIndex])) {
                continue;
            }
            $current = substr($lines[$lineIndex], $update['offset'], $update['length']);
            if ($current === $update['replacement']) {
                continue;
            }
            $lines[$lineIndex] = substr_replace($lines[$lineIndex], $update['replacement'], $update['offset'], $update['length']);
            $changed = true;
        }

        if (! $changed) {
            return $content;
        }

        $notes[] = 'Synchronized all parseable project-overview, clip-plan / shot-list durations, and total runtime with the final copy-ready CLIP DURATION values.';

        return implode($lineEnding, $lines);
    }

    private function overviewDurationValue(float $seconds, string $unit, string $existing): string
    {
        if (in_array($unit, ['clock_mmss', 'clock_hhmmss'], true)) {
            $wholeSeconds = max(0, (int) round($seconds));
            $existingParts = explode(':', $existing);
            if ($unit === 'clock_hhmmss') {
                $hours = intdiv($wholeSeconds, 3600);
                $minutes = intdiv($wholeSeconds % 3600, 60);
                $remainder = $wholeSeconds % 60;

                return str_pad((string) $hours, max(2, strlen($existingParts[0] ?? '00')), '0', STR_PAD_LEFT)
                    .':'.str_pad((string) $minutes, max(2, strlen($existingParts[1] ?? '00')), '0', STR_PAD_LEFT)
                    .':'.str_pad((string) $remainder, max(2, strlen($existingParts[2] ?? '00')), '0', STR_PAD_LEFT);
            }

            $minutes = intdiv($wholeSeconds, 60);
            $remainder = $wholeSeconds % 60;

            return str_pad((string) $minutes, max(1, strlen($existingParts[0] ?? '0')), '0', STR_PAD_LEFT)
                .':'.str_pad((string) $remainder, max(2, strlen($existingParts[1] ?? '00')), '0', STR_PAD_LEFT);
        }

        $minutes = str_starts_with(strtolower($unit), 'm');
        $value = $minutes ? $seconds / 60 : $seconds;
        $decimalPoint = strpos($existing, '.');
        $decimals = $decimalPoint === false ? 0 : min(6, strlen($existing) - $decimalPoint - 1);
        if ($minutes && abs($value - round($value)) > 0.000001) {
            $decimals = max(4, $decimals);
        }

        return number_format($value, $decimals, '.', '');
    }

    private function correctFinalContinuityAuditClaims(string $content, array &$notes): string
    {
        $claimGroups = [
            'continuity' => $this->auditor->continuityAuditClaimLineIndexes($content),
            'timing' => $this->auditor->timingAuditClaimLineIndexes($content),
            'speech' => $this->auditor->speechPacingAuditClaimLineIndexes($content),
        ];
        $claimLines = [];
        foreach ($claimGroups as $kind => $indexes) {
            foreach ($indexes as $index) {
                $claimLines[$index][$kind] = true;
            }
        }
        if ($claimLines === []) {
            return $content;
        }

        $lineEnding = str_contains($content, "\r\n") ? "\r\n" : "\n";
        $lines = preg_split('/\R/u', str_replace("\r", '', $content)) ?: [];
        foreach ($claimLines as $lineIndex => $kinds) {
            if (! isset($lines[$lineIndex])) {
                continue;
            }
            preg_match('/^\\s*(?:(?:[-*+]\\s+)|(?:>\\s*)?)?/u', $lines[$lineIndex], $prefix);
            $evidenceBasedLines = [];
            if (isset($kinds['continuity'])) {
                $evidenceBasedLines[] = 'Handoffs with compatible, mismatched, or unverified states are not verbatim copies; reserve exact-copy claims for verified structured and written matches.';
            }
            if (isset($kinds['timing'])) {
                $evidenceBasedLines[] = 'Timing claims reflect parsed QA only; overview totals are compared with the final clip sum, and clip-plan values are checked when present. Missing or conflicting timings remain for review.';
            }
            if (isset($kinds['speech'])) {
                $evidenceBasedLines[] = 'Text QA uses conservative word-time estimates; actual delivery and same-clip visual alignment still require human review.';
            }
            $lines[$lineIndex] = ($prefix[0] ?? '').implode(' ', $evidenceBasedLines);
        }

        $notes[] = 'Rephrased final-audit claims to match the evidence available from full text QA; actual speech delivery and rendered visuals remain for human review.';

        return implode($lineEnding, $lines);
    }

    /** Contextual overflow proposals stay unresolved until a safe replan and full QA can prove them. */
    private function proposeSpeechRepartition(?AiSetting $settings, string $content, array $input, array $review, array &$notes, bool &$retryable): void
    {
        $numbers = [];
        foreach ($review['issues'] ?? [] as $issue) {
            if (in_array($issue['code'] ?? '', self::SPEECH_REPAIR_CODES, true)) {
                $numbers[(int) $issue['clip']] = true;
            }
        }
        $excerpts = [];
        foreach ($this->auditor->canonicalProjectPlan($content, $input)->clips as $clip) {
            if (isset($numbers[$clip['number']]) && $clip['speech_rows'] !== []) {
                $excerpts[] = ['clip' => $clip['number'], 'duration' => $clip['duration_seconds'],
                    'speech_budget' => $clip['speech_budget'], 'visible_action' => $clip['visible_cause_action_result'],
                    'speech_rows' => $clip['speech_rows']];
            }
        }
        if ($excerpts === [] || ! $settings?->isReady()) {
            return;
        }
        // One bounded advisory request; never ask a model to solve an impossible fixed window by rewriting words.
        $excerpts = array_slice($excerpts, 0, 4);
        $encoded = json_encode(['saved_settings' => $input, 'affected_clips' => $excerpts], JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
        if ($encoded === false || strlen($encoded) > 24000) {
            $notes[] = 'Contextual speech proposal exceeded the bounded review size; overflow remains unresolved.';
            return;
        }
        try {
            $response = $this->generation->requestSpeechTimingProposal($settings, $encoded);
            $decisions = json_decode($response, true);
            $allowed = array_column($excerpts, 'clip');
            $seen = [];
            foreach (is_array($decisions['clips'] ?? null) ? $decisions['clips'] : [] as $decision) {
                if (! is_array($decision) || ! in_array($decision['clip'] ?? null, $allowed, true)
                    || isset($seen[$decision['clip']])
                    || ! in_array($decision['proposal'] ?? '', ['SPLIT_REQUIRED', 'REVIEW'], true)) {
                    continue;
                }
                $seen[$decision['clip']] = true;
                $notes[] = 'Clip '.$decision['clip'].($decision['proposal'] === 'SPLIT_REQUIRED'
                    ? ': AI proposes splitting the connected visible scene and exact dialogue across more clips. No split was applied: safe visual alignment, all duration views, project limits and handoffs require a contextual replan followed by full QA.'
                    : ': AI could not propose a safe split within the supplied context. Exact dialogue is unchanged and timing remains unresolved.');
            }
            if ($seen === []) {
                $notes[] = 'Contextual speech review supplied no valid bounded proposal; overflow remains unresolved.';
            }
        } catch (\Throwable) {
            $retryable = true;
            $notes[] = 'Contextual speech review was unavailable. No dialogue was changed; overflow remains unresolved.';
        }
    }

    /** @return array{changed: bool, rows: array<int, array{line_index: int, replacement: string}>, duration: int|null, old_duration: int}|null */
    private function repairSpeechTiming(array $clip, PromptProjectPlan $plan, array $input): ?array
    {
        $clipText = (string) ($clip['text'] ?? '');
        $durationValue = $clip['duration_seconds'] ?? null;
        if (! is_numeric($durationValue) || (float) $durationValue <= 0 || floor((float) $durationValue) !== (float) $durationValue) {
            return null;
        }

        $limits = $plan->durationLimits;
        if ($limits['conflict']) {
            return null;
        }
        $aiSelected = $limits['is_ai_selected'];
        $oldDuration = (int) $durationValue;
        $targetDuration = $oldDuration;
        if ($aiSelected) {
            $targetDuration = max($limits['minimum'], min($targetDuration, $limits['maximum']));
        } elseif ($limits['fixed_duration'] === null || $oldDuration !== $limits['fixed_duration']) {
            return null;
        }

        $speechRows = is_array($clip['speech_rows'] ?? null) ? $clip['speech_rows'] : [];
        preg_match_all('/\\bLINE\\s*:/iu', $clipText, $rawSpeechLines);
        if (count($rawSpeechLines[0] ?? []) !== count($speechRows)) {
            return null;
        }
        $sourceLines = preg_split('/\\R/u', $clipText) ?: [];
        $rows = [];
        foreach ($speechRows as $speechRow) {
            $lineIndex = $speechRow['line_index'] ?? null;
            if (! is_numeric($lineIndex) || ! isset($sourceLines[(int) $lineIndex])
                || ! is_numeric($speechRow['start'] ?? null)
                || ! is_numeric($speechRow['end'] ?? null)
                || trim((string) ($speechRow['action'] ?? '')) === ''
                || trim((string) ($speechRow['speaker'] ?? '')) === ''
                || trim((string) ($speechRow['line'] ?? '')) === '') {
                return null;
            }

            $minimum = ceil(((float) ($speechRow['minimum_seconds'] ?? $this->auditor->minimumSpeechSeconds($speechRow['line']))) * 10) / 10;
            $rows[] = [
                'line_index' => (int) $lineIndex,
                'start' => (float) $speechRow['start'],
                'end' => (float) $speechRow['end'],
                'minimum' => $minimum,
                'existing' => max(0.0, (float) $speechRow['end'] - (float) $speechRow['start']),
                'suffix' => $this->speechSuffix($sourceLines[(int) $lineIndex]),
            ];
        }

        if ($rows === []) {
            $audioOff = in_array(strtolower((string) ($input['narration'] ?? '1')), ['0', 'false', 'off', 'none'], true);
            if (! $audioOff || $targetDuration === $oldDuration) {
                return null;
            }

            return [
                'changed' => true,
                'rows' => [],
                'duration' => $targetDuration,
                'old_duration' => $oldDuration,
            ];
        }

        $speechPolicy = is_array($clip['speech_timing_policy'] ?? null)
            ? $clip['speech_timing_policy']
            : $this->auditor->speechTimingPolicy($clipText);
        $leadIn = max((float) ($speechPolicy['opening_visual_beat_seconds'] ?? 0.2), (float) $rows[0]['start']);
        $gap = (float) ($speechPolicy['inter_line_pause_seconds'] ?? 0.2);
        $tail = (float) ($speechPolicy['ending_pause_seconds'] ?? 0.3);
        // Never compress an existing speech window to make a repair fit.
        $preferredDurations = array_map(static fn (array $row): float => max($row['minimum'], $row['existing']), $rows);
        $existingPauses = 0.0;
        foreach ($rows as $index => &$row) {
            $row['gap_before'] = $index === 0 ? 0.0 : max($gap, $row['start'] - $rows[$index - 1]['end']);
            $existingPauses += $row['gap_before'];
        }
        unset($row);
        $minimumTotal = $leadIn + array_sum($preferredDurations) + $existingPauses + $tail;
        if ($minimumTotal > $targetDuration) {
            if (! $aiSelected) {
                return null;
            }
            $targetDuration = max($limits['minimum'], (int) ceil($minimumTotal));
        }
        if ($targetDuration < $limits['minimum'] || $targetDuration > $limits['maximum']) {
            return null;
        }

        $schedule = $this->speechSchedule($rows, $preferredDurations, $targetDuration, $gap, $leadIn, $tail);
        if ($schedule === null) {
            return null;
        }

        $replacements = [];
        $changed = $targetDuration !== $oldDuration;
        foreach ($rows as $index => $row) {
            [$start, $end] = $schedule[$index];
            $replacement = '- '.$this->formatSecond($start).'–'.$this->formatSecond($end).'s '.$row['suffix'];
            $changed = $changed
                || abs($row['start'] - $start) > 0.0001
                || abs($row['end'] - $end) > 0.0001;
            $replacements[] = ['line_index' => $row['line_index'], 'replacement' => $replacement];
        }

        return [
            'changed' => $changed,
            'rows' => $replacements,
            'duration' => $targetDuration !== $oldDuration ? $targetDuration : null,
            'old_duration' => $oldDuration,
        ];
    }

    /** @return array<int, array{0: float, 1: float}>|null */
    private function speechSchedule(array $rows, array $durations, int $duration, float $gap, float $leadIn, float $tail): ?array
    {
        $schedule = [];
        $cursor = null;
        foreach ($rows as $index => $row) {
            $start = $index === 0
                ? max($leadIn, (float) $row['start'])
                : max((float) $row['start'], (float) $cursor + max($gap, $row['gap_before'] ?? $gap));
            $end = $start + $durations[$index];
            $schedule[] = [$start, $end];
            $cursor = $end;
        }

        if ($schedule !== [] && end($schedule)[1] + $tail > $duration + 0.0001) {
            return null;
        }

        return $schedule;
    }

    private function speechSuffix(string $originalLine): string
    {
        $separator = strpos($originalLine, '|');
        if ($separator === false) {
            return '';
        }

        return trim(substr($originalLine, $separator));
    }

    private function formatSecond(float $value): string
    {
        return number_format(round($value, 1), 1, '.', '');
    }

    private function equivalentStateMaps(string $left, string $right): bool
    {
        $leftMap = $this->stateMap($left);
        $rightMap = $this->stateMap($right);
        if ($leftMap === null || $rightMap === null) {
            return false;
        }

        ksort($leftMap);
        ksort($rightMap);

        return $leftMap === $rightMap;
    }

    private function stateMap(string $state): ?array
    {
        $parts = explode(';', $state);
        $values = [];
        foreach ($parts as $part) {
            if (preg_match('/^\s*([^=;]+?)\s*=\s*(.*?)\s*$/u', $part, $match) !== 1 || trim($match[2]) === '') {
                return null;
            }
            $key = strtolower(preg_replace('/\s+/u', ' ', trim($match[1])) ?? trim($match[1]));
            $value = preg_replace('/\s+/u', ' ', trim($match[2])) ?? trim($match[2]);
            if ($key === '' || array_key_exists($key, $values)) {
                return null;
            }
            $values[$key] = $value;
        }

        return $values;
    }

    /** @param array<int, array<string, mixed>> $pairs @return array<int, array<string, mixed>> */
    private function prioritizeStatePairs(array $pairs): array
    {
        $finalPair = [];
        $otherPairs = [];
        foreach ($pairs as $pair) {
            if (($pair['is_final_handoff'] ?? false) === true) {
                $finalPair[] = $pair;
            } else {
                $otherPairs[] = $pair;
            }
        }

        return [...$finalPair, ...$otherPairs];
    }

    /** @return array<int, array<string, mixed>> */
    private function stateMismatchPairs(string $content, array $input, array $review): array
    {
        $clips = $this->auditor->canonicalProjectPlan($content, $input)->clips;
        $pairs = [];
        foreach ($clips as $index => $next) {
            if ($index === 0) {
                continue;
            }
            $physical = $this->hasIssue($review, 'continuity_state_mismatch', $next['number']);
            $presentation = $this->hasIssue($review, 'presentation_state_mismatch', $next['number']);
            if (! $physical && ! $presentation) {
                continue;
            }

            $previous = $clips[$index - 1];
            $pairs[] = [
                'pair' => count($pairs) + 1,
                'physical' => $physical ? [
                    'prior' => $previous['ending_continuity_state'],
                    'next' => $next['opening_continuity_state'],
                ] : null,
                'presentation' => $presentation ? [
                    'prior' => $previous['ending_presentation_state'],
                    'next' => $next['opening_presentation_state'],
                ] : null,
                'prior_clip' => $previous,
                'next_clip' => $next,
                'is_final_handoff' => $index === count($clips) - 1,
                'handoff' => $previous['outgoing_type'],
            ];
        }

        return $pairs;
    }

    private function buildStateRepairBrief(array $pairs, array $input): string
    {
        $intent = [];
        foreach ([
            'video_type' => 'Video type',
            'topic' => 'Topic',
            'learning_objective' => 'Learning objective',
            'creative_direction' => 'Creative direction',
            'character_type' => 'Saved character type',
            'character_details' => 'Saved character description',
            'character_idea' => 'Character/subject direction',
            'next_action' => 'Requested extension action',
            'visible_start' => 'Written extension starting state',
        ] as $key => $label) {
            if (isset($input[$key]) && trim((string) $input[$key]) !== '') {
                $intent[] = $label.': '.trim((string) $input[$key]);
            }
        }

        $sections = [];
        foreach ($pairs as $pair) {
            $sections[] = 'PAIR '.$pair['pair'].' — HANDOFF TYPE: '.($pair['handoff'] ?? 'unavailable');
            if ($pair['physical'] !== null) {
                $sections[] = 'CURRENT PRIOR ENDING CONTINUITY STATE: '.$pair['physical']['prior'];
                $sections[] = 'CURRENT NEXT OPENING CONTINUITY STATE: '.$pair['physical']['next'];
            }
            if ($pair['presentation'] !== null) {
                $sections[] = 'CURRENT PRIOR ENDING PRESENTATION STATE: '.$pair['presentation']['prior'];
                $sections[] = 'CURRENT NEXT OPENING PRESENTATION STATE: '.$pair['presentation']['next'];
            }
            $sections[] = "PRIOR CLIP CONTEXT:\n".$this->repairContext($pair['prior_clip']['text']);
            $sections[] = "NEXT CLIP CONTEXT:\n".$this->repairContext($pair['next_clip']['text']);
        }

        return "TARGETED CONTINUITY REPAIR DECISIONS\nUse only the saved intent and the displayed existing values. Do not invent or rewrite prompt text. For each PAIR, select the side whose current state is supported by the adjacent clip actions and intended scene. If neither side can be selected without changing the story, cast, process, or handoff, choose REVIEW. Reply with exactly one line for each requested decision, using only PRIOR_ENDING, NEXT_OPENING, or REVIEW.\n\nSAVED BRIEF\n".($intent === [] ? 'No additional project brief was saved.' : implode("\n", $intent))."\n\n".implode("\n\n", $sections)."\n\nREQUIRED RESPONSE FORMAT\n".$this->decisionFormat($pairs);
    }

    private function repairContext(string $clipText): string
    {
        $fields = [
            'TRANSITION TYPE',
            'OUTGOING HANDOFF TYPE',
            'VISIBLE CHARACTER CAST',
            'CHARACTER IDENTITY LOCK',
            'SCENE',
            'SETTING',
            'LOCATION',
            'ENVIRONMENT',
            'PRIMARY SUBJECT',
            'KEY OBJECTS',
            'PRIMARY IDEA',
            'VISIBLE ACTION',
            'ACTION',
            'VISIBLE CAUSE → ACTION → RESULT',
            'VISUAL REPRESENTATION',
            'OPENING CONTINUITY STATE',
            'ENDING CONTINUITY STATE',
            'EXACT OPENING FRAME',
            'EXACT ENDING STATE',
            'OPENING PRESENTATION STATE',
            'ENDING PRESENTATION STATE',
        ];
        $lines = [];
        foreach ($fields as $field) {
            $value = $this->auditor->fieldValue($clipText, $field);
            if ($value !== null) {
                $lines[] = $field.': '.$value;
            }
        }

        return $lines === [] ? 'No structured continuity context was available.' : implode("\n", $lines);
    }

    private function decisionFormat(array $pairs): string
    {
        $lines = [];
        foreach ($pairs as $pair) {
            if ($pair['physical'] !== null) {
                $lines[] = 'PAIR '.$pair['pair'].' PHYSICAL STATE: choose one of PRIOR_ENDING, NEXT_OPENING, or REVIEW';
            }
            if ($pair['presentation'] !== null) {
                $lines[] = 'PAIR '.$pair['pair'].' PRESENTATION STATE: choose one of PRIOR_ENDING, NEXT_OPENING, or REVIEW';
            }
        }

        return implode("\n", $lines);
    }

    private function parseStateDecisions(string $response, array $pairs): array
    {
        $decisions = [];
        foreach ($pairs as $pair) {
            foreach (['physical' => 'PHYSICAL STATE', 'presentation' => 'PRESENTATION STATE'] as $key => $label) {
                if ($pair[$key] === null) {
                    continue;
                }
                $pattern = '/^\s*PAIR\s+'.preg_quote((string) $pair['pair'], '/').'\s+'.preg_quote($label, '/').'\s*:\s*(PRIOR_ENDING|NEXT_OPENING|REVIEW)\s*$/im';
                $decisions[$pair['pair']][$key] = preg_match($pattern, $response, $match) === 1
                    ? strtoupper($match[1])
                    : 'REVIEW';
            }
        }

        return $decisions;
    }

    private function applyStateDecisions(string $content, array $input, array $pairs, array $decisions, array &$notes): string
    {
        $lineEnding = str_contains($content, "\r\n") ? "\r\n" : "\n";
        foreach ($pairs as $pair) {
            $choice = $decisions[$pair['pair']] ?? [];
            foreach ([
                'physical' => [
                    'ending' => 'ENDING CONTINUITY STATE',
                    'opening' => 'OPENING CONTINUITY STATE',
                    'ending_prose' => 'EXACT ENDING STATE',
                    'opening_prose' => 'EXACT OPENING FRAME',
                ],
                'presentation' => [
                    'ending' => 'ENDING PRESENTATION STATE',
                    'opening' => 'OPENING PRESENTATION STATE',
                    'ending_prose' => null,
                    'opening_prose' => null,
                ],
            ] as $kind => $fields) {
                if ($pair[$kind] === null) {
                    continue;
                }
                $decision = $choice[$kind] ?? 'REVIEW';
                if ($decision === 'REVIEW') {
                    $notes[] = 'Pair '.$pair['pair'].' '.$kind.' mismatch was left for manual review because no safe source was selected.';
                    continue;
                }

                $value = $decision === 'PRIOR_ENDING' ? $pair[$kind]['prior'] : $pair[$kind]['next'];
                if (! is_string($value) || trim($value) === '') {
                    $notes[] = 'Pair '.$pair['pair'].' '.$kind.' mismatch was left for manual review because the selected source was empty.';
                    continue;
                }

                $clips = $this->auditor->canonicalProjectPlan($content, $input)->clips;
                $previous = $this->findClip($clips, $pair['prior_clip']['number']);
                $next = $this->findClip($clips, $pair['next_clip']['number']);
                if ($previous === null || $next === null) {
                    continue;
                }

                $sourceClip = $decision === 'PRIOR_ENDING' ? $previous : $next;
                $targetClip = $decision === 'PRIOR_ENDING' ? $next : $previous;
                $sourceProseField = $decision === 'PRIOR_ENDING' ? $fields['ending_prose'] : $fields['opening_prose'];
                $targetProseField = $decision === 'PRIOR_ENDING' ? $fields['opening_prose'] : $fields['ending_prose'];
                $sourceProse = $sourceProseField === null ? null : $this->auditor->fieldValue($sourceClip['text'], $sourceProseField);
                $targetProse = $targetProseField === null ? null : $this->auditor->fieldValue($targetClip['text'], $targetProseField);
                $sourceProseRelation = $this->auditor->stateProseRelation($value, $sourceProse);
                $needsProseSync = $targetProse !== null
                    && $this->auditor->stateProseRelation($value, $targetProse) === 'mismatch';
                if ($kind === 'physical' && $sourceProse !== null
                    && ! in_array($sourceProseRelation, ['exact', 'compatible'], true)) {
                    $notes[] = 'Pair '.$pair['pair'].' continuity was left for manual review because the selected structured state is not validated by its exact-state prose.';
                    continue;
                }
                if ($needsProseSync && ($sourceProse === null
                    || ! in_array($sourceProseRelation, ['exact', 'compatible'], true))) {
                    $notes[] = 'Pair '.$pair['pair'].' continuity was left for manual review because its written state cannot be safely synchronized from the selected source.';
                    continue;
                }

                $lines = preg_split('/\R/u', str_replace("\r", '', $content)) ?: [];
                $leftChanged = $this->replaceField($lines, $previous, $fields['ending'], $value);
                $rightChanged = $this->replaceField($lines, $next, $fields['opening'], $value);
                $proseChanged = false;
                if ($needsProseSync && $targetProseField !== null && $sourceProse !== null) {
                    $proseChanged = $this->replaceField($lines, $targetClip, $targetProseField, $sourceProse);
                }
                if ($leftChanged || $rightChanged || $proseChanged) {
                    $content = implode($lineEnding, $lines);
                    $notes[] = 'Pair '.$pair['pair'].' '.$kind.' metadata was reconciled using an existing state; dialogue, visible-action text, and transition labels were preserved.';
                    if ($proseChanged) {
                        $notes[] = 'The conflicting exact opening/ending state description was aligned to the selected existing prose without rewriting action text.';
                    }
                }
            }
        }

        return $content;
    }

    private function replaceField(array &$lines, array $clip, string $field, string $value): bool
    {
        for ($lineIndex = $clip['start_line']; $lineIndex < $clip['end_line']; $lineIndex++) {
            if (! isset($lines[$lineIndex])) {
                continue;
            }
            $plain = str_replace(['**', '__', '`'], '', $lines[$lineIndex]);
            if (preg_match('/^\s*(?:[-*+>]\s*)?'.preg_quote($field, '/').'\s*:/iu', $plain) !== 1) {
                continue;
            }
            preg_match('/^\s*(?:[-*+>]\s*)?/u', $lines[$lineIndex], $prefix);
            $lines[$lineIndex] = ($prefix[0] ?? '').$field.': '.$value;

            return true;
        }

        return false;
    }

    private function hasIssue(array $review, string $code, ?int $clip): bool
    {
        foreach ($review['issues'] ?? [] as $issue) {
            if (($issue['code'] ?? null) === $code && ($issue['clip'] ?? null) === $clip) {
                return true;
            }
        }

        return false;
    }

    private function findClip(array $clips, int $number): ?array
    {
        foreach ($clips as $clip) {
            if ($clip['number'] === $number) {
                return $clip;
            }
        }

        return null;
    }

    private function addedIssues(array $before, array $after): array
    {
        $known = [];
        foreach ($before['issues'] ?? [] as $issue) {
            $known[$this->issueKey($issue)] = true;
        }

        return array_values(array_filter(
            $after['issues'] ?? [],
            fn (array $issue): bool => ! isset($known[$this->issueKey($issue)]),
        ));
    }

    private function removedIssues(array $before, array $after): array
    {
        $remaining = [];
        foreach ($after['issues'] ?? [] as $issue) {
            $remaining[$this->issueKey($issue)] = true;
        }

        return array_values(array_filter(
            $before['issues'] ?? [],
            fn (array $issue): bool => ! isset($remaining[$this->issueKey($issue)]),
        ));
    }

    private function issueKey(array $issue): string
    {
        $code = (string) ($issue['code'] ?? 'unknown');
        $key = $code.':'.(string) ($issue['clip'] ?? 'all');
        $message = (string) ($issue['message'] ?? '');

        if (in_array($code, ['speech_map_incomplete', 'speech_time_range_invalid', 'speech_exceeds_clip', 'speech_overrun', 'speech_inter_line_pause_missing'], true)
            && preg_match('/\bspeech\s+row\s+(\d+)/i', $message, $match) === 1) {
            return $key.':row:'.$match[1];
        }

        if ($code === 'required_field_missing' && preg_match('/required `([^`]+)`/i', $message, $match) === 1) {
            return $key.':field:'.strtolower($match[1]);
        }

        return $key;
    }

    private function result(
        string $content,
        array $review,
        string $outcome,
        bool $applied,
        array $fixed,
        array $unresolved,
        array $newIssues,
        array $notes,
        bool $attempted,
        array $rejectedCandidateIssues = [],
        bool $retryable = false,
    ): array {
        return [
            'content' => $content,
            'quality_review' => $review,
            'repair_report' => [
                'outcome' => $outcome,
                'attempted' => $attempted,
                'retryable' => $retryable,
                'applied' => $applied,
                'fixed' => array_values($fixed),
                'unresolved' => array_values($unresolved),
                'new_issues' => array_values($newIssues),
                'rejected_candidate_issues' => array_values($rejectedCandidateIssues),
                'informational' => array_values(array_unique($notes)),
                'notes' => array_values(array_unique($notes)),
            ],
        ];
    }
}
