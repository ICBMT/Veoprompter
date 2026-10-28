<?php

namespace App\Services;

use App\Support\ProjectSettings;

/**
 * Deterministic checks for machine-readable prompt structure and time budgets.
 * It deliberately does not claim to verify facts, infer physical feasibility,
 * or validate video that has not been rendered.
 */
class PromptOutputAuditor
{
    private const OPPOSING_STATE_GROUPS = [
        [['open', 'opened', 'unsealed'], ['closed', 'shut', 'sealed']],
        [['full', 'filled'], ['empty', 'vacant']],
        [['left'], ['right']],
        [['inside', 'indoors', 'within'], ['outside', 'outdoors']],
        [['moving', 'in motion', 'rolling', 'walking', 'running'], ['stationary', 'still', 'stopped', 'motionless', 'at rest']],
        [['wet', 'damp', 'moist'], ['dry']],
        [['attached', 'fastened', 'connected'], ['detached', 'disconnected', 'separated']],
        [['present', 'visible'], ['absent', 'missing', 'invisible']],
        [['standing', 'upright'], ['sitting', 'seated', 'lying', 'prone']],
        [['lit', 'illuminated', 'bright'], ['dark', 'unlit']],
        [['on', 'turned on', 'powered on'], ['off', 'turned off', 'powered off']],
        [['intact', 'whole', 'unbroken'], ['broken', 'cracked', 'damaged']],
        [['hot', 'warm'], ['cold', 'cool']],
    ];

    private const STATE_STOP_WORDS = [
        'a', 'an', 'and', 'are', 'as', 'at', 'by', 'for', 'from', 'in', 'is', 'of', 'on', 'the', 'to', 'was', 'were', 'with',
    ];

    /**
     * @return array{
     *     status: string,
     *     corrected: bool,
     *     repair_attempted: bool,
     *     checks: array<int, array{id: string, label: string, status: string, detail: string}>,
     *     issues: array<int, array{code: string, category: string, clip: int|null, message: string, repairable: bool}>,
     *     handoffs: array<int, array{from_clip: int, to_clip: int, type: string|null, continuity_relation: string, written_opening_relation: string, written_handoff_relation: string, overall_relation: string, exact_copy: bool}>,
     *     limitations: array<int, string>
     * }
     */
    public function audit(string $content, array $input): array
    {
        $isExtension = ($input['mode'] ?? 'story') === 'extend';
        $plan = $this->canonicalProjectPlan($content, $input);
        $clips = $plan->clips;

        if ($clips === []) {
            $issues = [[
                'code' => 'prompt_structure_missing',
                'category' => 'structure',
                'clip' => null,
                'message' => $isExtension
                    ? 'No clearly labeled copy-ready extension prompt was found, so duration and speech timing could not be checked.'
                    : 'No consecutively numbered COPY CLIP N PROMPT blocks were found, so clip-level checks could not be run.',
                'repairable' => false,
            ]];

            return $this->buildReview($issues, $input, false, $isExtension);
        }

        $issues = [];
        $details = [];
        $handoffs = [];
        $spokenAudioEnabled = $this->spokenAudioEnabled($input);
        $durationLimits = $plan->durationLimits;
        if ($durationLimits['conflict']) {
            $this->addIssue(
                $issues,
                'duration_constraint_conflict',
                'duration',
                null,
                $durationLimits['conflict_message'],
                false,
            );
        }

        if (! $isExtension) {
            $expectedClipCount = $input['clip_count'] ?? ProjectSettings::AI_CHOICE;
            if (! ProjectSettings::isAiClipCountChoice($expectedClipCount) && is_numeric($expectedClipCount)) {
                $expectedClipCount = (int) $expectedClipCount;
                if (count($clips) !== $expectedClipCount) {
                    $this->addIssue(
                        $issues,
                        'clip_count_mismatch',
                        'structure',
                        null,
                        'The output contains '.count($clips).' clip prompts, but the selected manual count is '.$expectedClipCount.'.',
                    );
                }
            }

            foreach ($clips as $index => $clip) {
                $expectedNumber = $index + 1;
                if ($clip['number'] !== $expectedNumber) {
                    $this->addIssue(
                        $issues,
                        'clip_numbering',
                        'structure',
                        $clip['number'],
                        'Clip prompts must be numbered consecutively from 1; this block is labeled Clip '.$clip['number'].' where Clip '.$expectedNumber.' is expected.',
                    );
                }
            }
        }

        foreach ($clips as $clip) {
            $details[] = $this->auditClip($clip, $input, $issues, $spokenAudioEnabled, $isExtension, $durationLimits);
        }

        if (! $isExtension) {
            foreach ($clips as $index => $clip) {
                $incoming = $details[$index]['incoming_type'];
                $outgoing = $details[$index]['outgoing_type'];
                $clipNumber = $clip['number'];

                if ($index === 0 && $incoming !== 'INITIAL') {
                    $this->addIssue(
                        $issues,
                        'first_clip_transition',
                        'handoff',
                        $clipNumber,
                        'Clip 1 must use TRANSITION TYPE: INITIAL CLIP — NO PREVIOUS HANDOFF.',
                    );
                } elseif ($index > 0 && ! in_array($incoming, ['A', 'B', 'C'], true)) {
                    $this->addIssue(
                        $issues,
                        'incoming_handoff_missing',
                        'handoff',
                        $clipNumber,
                        'Clip '.$clipNumber.' must identify its incoming Type A, B, or C transition.',
                    );
                }

                if ($index < count($clips) - 1) {
                    $next = $details[$index + 1];
                    $nextClipNumber = $clips[$index + 1]['number'];

                    if (! in_array($outgoing, ['A', 'B', 'C'], true)) {
                        $this->addIssue(
                            $issues,
                            'outgoing_handoff_missing',
                            'handoff',
                            $clipNumber,
                            'Clip '.$clipNumber.' must identify its outgoing Type A, B, or C handoff to the next clip.',
                        );
                    }
                    if (in_array($outgoing, ['A', 'B', 'C'], true)
                        && in_array($next['incoming_type'], ['A', 'B', 'C'], true)
                        && $outgoing !== $next['incoming_type']) {
                        $this->addIssue(
                            $issues,
                            'handoff_type_mismatch',
                            'handoff',
                            $clipNumber,
                            'Clip '.$clipNumber.' ends with Type '.$outgoing.', but Clip '.$nextClipNumber.' begins with Type '.$next['incoming_type'].'.',
                        );
                    }

                    $endingState = $details[$index]['ending_state'];
                    $openingState = $next['opening_state'];
                    $stateRelation = $this->compareStates($endingState, $openingState);
                    $transitionAction = (string) ($clips[$index + 1]['visible_cause_action_result'] ?? '');
                    $nextEndingState = $details[$index + 1]['ending_state'];
                    $typeBPreviousBridgeDocumented = $stateRelation === 'exact';
                    $typeBInternalBridgeDocumented = $this->transitionActionConnectsStates($openingState, $nextEndingState, $transitionAction, false);
                    $typeBBridgeDocumented = $outgoing === 'B'
                        && $typeBPreviousBridgeDocumented
                        && $typeBInternalBridgeDocumented;
                    $typeCExternalTransitionDocumented = $stateRelation === 'exact';
                    $typeCInternalTransitionDocumented = $this->transitionActionConnectsStates($openingState, $nextEndingState, $transitionAction, true);
                    $typeCTransitionDocumented = $outgoing === 'C'
                        && $typeCExternalTransitionDocumented
                        && $typeCInternalTransitionDocumented;

                    if ($outgoing === 'B' && ! $typeBBridgeDocumented) {
                        $this->addIssue(
                            $issues,
                            'type_b_transition_action_unverified',
                            'continuity',
                            $nextClipNumber,
                            'Clip '.$nextClipNumber.' Type B must open on the exact prior ending state and show a visible action bridge from its opening to its ending state; the available cause/action/result evidence does not establish both. Text QA cannot certify physical realism.',
                            false,
                        );
                        if ($stateRelation !== 'exact') {
                            $this->addIssue(
                                $issues,
                                'continuity_state_mismatch',
                                'continuity',
                                $nextClipNumber,
                                'Clip '.$nextClipNumber.' Type B opening state is not a verified continuation of Clip '.$clipNumber.'; no safe state bridge can be assumed.',
                            );
                        }
                    } elseif ($outgoing === 'C' && ! $typeCTransitionDocumented) {
                        $this->addIssue(
                            $issues,
                            'type_c_transition_undocumented',
                            'continuity',
                            $nextClipNumber,
                            'Clip '.$nextClipNumber.' uses Type C, but its opening does not match the prior ending and/or its visible cause/action/result does not explicitly establish the before-state, temporal/narrative transition, and after-state.',
                            false,
                        );
                        if ($stateRelation !== 'exact') {
                            $this->addIssue(
                                $issues,
                                'continuity_state_mismatch',
                                'continuity',
                                $nextClipNumber,
                                'Clip '.$nextClipNumber.' Type C changes the adjacent opening state without a documented narrative/temporal transition; this handoff remains unverified.',
                            );
                        }
                    } elseif ($this->normalizeState($endingState) !== ''
                        && $this->normalizeState($openingState) !== ''
                        && $stateRelation !== 'exact'
                        && ! $typeBBridgeDocumented
                        && ! $typeCTransitionDocumented
                        && ! ($outgoing === 'B' && $stateRelation === 'compatible')) {
                        $this->addIssue(
                            $issues,
                            'continuity_state_mismatch',
                            'continuity',
                            $nextClipNumber,
                            'Clip '.$nextClipNumber.' opening scene state does not satisfy the preceding handoff contract. Review identity, objects/materials, process stage, environment, position, condition, and motion; show any permitted Type B/C change inside the clip.',
                        );
                    }

                    $writtenOpeningRelation = $this->stateProseRelation($endingState, $next['exact_opening_frame']);
                    if ($writtenOpeningRelation === 'mismatch') {
                        $this->addIssue(
                            $issues,
                            'handoff_opening_prose_mismatch',
                            'continuity',
                            $nextClipNumber,
                            'Clip '.$nextClipNumber.' EXACT OPENING FRAME prose contradicts the preceding clip’s structured ending state.',
                        );
                    } elseif ($endingState !== null && $next['exact_opening_frame'] !== null && $writtenOpeningRelation === 'unverified') {
                        $this->addIssue(
                            $issues,
                            'handoff_opening_prose_unverified',
                            'continuity',
                            $nextClipNumber,
                            'Clip '.$nextClipNumber.' EXACT OPENING FRAME prose does not provide enough shared evidence to validate compatibility with Clip '.$clipNumber.' ending state.',
                            false,
                        );
                    }

                    $writtenHandoffRelation = $this->compareWrittenStates(
                        $details[$index]['exact_ending_state'],
                        $next['exact_opening_frame'],
                    );
                    if ($writtenHandoffRelation === 'mismatch') {
                        $this->addIssue(
                            $issues,
                            'handoff_prose_state_mismatch',
                            'continuity',
                            $nextClipNumber,
                            'Clip '.$nextClipNumber.' written opening state contradicts the previous clip’s EXACT ENDING STATE.',
                        );
                    } elseif ($details[$index]['exact_ending_state'] !== null
                        && $next['exact_opening_frame'] !== null
                        && $writtenHandoffRelation === 'unverified') {
                        $this->addIssue(
                            $issues,
                            'handoff_prose_state_unverified',
                            'continuity',
                            $nextClipNumber,
                            'The written ending/opening prose for Clips '.$clipNumber.' and '.$nextClipNumber.' does not provide enough shared state evidence to validate the handoff.',
                            false,
                        );
                    }

                    $overallRelation = $typeCTransitionDocumented
                        ? (in_array('mismatch', [$writtenOpeningRelation, $writtenHandoffRelation], true) ? 'mismatch' : 'transition_documented')
                        : ($outgoing === 'B' && ! $typeBBridgeDocumented ? 'unverified' : $stateRelation);
                    if (! $typeCTransitionDocumented) {
                        if ($stateRelation === 'mismatch' || $writtenOpeningRelation === 'mismatch' || $writtenHandoffRelation === 'mismatch') {
                            $overallRelation = 'mismatch';
                        } elseif ($stateRelation === 'compatible'
                            || $writtenOpeningRelation === 'compatible'
                            || $writtenHandoffRelation === 'compatible') {
                            $overallRelation = 'compatible';
                        } elseif ($stateRelation === 'exact'
                            && (($next['exact_opening_frame'] !== null && $writtenOpeningRelation === 'unverified')
                                || ($details[$index]['exact_ending_state'] !== null
                                    && $next['exact_opening_frame'] !== null
                                    && $writtenHandoffRelation === 'unverified'))) {
                            $overallRelation = 'unverified';
                        }
                    }
                    if ($outgoing === 'B' && ! $typeBBridgeDocumented && $overallRelation !== 'mismatch') {
                        $overallRelation = 'unverified';
                    }
                    if ($outgoing === 'C' && ! $typeCTransitionDocumented && $overallRelation !== 'mismatch') {
                        $overallRelation = 'unverified';
                    }

                    if (in_array($outgoing, ['A', 'B'], true)) {
                        $endingPresentation = $this->normalizeState($details[$index]['ending_presentation']);
                        $openingPresentation = $this->normalizeState($next['opening_presentation']);
                        if ($endingPresentation !== '' && $openingPresentation !== '' && $endingPresentation !== $openingPresentation) {
                            $this->addIssue(
                                $issues,
                                'presentation_state_mismatch',
                                'continuity',
                                $nextClipNumber,
                                'Type A/Type B openings must match the previous ending presentation state; Type B camera, lighting, or audible changes belong after that matched opening. Clip '.$nextClipNumber.' does not match Clip '.$clipNumber.'.',
                            );
                        }
                    }

                    $handoffs[] = [
                        'from_clip' => $clipNumber,
                        'to_clip' => $nextClipNumber,
                        'type' => $outgoing,
                        'continuity_relation' => $stateRelation,
                        'written_opening_relation' => $writtenOpeningRelation,
                        'written_handoff_relation' => $writtenHandoffRelation,
                        'overall_relation' => $overallRelation,
                        'exact_copy' => $overallRelation === 'exact',
                    ];
                } elseif ($outgoing !== 'FINAL') {
                    $this->addIssue(
                        $issues,
                        'final_handoff_marker_missing',
                        'handoff',
                        $clipNumber,
                        'The final clip must end with OUTGOING HANDOFF TYPE: FINAL — NO NEXT HANDOFF.',
                    );
                }
            }

            $this->auditProjectOverview($content, $clips, $details, $issues, $plan);
            $this->auditClipPlanDurations($content, $clips, $details, $issues, $plan);
            $this->auditProjectRuntimeConstraints($plan, $issues);
            $this->auditFinalContinuityClaims($content, $handoffs, $clips, $input, $issues, $plan);
        }

        return $this->buildReview($issues, $input, true, $isExtension, $handoffs);
    }

    /**
     * Expose the auditor's existing block parser to the repair service so edits
     * and the final QA pass share the same definition of copy-ready clips.
     *
     * @return array<int, array{number: int, text: string, start_line: int, end_line: int}>
     */
    public function extractClips(string $content, array $input): array
    {
        return ($input['mode'] ?? 'story') === 'extend'
            ? $this->extractExtensionPrompt($content)
            : $this->extractStoryClips($content);
    }

    /**
     * Parse the single canonical post-generation plan shared by QA and Auto-Fix.
     * Structured copy-ready clip blocks are authoritative; overview and clip-plan
     * values are captured as dependent references for validation/synchronization.
     */
    public function canonicalProjectPlan(string $content, array $input): PromptProjectPlan
    {
        $clips = $this->extractClips($content, $input);
        foreach ($clips as &$clip) {
            $text = (string) ($clip['text'] ?? '');
            $clip['duration_seconds'] = $this->durationSeconds($text);
            $clip['incoming_type'] = ($input['mode'] ?? 'story') === 'extend' ? null : $this->incomingType($text);
            $clip['outgoing_type'] = ($input['mode'] ?? 'story') === 'extend' ? null : $this->outgoingType($text);
            $clip['visible_character_cast'] = $this->fieldValue($text, 'VISIBLE CHARACTER CAST');
            $clip['character_identity_lock'] = $this->fieldValue($text, 'CHARACTER IDENTITY LOCK');
            $clip['opening_continuity_state'] = $this->fieldValue($text, 'OPENING CONTINUITY STATE');
            $clip['ending_continuity_state'] = $this->fieldValue($text, 'ENDING CONTINUITY STATE');
            $clip['opening_presentation_state'] = $this->fieldValue($text, 'OPENING PRESENTATION STATE');
            $clip['ending_presentation_state'] = $this->fieldValue($text, 'ENDING PRESENTATION STATE');
            $clip['exact_opening_frame'] = $this->fieldValue($text, 'EXACT OPENING FRAME');
            $clip['exact_ending_state'] = $this->fieldValue($text, 'EXACT ENDING STATE');
            $clip['primary_idea'] = $this->fieldValue($text, 'PRIMARY IDEA');
            $clip['visual_representation'] = $this->fieldValue($text, 'VISUAL REPRESENTATION');
            $clip['visible_cause_action_result'] = $this->fieldValue($text, 'VISIBLE CAUSE → ACTION → RESULT');
            $clip['speech_timing_policy'] = $this->speechTimingPolicy($text);
            $clip['speech_rows'] = $this->speechRows($text);
            foreach ($clip['speech_rows'] as &$speechRow) {
                $speechRow['minimum_seconds'] = $this->minimumSpeechSeconds($speechRow['line'], $input);
            }
            unset($speechRow);
            $clip['speech_budget'] = $this->speechBudget($clip['speech_rows'], $clip['duration_seconds'], $clip['speech_timing_policy'], $input);
            $clip['minimum_speech_duration_seconds'] = array_sum(array_column($clip['speech_rows'], 'minimum_seconds'));
            $clip['minimum_duration_with_visual_beats_seconds'] = $clip['speech_rows'] === []
                ? 0.0
                : $clip['minimum_speech_duration_seconds']
                    + ($clip['speech_timing_policy']['opening_visual_beat_seconds'] ?? 0)
                    + ($clip['speech_timing_policy']['ending_pause_seconds'] ?? 0)
                    + (max(0, count($clip['speech_rows']) - 1) * ($clip['speech_timing_policy']['inter_line_pause_seconds'] ?? 0));
        }
        unset($clip);

        return new PromptProjectPlan(
            clips: $clips,
            durationLimits: $this->durationLimits($content, $input),
            projectRuntimeLimits: $this->projectRuntimeLimits($content, $input),
            overviewDurations: $this->overviewDurationReferences($content),
            overviewRuntimes: $this->overviewRuntimeReferences($content),
            clipPlanDurations: $this->clipPlanDurationReferences($content),
            savedSettings: $input,
        );
    }

    /** @return array<int, array{number: int, text: string, start_line: int, end_line: int}> */
    private function extractStoryClips(string $content): array
    {
        $lines = preg_split('/\R/u', str_replace("\r", '', $content)) ?: [];
        $headings = [];

        foreach ($lines as $index => $line) {
            $normalized = $this->normalizeHeading($line);
            if (preg_match('/^(?:COPY\s+)?(?:TRANSITION\s+)?CLIP\s+(\d+)(?:\s+TRANSITION)?\s+(?:COPY[- ]AND[- ]PASTE\s+)?(?:VIDEO\s+)?PROMPT\b\s*(?:>|:|—|–|-)?\s*(.*)$/iu', $normalized, $match) !== 1) {
                continue;
            }

            $headings[] = [
                'number' => (int) $match[1],
                'line' => $index,
                'inline' => trim($match[2] ?? ''),
            ];
        }

        $blocks = [];
        foreach ($headings as $index => $heading) {
            $end = count($lines);
            for ($cursor = $heading['line'] + 1; $cursor < count($lines); $cursor++) {
                $nextIsClip = isset($headings[$index + 1]) && $cursor === $headings[$index + 1]['line'];
                if ($nextIsClip || preg_match('/^\s*(?:#{1,6}\s*)?(?:\*\*)?SECTION\s+9(?:\+|\b)/iu', $lines[$cursor]) === 1) {
                    $end = $cursor;
                    break;
                }
            }

            $body = [];
            if ($heading['inline'] !== '') {
                $body[] = $heading['inline'];
            }
            $body = [...$body, ...array_slice($lines, $heading['line'] + 1, $end - $heading['line'] - 1)];
            $blocks[] = [
                'number' => $heading['number'],
                'text' => implode("\n", $body),
                'start_line' => $heading['line'] + ($heading['inline'] !== '' ? 0 : 1),
                'end_line' => $end,
            ];
        }

        return $blocks;
    }

    /** @return array<int, array{number: int, text: string, start_line: int, end_line: int}> */
    private function extractExtensionPrompt(string $content): array
    {
        $lines = preg_split('/\R/u', str_replace("\r", '', $content)) ?: [];
        foreach ($lines as $index => $line) {
            $normalized = $this->normalizeHeading($line);
            if (preg_match('/^COPY\s+EXTENSION\s+VIDEO\s+PROMPT\b\s*(?:>|:|—|–|-)?\s*(.*)$/iu', $normalized, $match) !== 1) {
                continue;
            }

            $end = count($lines);
            for ($cursor = $index + 1; $cursor < count($lines); $cursor++) {
                if (preg_match('/^\s*(?:#{1,6}\s*)?(?:\*\*)?SECTION\s+\d+(?:\+|\b)/iu', $lines[$cursor]) === 1
                    || preg_match('/^\s*(?:CONTINUITY\s+(?:CHECK|AUDIT)|COPY\s+CHECKLIST)\b/iu', $lines[$cursor]) === 1) {
                    $end = $cursor;
                    break;
                }
            }

            $body = [];
            if (trim($match[1] ?? '') !== '') {
                $body[] = trim($match[1]);
            }
            $body = [...$body, ...array_slice($lines, $index + 1, $end - $index - 1)];

            return [[
                'number' => 1,
                'text' => implode("\n", $body),
                'start_line' => $index + (trim($match[1] ?? '') !== '' ? 0 : 1),
                'end_line' => $end,
            ]];
        }

        return [];
    }

    /** @param array<int, array<string, mixed>> $issues */
    private function auditClip(array $clip, array $input, array &$issues, bool $spokenAudioEnabled, bool $isExtension, array $durationLimits): array
    {
        $text = $clip['text'];
        $number = $clip['number'];
        $duration = is_numeric($clip['duration_seconds'] ?? null)
            ? (float) $clip['duration_seconds']
            : $this->durationSeconds($text);

        if ($duration === null) {
            $this->addIssue(
                $issues,
                'clip_duration_missing',
                'duration',
                $number,
                'Clip '.$number.' is missing the exact `CLIP DURATION: N seconds` field, so its timing cannot be verified.',
            );
        } else {
            if ($duration <= 0 || floor($duration) !== $duration) {
                $this->addIssue(
                    $issues,
                    'clip_duration_invalid',
                    'duration',
                    $number,
                    'Clip '.$number.' duration must be a positive whole number of seconds.',
                );
            }

            $durationChoice = ($input['mode'] ?? 'story') === 'extend'
                ? ($input['extend_duration'] ?? $input['clip_duration'] ?? ProjectSettings::AI_CHOICE)
                : ($input['clip_duration'] ?? ProjectSettings::AI_CHOICE);
            if (ProjectSettings::isAiChoice($durationChoice)) {
                if ($duration > ProjectSettings::AI_DURATION_MAX) {
                    $this->addIssue(
                        $issues,
                        'ai_duration_over_limit',
                        'duration',
                        $number,
                        'AI-selected Clip '.$number.' is '.$duration.' seconds; AI-selected durations must not exceed '.ProjectSettings::AI_DURATION_MAX.' seconds.',
                    );
                }
            } elseif (is_numeric($durationChoice) && (float) $duration !== (float) $durationChoice) {
                $this->addIssue(
                    $issues,
                    'manual_duration_mismatch',
                    'duration',
                    $number,
                    'Clip '.$number.' is '.$duration.' seconds, but the selected fixed duration is '.(int) $durationChoice.' seconds.',
                );
            }
        }

        if ($duration !== null && ! $durationLimits['conflict']
            && (($durationLimits['declared_minimum'] !== null && $duration < $durationLimits['declared_minimum'])
                || ($durationLimits['declared_maximum'] !== null && $duration > $durationLimits['declared_maximum']))) {
            $this->addIssue(
                $issues,
                'declared_duration_constraint_violation',
                'duration',
                $number,
                'Clip '.$number.' is '.$this->formatSeconds($duration).' seconds, outside the project-declared duration range '.$durationLimits['declared_label'].'. The range must not be changed silently.',
                false,
            );
        }

        $requiredFields = [
            'VISIBLE CHARACTER CAST' => 'structure',
            'CHARACTER IDENTITY LOCK' => 'structure',
            'PRIMARY IDEA' => 'visual',
            'VISUAL REPRESENTATION' => 'visual',
            'VISIBLE CAUSE → ACTION → RESULT' => 'visual',
            'OPENING CONTINUITY STATE' => 'continuity',
            'ENDING CONTINUITY STATE' => 'continuity',
            'OPENING PRESENTATION STATE' => 'continuity',
            'ENDING PRESENTATION STATE' => 'continuity',
            'SPEECH/VISUAL MAP' => 'narration',
        ];
        $canonicalFields = [
            'VISIBLE CHARACTER CAST' => 'visible_character_cast',
            'CHARACTER IDENTITY LOCK' => 'character_identity_lock',
            'PRIMARY IDEA' => 'primary_idea',
            'VISUAL REPRESENTATION' => 'visual_representation',
            'VISIBLE CAUSE → ACTION → RESULT' => 'visible_cause_action_result',
            'OPENING CONTINUITY STATE' => 'opening_continuity_state',
            'ENDING CONTINUITY STATE' => 'ending_continuity_state',
            'OPENING PRESENTATION STATE' => 'opening_presentation_state',
            'ENDING PRESENTATION STATE' => 'ending_presentation_state',
        ];
        $values = [];
        foreach ($requiredFields as $field => $category) {
            $planKey = $canonicalFields[$field] ?? null;
            $values[$field] = $planKey !== null && array_key_exists($planKey, $clip)
                ? $clip[$planKey]
                : $this->fieldValue($text, $field);
            $missingValue = in_array($field, ['SPEECH/VISUAL MAP', 'CHARACTER IDENTITY LOCK'], true)
                ? $values[$field] === null
                : ($values[$field] === null || trim($values[$field]) === '');
            if ($missingValue) {
                $this->addIssue(
                    $issues,
                    'required_field_missing',
                    $category,
                    $number,
                    'Clip '.$number.' is missing its required `'.$field.':` metadata.',
                );
            }
        }

        $exactOpeningFrame = array_key_exists('exact_opening_frame', $clip)
            ? $clip['exact_opening_frame']
            : $this->fieldValue($text, 'EXACT OPENING FRAME');
        $exactEndingState = array_key_exists('exact_ending_state', $clip)
            ? $clip['exact_ending_state']
            : $this->fieldValue($text, 'EXACT ENDING STATE');
        $openingProseRelation = $this->stateProseRelation($values['OPENING CONTINUITY STATE'], $exactOpeningFrame);
        $endingProseRelation = $this->stateProseRelation($values['ENDING CONTINUITY STATE'], $exactEndingState);
        if ($openingProseRelation === 'mismatch') {
            $this->addIssue(
                $issues,
                'continuity_prose_state_mismatch',
                'continuity',
                $number,
                'Clip '.$number.' EXACT OPENING FRAME prose contradicts its structured OPENING CONTINUITY STATE.',
            );
        }
        if ($endingProseRelation === 'mismatch') {
            $this->addIssue(
                $issues,
                'continuity_prose_state_mismatch',
                'continuity',
                $number,
                'Clip '.$number.' EXACT ENDING STATE prose contradicts its structured ENDING CONTINUITY STATE.',
            );
        }
        if ($exactOpeningFrame !== null && $openingProseRelation === 'unverified') {
            $this->addIssue(
                $issues,
                'continuity_prose_state_unverified',
                'continuity',
                $number,
                'Clip '.$number.' EXACT OPENING FRAME prose does not share enough explicit state evidence to validate against its structured opening state.',
                false,
            );
        }
        if ($exactEndingState !== null && $endingProseRelation === 'unverified') {
            $this->addIssue(
                $issues,
                'continuity_prose_state_unverified',
                'continuity',
                $number,
                'Clip '.$number.' EXACT ENDING STATE prose does not share enough explicit state evidence to validate against its structured ending state.',
                false,
            );
        }

        if ($values['VISUAL REPRESENTATION'] !== null
            && ! preg_match('/\b(literal|cutaway|diagram|overlay|metaphor|illustrative)\b/i', $values['VISUAL REPRESENTATION'])) {
            $this->addIssue(
                $issues,
                'visual_representation_unclear',
                'visual',
                $number,
                'Clip '.$number.' must identify whether its image is literal or an explanatory/illustrative representation.',
            );
        }

        $incomingType = $isExtension ? null : ($clip['incoming_type'] ?? $this->incomingType($text));
        $outgoingType = $isExtension ? null : ($clip['outgoing_type'] ?? $this->outgoingType($text));
        $mapMarker = $values['SPEECH/VISUAL MAP'] ?? null;
        $speechRows = is_array($clip['speech_rows'] ?? null) ? $clip['speech_rows'] : $this->speechRows($text);
        preg_match_all('/\\bLINE\\s*:/iu', $text, $rawSpeechLines);
        if (count($rawSpeechLines[0] ?? []) !== count($speechRows)) {
            $this->addIssue(
                $issues,
                'speech_map_unparseable',
                'narration',
                $number,
                'Clip '.$number.' has '.count($rawSpeechLines[0] ?? []).' LINE marker(s) but only '.count($speechRows).' machine-readable timed speech row(s); timing remains unverified until every line is parsed.',
                false,
            );
        }
        if ($speechRows !== []) {
            $speechTimingPolicy = is_array($clip['speech_timing_policy'] ?? null)
                ? $clip['speech_timing_policy']
                : $this->speechTimingPolicy($text);
            $openingBeat = $speechTimingPolicy['opening_visual_beat_seconds'];
            $requiredInterLinePause = $speechTimingPolicy['inter_line_pause_seconds'];
            $requiredEndingPause = $speechTimingPolicy['ending_pause_seconds'];
            $timingTolerance = $speechTimingPolicy['timing_tolerance_seconds'];
            $budget = $this->speechBudget($speechRows, $duration, $speechTimingPolicy, $input);
            if ($budget['fits'] === false) {
                $this->addIssue($issues, 'speech_budget_exceeded', 'narration', $number,
                    'Clip '.$number.' needs about '.round($budget['required_seconds'], 2).' seconds for its exact words, but only '
                    .round($budget['available_seconds'], 2).' seconds remain after opening, closing and inter-line pauses. Preserve dialogue; allocate permitted time or replan across connected clips when count is AI-selected. Otherwise this conflict remains unresolved.');
            }

            if ($speechRows[0]['start'] + $timingTolerance < $openingBeat) {
                $this->addIssue(
                    $issues,
                    'speech_opening_visual_beat_missing',
                    'narration',
                    $number,
                    'Clip '.$number.' starts speech at '.$this->formatSeconds($speechRows[0]['start']).' seconds; reserve at least '.$this->formatSeconds($openingBeat).' seconds for the opening visual beat.',
                );
            }
            if (! $spokenAudioEnabled) {
                $this->addIssue(
                    $issues,
                    'speech_when_disabled',
                    'narration',
                    $number,
                    'Clip '.$number.' contains a timed spoken line even though spoken audio is off.',
                );
            }

            $previousEnd = null;
            foreach ($speechRows as $rowIndex => $row) {
                if ($row['action'] === '' || $row['speaker'] === '' || $row['line'] === '') {
                    $this->addIssue(
                        $issues,
                        'speech_map_incomplete',
                        'narration',
                        $number,
                        'Clip '.$number.' speech row '.($rowIndex + 1).' must include a visible action, speaker, and exact line.',
                    );
                    continue;
                }

                if ($row['start'] < 0 || $row['end'] <= $row['start']) {
                    $this->addIssue(
                        $issues,
                        'speech_time_range_invalid',
                        'narration',
                        $number,
                        'Clip '.$number.' speech row '.($rowIndex + 1).' has an invalid clip-relative time range.',
                    );
                }
                if ($duration !== null && $row['end'] > $duration) {
                    $this->addIssue(
                        $issues,
                        'speech_exceeds_clip',
                        'narration',
                        $number,
                        'Clip '.$number.' speech row '.($rowIndex + 1).' ends at '.$row['end'].' seconds, beyond the '.$duration.'-second clip.',
                    );
                }
                if ($previousEnd !== null && $row['start'] < $previousEnd) {
                    $this->addIssue(
                        $issues,
                        'speech_rows_overlap',
                        'narration',
                        $number,
                        'Clip '.$number.' has overlapping timed speech rows; leave room for natural pauses rather than layering or rushing lines.',
                    );
                } elseif ($previousEnd !== null && $row['start'] - $previousEnd + $timingTolerance < $requiredInterLinePause) {
                    $this->addIssue(
                        $issues,
                        'speech_inter_line_pause_missing',
                        'narration',
                        $number,
                        'Clip '.$number.' speech row '.($rowIndex + 1).' has less than the required '.$this->formatSeconds($requiredInterLinePause).'-second breathing/reaction pause after the preceding spoken row.',
                    );
                }
                $previousEnd = max($previousEnd ?? 0, $row['end']);

                $wordCount = $this->wordCount($row['line']);
                $minimumSeconds = is_numeric($row['minimum_seconds'] ?? null)
                    ? (float) $row['minimum_seconds']
                    : $this->minimumSpeechSeconds($row['line'], $input);
                $availableSeconds = max(0, $row['end'] - $row['start']);
                if ($wordCount > 0 && $availableSeconds + 0.05 < $minimumSeconds) {
                    $this->addIssue(
                        $issues,
                        'speech_overrun',
                        'narration',
                        $number,
                        'Clip '.$number.' speech row '.($rowIndex + 1).' has '.$wordCount.' words in '.round($availableSeconds, 1).' seconds; allow about '.round($minimumSeconds, 1).' seconds at a natural pace plus any pronunciation/comprehension needs. Preserve the exact words and speaker order. Allocate more time only within declared limits, or split into connected clips when count is AI-selected; otherwise leave the conflict unresolved. Never rush or silently rewrite protected dialogue.',
                    );
                }
            }

            $lastSpeechEnd = max(array_column($speechRows, 'end'));
            if ($duration !== null && $duration - $lastSpeechEnd + $timingTolerance < $requiredEndingPause) {
                $this->addIssue(
                    $issues,
                    'speech_leaves_no_visual_tail',
                    'narration',
                    $number,
                    'Clip '.$number.' leaves '.$this->formatSeconds($duration - $lastSpeechEnd).' seconds after the final spoken line; reserve at least '.$this->formatSeconds($requiredEndingPause).' seconds for the settled visual/closing handoff beat.',
                );
            }
        } elseif ($mapMarker !== null) {
            $normalizedMap = strtolower($mapMarker);
            $explicitlySilent = str_contains($normalizedMap, 'none')
                && (str_contains($normalizedMap, 'intentionally') || str_contains($normalizedMap, 'spoken audio is off'));

            if (! $explicitlySilent) {
                $this->addIssue(
                    $issues,
                    'speech_map_empty_unmarked',
                    'narration',
                    $number,
                    'Clip '.$number.' has no timed speech rows and must explicitly say the clip is intentionally silent, or that spoken audio is off.',
                );
            } elseif (! $spokenAudioEnabled && ! str_contains($normalizedMap, 'spoken audio is off')) {
                $this->addIssue(
                    $issues,
                    'speech_off_marker_mismatch',
                    'narration',
                    $number,
                    'Spoken audio is off; use exactly `SPEECH/VISUAL MAP: None — spoken audio is off` and include no words.',
                );
            } elseif ($spokenAudioEnabled && str_contains($normalizedMap, 'spoken audio is off')) {
                $this->addIssue(
                    $issues,
                    'speech_off_marker_mismatch',
                    'narration',
                    $number,
                    'Spoken audio is enabled; mark this clip as intentionally silent only if it contains no spoken line.',
                );
            }
        }

        return [
            'incoming_type' => $incomingType,
            'outgoing_type' => $outgoingType,
            'opening_state' => $values['OPENING CONTINUITY STATE'] ?? null,
            'ending_state' => $values['ENDING CONTINUITY STATE'] ?? null,
            'opening_presentation' => $values['OPENING PRESENTATION STATE'] ?? null,
            'ending_presentation' => $values['ENDING PRESENTATION STATE'] ?? null,
            'exact_opening_frame' => $exactOpeningFrame,
            'exact_ending_state' => $exactEndingState,
            'opening_prose_relation' => $openingProseRelation,
            'ending_prose_relation' => $endingProseRelation,
            'duration' => $duration,
        ];
    }

    /** @return array<int, array{line_index: int, start: float, end: float, action: string, speaker: string, line: string}> */
    private function speechRows(string $text): array
    {
        $rows = [];
        foreach (preg_split('/\R/u', $text) ?: [] as $lineIndex => $line) {
            $line = str_replace(['**', '__', '`'], '', $line);
            if (preg_match('/^\s*(?:[-*+•–]\s+)?(-?\d+(?:\.\d+)?)\s*(?:s)?\s*(?:–|—|-|to)\s*(-?\d+(?:\.\d+)?)\s*s\s*\|\s*VISIBLE ACTION:\s*(.*?)\s*\|\s*SPEAKER:\s*(.*?)\s*\|\s*LINE:\s*(.+?)\s*$/iu', $line, $match) !== 1) {
                continue;
            }

            $rows[] = [
                'line_index' => $lineIndex,
                'start' => (float) $match[1],
                'end' => (float) $match[2],
                'action' => trim($match[3]),
                'speaker' => trim($match[4]),
                'line' => trim($match[5]),
            ];
        }

        return $rows;
    }

    public function durationSeconds(string $text): ?float
    {
        $value = $this->fieldValue($text, 'CLIP DURATION');
        if ($value === null || preg_match('/^\s*(\d+(?:\.\d+)?)\s*(?:seconds?|secs?|s)\s*$/i', $value, $match) !== 1) {
            return null;
        }

        return (float) $match[1];
    }

    private function isPerClipDurationStatement(string $line): bool
    {
        $listPrefix = '(?:\\d+[.)]\\s*)?(?:[-*+>•]\\s*)?';
        if (preg_match('/^\\s*'.$listPrefix.'(?:clip|shot)\\s*#?\\s*\\d+\\s*[—–:-]\\s*\\d+(?:\\.\\d+)?\\s*(?:seconds?|secs?|s|minutes?|mins?|m)\\s*$/iu', $line) === 1
            || preg_match('/^\\s*'.$listPrefix.'(?:clip|shot)\\s*#?\\s*\\d+\\s*(?:(?:—|–|:|-)\\s*)?(?:(?:exact|planned|target|approximate|approx)\\s+)?duration\\s*(?::|=|is)?\\s*\\d+(?:\\.\\d+)?\\s*(?:seconds?|secs?|s|minutes?|mins?|m)\\s*$/iu', $line) === 1) {
            return true;
        }

        if (preg_match('/\\b(?:range|between|within|minimum|maximum|at\\s+least|no\\s+more\\s+than|up\\s+to|under|over|must|should|shall)\\b/iu', $line) === 1) {
            return false;
        }

        preg_match_all('/\\b(?:clip|shot)\\s*#?\\s*\\d+\\b[^\\d\\r\\n]{0,40}?\\b\\d+(?:\\.\\d+)?\\s*(?:seconds?|secs?|s|minutes?|mins?|m)\\b/iu', $line, $pointDurations);

        return count($pointDurations[0] ?? []) >= 2;
    }

    /**
     * Resolve effective clip-duration limits. A fixed saved choice remains exact;
     * explicit project ranges narrow the AI limit and conflicts stay unresolved.
     *
     * @return array{minimum: int, maximum: int, is_ai_selected: bool, fixed_duration: int|null, declared_minimum: int|null, declared_maximum: int|null, declared_label: string, conflict: bool, conflict_message: string}
     */
    public function durationLimits(string $content, array $input): array
    {
        $mode = (string) ($input['mode'] ?? 'story');
        $choice = $mode === 'extend'
            ? ($input['extend_duration'] ?? $input['clip_duration'] ?? ProjectSettings::AI_CHOICE)
            : ($input['clip_duration'] ?? ProjectSettings::AI_CHOICE);
        $isAiSelected = ProjectSettings::isAiChoice($choice) || ! is_numeric($choice);
        $fixedDuration = $isAiSelected ? null : (int) $choice;
        $minimum = $isAiSelected ? 1 : $fixedDuration;
        $maximum = $isAiSelected ? ProjectSettings::AI_DURATION_MAX : $fixedDuration;
        $declaredMinimums = [];
        $declaredMaximums = [];
        $texts = [$content];

        foreach (['duration_constraints', 'clip_duration_range', 'duration_rule', 'creative_direction', 'project_description', 'topic', 'next_action', 'learning_objective', 'style_detail', 'character_details'] as $key) {
            if (! isset($input[$key]) || ! is_string($input[$key]) || trim($input[$key]) === '') {
                continue;
            }
            $texts[] = $input[$key];
            if (in_array($key, ['duration_constraints', 'clip_duration_range', 'duration_rule'], true)
                && preg_match('/^\\s*(\\d{1,2})\\s*(?:-|–|—|to)\\s*(\\d{1,2})\\s*(?:seconds?|secs?|s)?\\s*$/iu', $input[$key], $rangeMatch) === 1) {
                $declaredMinimums[] = (int) $rangeMatch[1];
                $declaredMaximums[] = (int) $rangeMatch[2];
            }
        }

        $rangePatterns = [
            '/\\b(?:clip|shot)\\s+durations?\\s*(?:range\\s*)?(?:is|are|must\\s+be|:|=|of)?\\s*(?:between\\s+)?(\\d{1,2})\\s*(?:-|–|—|to|and)\\s*(\\d{1,2})\\s*(?:seconds?|secs?|s)\\b/iu',
            '/\\b(?:each|all|every)\\s+(?:video\\s+)?(?:clips?|shots?)\\b[^.!?;\\r\\n]{0,70}\\b(?:must|shall|should|need\\s+to|have\\s+to|stay|remain)\\s+(?:be\\s+)?(?:within\\s+|between\\s+|from\\s+)?(\\d{1,2})\\s*(?:-|–|—|to|and)\\s*(\\d{1,2})\\s*(?:seconds?|secs?|s)\\b/iu',
            '/\\b(?:clips?|shots?)\\s+(?:are|must\\s+be|shall\\s+be|should\\s+be|need\\s+to\\s+be|have\\s+to\\s+be)\\s+(?:within\\s+|between\\s+|from\\s+)?(\\d{1,2})\\s*(?:-|–|—|to|and)\\s*(\\d{1,2})\\s*(?:seconds?|secs?|s)\\b/iu',
            '/\\b(?:clips?|shots?)\\b[^.!?;\\r\\n]{0,60}?(\\d{1,2})\\s*(?:-|–|—|to|and)\\s*(\\d{1,2})\\s*(?:seconds?|secs?|s)\\b/iu',
            '/\\b(\\d{1,2})\\s*(?:-|–|—|to|and)\\s*(\\d{1,2})\\s*(?:seconds?|secs?|s)\\s+(?:per|for\\s+each)\\s+(?:clip|shot)\\b/iu',
            '/\\b(?:duration\\s+constraints?|clip\\s+duration\\s+constraints?)\\b[^.!?;\\r\\n]{0,45}?(\\d{1,2})\\s*(?:-|–|—|to|and)\\s*(\\d{1,2})\\s*(?:seconds?|secs?|s)\\b/iu',
            '/\\b(?:allowed\\s+|project\\s+)?(?:clip\\s+)?duration\\s+range\\b[^.!?;\\r\\n]{0,45}?(\\d{1,2})\\s*(?:-|–|—|to|and)\\s*(\\d{1,2})\\s*(?:seconds?|secs?|s)\\b/iu',
            '/\\b(?:keep|limit|restrict)\\b[^.!?;\\r\\n]{0,55}\\b(?:clips?|shots?)\\b[^.!?;\\r\\n]{0,35}\\b(?:between|within|from)\\s*(\\d{1,2})\\s*(?:-|–|—|to|and)\\s*(\\d{1,2})\\s*(?:seconds?|secs?|s)\\b/iu',
        ];
        $maximumPatterns = [
            '/\\b(?:clip|shot)\\s+durations?\\b[^.!?;\\r\\n]{0,55}\\b(?:must\\s+not\\s+exceed|may\\s+not\\s+exceed|cannot\\s+exceed|no\\s+more\\s+than|at\\s+most|maximum(?:\\s+of)?|max(?:\\s+of)?|up\\s+to)\\s*(\\d{1,2})\\s*(?:seconds?|secs?|s)\\b/iu',
            '/\\bclips?\\b[^.!?;\\r\\n]{0,55}\\b(?:must\\s+not\\s+exceed|may\\s+not\\s+exceed|cannot\\s+exceed|no\\s+more\\s+than|at\\s+most|maximum(?:\\s+of)?|up\\s+to)\\s*(\\d{1,2})\\s*(?:seconds?|secs?|s)\\b/iu',
        ];
        $minimumPatterns = [
            '/\\b(?:clip|shot)\\s+durations?\\b[^.!?;\\r\\n]{0,55}\\b(?:at\\s+least|minimum(?:\\s+of)?|min(?:\\s+of)?|no\\s+less\\s+than)\\s*(\\d{1,2})\\s*(?:seconds?|secs?|s)\\b/iu',
            '/\\bclips?\\b[^.!?;\\r\\n]{0,55}\\b(?:at\\s+least|minimum(?:\\s+of)?|no\\s+less\\s+than)\\s*(\\d{1,2})\\s*(?:seconds?|secs?|s)\\b/iu',
        ];

        foreach ($texts as $text) {
            foreach (preg_split('/(?:\\R+|(?<=[.!?;])\\s+|,\\s+)/u', $text) ?: [] as $line) {
                $lower = strtolower($line);
                // Do not let the broad global-range matcher read `Clip 1 — 5 seconds` as `1–5`.
                if ($this->isPerClipDurationStatement($line)
                    || ($isAiSelected && preg_match('/\\bmanual\\b/u', $lower) === 1)
                    || preg_match('/\\b(?:not\\s+required|not\\s+necessary|not\\s+mandatory|not\\s+constrained|not\\s+limited\\s+to|not\\s+restricted\\s+to|no\\s+(?:fixed|strict|specific)\\s+(?:clip\\s+)?duration\\s+(?:range|limit|constraint)|not\\s+(?:a\\s+)?(?:hard\\s+)?(?:duration\\s+)?(?:range|limit|constraint)|should\\s+not\\s+(?:be|stay|remain)|do\\s+not\\s+(?:need\\s+to|have\\s+to)\\s+(?:be|stay|remain))\\b/u', $lower) === 1) {
                    continue;
                }
                foreach ($rangePatterns as $pattern) {
                    if (preg_match_all($pattern, $line, $matches, PREG_SET_ORDER) !== false) {
                        foreach ($matches as $match) {
                            $declaredMinimums[] = (int) $match[1];
                            $declaredMaximums[] = (int) $match[2];
                        }
                    }
                }
                foreach ($maximumPatterns as $pattern) {
                    if (preg_match_all($pattern, $line, $matches, PREG_SET_ORDER) !== false) {
                        foreach ($matches as $match) {
                            $declaredMaximums[] = (int) $match[1];
                        }
                    }
                }
                foreach ($minimumPatterns as $pattern) {
                    if (preg_match_all($pattern, $line, $matches, PREG_SET_ORDER) !== false) {
                        foreach ($matches as $match) {
                            $declaredMinimums[] = (int) $match[1];
                        }
                    }
                }
            }
        }

        $declaredMinimum = $declaredMinimums === [] ? null : max($declaredMinimums);
        $declaredMaximum = $declaredMaximums === [] ? null : min($declaredMaximums);
        $declaredConflict = $declaredMinimum !== null && $declaredMaximum !== null && $declaredMinimum > $declaredMaximum;
        $selectedConflict = $fixedDuration !== null
            && (($declaredMinimum !== null && $fixedDuration < $declaredMinimum)
                || ($declaredMaximum !== null && $fixedDuration > $declaredMaximum));
        $conflict = $declaredConflict || $selectedConflict;

        if ($fixedDuration === null) {
            $minimum = max($minimum, $declaredMinimum ?? $minimum);
            $maximum = min($maximum, $declaredMaximum ?? $maximum);
            $conflict = $conflict || $minimum > $maximum;
        }

        $declaredLabel = $declaredMinimum !== null && $declaredMaximum !== null
            ? $declaredMinimum.'–'.$declaredMaximum.' seconds'
            : ($declaredMinimum !== null
                ? 'at least '.$declaredMinimum.' seconds'
                : ($declaredMaximum !== null ? 'no more than '.$declaredMaximum.' seconds' : 'no additional project range declared'));
        $conflictMessage = $declaredConflict
            ? 'The saved prompt declares incompatible clip-duration limits; QA and Auto-Fix cannot safely choose which limit is authoritative.'
            : ($selectedConflict
                ? 'The fixed duration choice of '.$fixedDuration.' seconds conflicts with the project-declared range '.$declaredLabel.'; no duration will be changed automatically.'
                : 'The combined project and application duration limits have no safe overlap; no duration will be changed automatically.');

        return [
            'minimum' => (int) $minimum,
            'maximum' => (int) $maximum,
            'is_ai_selected' => $isAiSelected,
            'fixed_duration' => $fixedDuration,
            'declared_minimum' => $declaredMinimum,
            'declared_maximum' => $declaredMaximum,
            'declared_label' => $declaredLabel,
            'conflict' => $conflict,
            'conflict_message' => $conflictMessage,
        ];
    }

    /**
     * Parse explicit project-wide duration bounds, keeping descriptive runtime totals
     * separate from hard limits. These constraints only apply when stated as bounds.
     *
     * @return array{minimum: int|null, maximum: int|null, conflict: bool, declared_label: string}
     */
    public function projectRuntimeLimits(string $content, array $input): array
    {
        $minimums = [];
        $maximums = [];
        foreach (['project_runtime_min_seconds', 'total_runtime_min_seconds'] as $key) {
            if (isset($input[$key]) && is_numeric($input[$key])) {
                $minimums[] = max(0, (int) $input[$key]);
            }
        }
        foreach (['project_runtime_max_seconds', 'total_runtime_max_seconds'] as $key) {
            if (isset($input[$key]) && is_numeric($input[$key])) {
                $maximums[] = max(0, (int) $input[$key]);
            }
        }

        foreach (['project_runtime_range', 'total_runtime_range'] as $key) {
            $rangeText = $input[$key] ?? null;
            if (is_string($rangeText)
                && preg_match('/^\\s*(\\d{1,4})\\s*(?:-|–|—|to)\\s*(\\d{1,4})\\s*(?:seconds?|secs?|s)?\\s*$/iu', $rangeText, $range) === 1) {
                $minimums[] = (int) $range[1];
                $maximums[] = (int) $range[2];
            }
        }

        $texts = [$content];
        foreach (['project_runtime_range', 'total_runtime_range', 'runtime_constraints', 'total_runtime_constraints', 'duration_constraints', 'project_description', 'creative_direction'] as $key) {
            if (isset($input[$key]) && is_string($input[$key]) && trim($input[$key]) !== '') {
                $texts[] = $input[$key];
            }
        }

        foreach ($texts as $text) {
            foreach (preg_split('/(?:\R+|(?<=[.!?;])\s+|,\s+)/u', $text) ?: [] as $line) {
                $runtimeLabel = '(?:running\\s+time|run[ -]?time|runtime|duration|length)';
                $runtimeScope = '(?:total|overall|project(?:[- ]wide)?|video)';
                $hasRuntimeLabel = preg_match('/\\b'.$runtimeScope.'\\s+(?:(?:minimum|maximum|min|max)\\s+)?'.$runtimeLabel.'\\b/iu', $line) === 1
                    || preg_match('/\\b(?:minimum|maximum|min|max)\\s+(?:of\\s+)?'.$runtimeScope.'\\s+'.$runtimeLabel.'\\b/iu', $line) === 1;
                if (! $hasRuntimeLabel || preg_match('/\\bper\\s+clip\\b/iu', $line) === 1) {
                    continue;
                }

                if (preg_match('/\\b(?:minimum|min)\\s+(?:of\\s+)?'.$runtimeScope.'\\s+'.$runtimeLabel.'\\b[^0-9]{0,20}(\\d{1,4})\\s*(?:seconds?|secs?|s)\\b/iu', $line, $leadingMinimum) === 1
                    || preg_match('/\\b'.$runtimeScope.'\\s+(?:minimum|min)\\s+'.$runtimeLabel.'\\b[^0-9]{0,20}(\\d{1,4})\\s*(?:seconds?|secs?|s)\\b/iu', $line, $leadingMinimum) === 1) {
                    $minimums[] = (int) $leadingMinimum[1];
                }
                if (preg_match('/\\b(?:maximum|max)\\s+(?:of\\s+)?'.$runtimeScope.'\\s+'.$runtimeLabel.'\\b[^0-9]{0,20}(\\d{1,4})\\s*(?:seconds?|secs?|s)\\b/iu', $line, $leadingMaximum) === 1
                    || preg_match('/\\b'.$runtimeScope.'\\s+(?:maximum|max)\\s+'.$runtimeLabel.'\\b[^0-9]{0,20}(\\d{1,4})\\s*(?:seconds?|secs?|s)\\b/iu', $line, $leadingMaximum) === 1) {
                    $maximums[] = (int) $leadingMaximum[1];
                }

                if (preg_match('/(?:(?:\\b(?:between|within|range(?:\\s+of)?|must\\s+be|should\\s+be)\\s*)?)(\\d{1,4})\\s*(?:-|–|—|to|and)\\s*(\\d{1,4})\\s*(?:seconds?|secs?|s)\\b/iu', $line, $range) === 1) {
                    $minimums[] = (int) $range[1];
                    $maximums[] = (int) $range[2];
                    continue;
                }

                if (preg_match('/\b(?:must\s+not\s+exceed|may\s+not\s+exceed|cannot\s+exceed|no\s+more\s+than|at\s+most|maximum(?:\s+of)?|max(?:\s+of)?|under|below|up\s+to)\s*(\d{1,4})\s*(?:seconds?|secs?|s)\b/iu', $line, $maximum) === 1) {
                    $maximums[] = (int) $maximum[1];
                }
                if (preg_match('/\b(?:at\s+least|minimum(?:\s+of)?|min(?:\s+of)?|no\s+less\s+than|over|above)\s*(\d{1,4})\s*(?:seconds?|secs?|s)\b/iu', $line, $minimum) === 1) {
                    $minimums[] = (int) $minimum[1];
                }
            }
        }

        $minimum = $minimums === [] ? null : max($minimums);
        $maximum = $maximums === [] ? null : min($maximums);

        return [
            'minimum' => $minimum,
            'maximum' => $maximum,
            'conflict' => $minimum !== null && $maximum !== null && $minimum > $maximum,
            'declared_label' => $minimum !== null && $maximum !== null
                ? $minimum.'–'.$maximum.' seconds'
                : ($minimum !== null
                    ? 'at least '.$minimum.' seconds'
                    : ($maximum !== null ? 'no more than '.$maximum.' seconds' : 'no project-wide runtime limit declared')),
        ];
    }

    /**
     * Find explicit, numbered per-clip duration references inside SECTION 1 — PROJECT OVERVIEW.
     * Offsets are relative to the returned source line so safe repairs can replace only the number.
     *
     * @return array<int, array{clip: int, seconds: float, value: string, unit: string, line_index: int, offset: int, length: int}>
     */
    public function overviewDurationReferences(string $content): array
    {
        $lines = preg_split('/\R/u', str_replace("\r", '', $content)) ?: [];
        $range = $this->projectOverviewLineRange($lines);
        if ($range === null) {
            return [];
        }

        $references = [];
        for ($lineIndex = $range['start']; $lineIndex <= $range['end']; $lineIndex++) {
            $line = $lines[$lineIndex] ?? '';
            if ($this->containsCompoundDuration($line)) {
                continue;
            }
            preg_match_all(
                '/\\b(?:clip|shot)\\s*#?\\s*(\\d+)\\b[^\\d\\r\\n]{0,40}?(\\d+(?:\\.\\d+)?)\\s*(seconds?|secs?|s|minutes?|mins?|m)\\b/iu',
                $line,
                $matches,
                PREG_SET_ORDER | PREG_OFFSET_CAPTURE,
            );

            foreach ($matches as $match) {
                $value = $match[2][0];
                $offset = $match[2][1];
                $afterValue = substr($line, $offset + strlen($value), 12);
                if (preg_match('/^\\s*[–—-]\\s*\\d/u', $afterValue) === 1) {
                    continue;
                }

                $unit = strtolower($match[3][0]);
                $numeric = (float) $value;
                $seconds = str_starts_with($unit, 'm') ? $numeric * 60 : $numeric;
                $references[] = [
                    'clip' => (int) $match[1][0],
                    'seconds' => $seconds,
                    'value' => $value,
                    'unit' => $unit,
                    'line_index' => $lineIndex,
                    'offset' => $offset,
                    'length' => strlen($value),
                ];
            }

            if ($matches !== [] || preg_match('/\\b(?:clip\\s+)?durations?(?:\\s+by\\s+clip)?\\s*[:=—–-]\\s*(.+)$/iu', $line, $durationList, PREG_OFFSET_CAPTURE) !== 1) {
                continue;
            }

            $listText = $durationList[1][0];
            $listStart = $durationList[1][1];
            preg_match_all(
                '/(\\d+(?:\\.\\d+)?)\\s*(seconds?|secs?|s|minutes?|mins?|m)\\b/iu',
                $listText,
                $listMatches,
                PREG_SET_ORDER | PREG_OFFSET_CAPTURE,
            );
            foreach ($listMatches as $ordinal => $match) {
                $value = $match[1][0];
                $offset = $listStart + $match[1][1];
                $afterValue = substr($line, $offset + strlen($value), 12);
                if (preg_match('/^\\s*[–—-]\\s*\\d/u', $afterValue) === 1) {
                    continue;
                }

                $unit = strtolower($match[2][0]);
                $numeric = (float) $value;
                $references[] = [
                    'clip' => $ordinal + 1,
                    'seconds' => str_starts_with($unit, 'm') ? $numeric * 60 : $numeric,
                    'value' => $value,
                    'unit' => $unit,
                    'line_index' => $lineIndex,
                    'offset' => $offset,
                    'length' => strlen($value),
                ];
            }
        }

        return $references;
    }

    /**
     * Find explicit total-runtime references in the project overview.
     * Offsets are relative to the source line, matching overviewDurationReferences().
     *
     * @return array<int, array{seconds: float, value: string, unit: string, line_index: int, offset: int, length: int}>
     */
    public function overviewRuntimeReferences(string $content): array
    {
        $lines = preg_split('/\R/u', str_replace("\r", '', $content)) ?: [];
        $range = $this->projectOverviewLineRange($lines);
        if ($range === null) {
            return [];
        }

        $label = '(?:(?:(?:approx(?:imate(?:ly)?)?|estimated|about|roughly|projected|planned)\\.?\\s+)?(?:total|overall|project|video)\\s+(?:(?:approx(?:imate(?:ly)?)?|estimated)\\s+)?(?:running\\s+time|run[ -]?time|runtime|duration|length))';
        $clockPattern = '/'.$label.'\\b[^0-9\\r\\n]{0,40}(\\d{1,3}:\\d{2}(?::\\d{2})?)(?![\\d:])/iu';
        $unitPattern = '/'.$label.'\\b[^0-9\\r\\n]{0,40}(\\d+(?:\\.\\d+)?)\\s*(seconds?|secs?|s|minutes?|mins?|m)\\b/iu';
        $references = [];

        for ($lineIndex = $range['start']; $lineIndex <= $range['end']; $lineIndex++) {
            $line = $lines[$lineIndex] ?? '';
            if ($this->containsCompoundDuration($line)) {
                continue;
            }

            foreach ([['pattern' => $clockPattern, 'kind' => 'clock'], ['pattern' => $unitPattern, 'kind' => 'unit']] as $specification) {
                preg_match_all(
                    $specification['pattern'],
                    $line,
                    $matches,
                    PREG_SET_ORDER | PREG_OFFSET_CAPTURE,
                );

                foreach ($matches as $match) {
                    $value = $match[1][0];
                    $offset = $match[1][1];
                    $afterValue = substr($line, $offset + strlen($value), 12);
                    if ($specification['kind'] === 'unit' && preg_match('/^\\s*[–—-]\\s*\\d/u', $afterValue) === 1) {
                        continue;
                    }

                    if ($specification['kind'] === 'clock') {
                        $parts = array_map('intval', explode(':', $value));
                        if (count($parts) === 3) {
                            if ($parts[1] > 59 || $parts[2] > 59) {
                                continue;
                            }
                            $seconds = ($parts[0] * 3600) + ($parts[1] * 60) + $parts[2];
                            $unit = 'clock_hhmmss';
                        } else {
                            if ($parts[1] > 59) {
                                continue;
                            }
                            $seconds = ($parts[0] * 60) + $parts[1];
                            $unit = 'clock_mmss';
                        }
                    } else {
                        $unit = strtolower($match[2][0]);
                        $numeric = (float) $value;
                        $seconds = str_starts_with($unit, 'm') ? $numeric * 60 : $numeric;
                    }

                    $references[] = [
                        'seconds' => (float) $seconds,
                        'value' => $value,
                        'unit' => $unit,
                        'line_index' => $lineIndex,
                        'offset' => $offset,
                        'length' => strlen($value),
                    ];
                }
            }
        }

        return $references;
    }

    /**
     * Find explicit duration values in the clip-plan / shot-list section, excluding
     * copy-ready prompt blocks. Each result points to the numeric token for a safe sync.
     *
     * @return array<int, array{clip: int, seconds: float, value: string, unit: string, line_index: int, offset: int, length: int}>
     */
    public function clipPlanDurationReferences(string $content): array
    {
        $lines = preg_split('/\\R/u', str_replace("\\r", '', $content)) ?: [];
        $range = $this->clipPlanLineRange($lines);
        if ($range === null) {
            return [];
        }

        $references = [];
        $currentClip = null;
        for ($lineIndex = $range['start']; $lineIndex <= $range['end']; $lineIndex++) {
            $line = $lines[$lineIndex] ?? '';
            $cleanLine = str_replace(['**', '__', '`'], '', $line);
            if (preg_match('/^\\s*(?:[-*+>]\\s*)?(?:#+\\s*)?CLIP\\s*#?\\s*(\\d+)\\b/iu', $cleanLine, $clipMatch) === 1) {
                $currentClip = (int) $clipMatch[1];
            }
            if ($this->containsCompoundDuration($line)) {
                continue;
            }

            $lineReferences = [];
            preg_match_all(
                '/\\bCLIP\\s*#?\\s*(\\d+)\\b[^\\r\\n]{0,100}?\\b(?:(?:exact|planned|target|approx(?:imate)?)\\s+)?DURATION\\b[^\\r\\n]{0,50}?(\\d+(?:\\.\\d+)?)\\s*(seconds?|secs?|s|minutes?|mins?|m)\\b/iu',
                $line,
                $directMatches,
                PREG_SET_ORDER | PREG_OFFSET_CAPTURE,
            );
            foreach ($directMatches as $match) {
                $value = $match[2][0];
                $offset = $match[2][1];
                if ($this->isDurationRangeValue($line, $offset, $value)) {
                    continue;
                }
                $lineReferences[] = [
                    'clip' => (int) $match[1][0],
                    'value' => $value,
                    'unit' => strtolower($match[3][0]),
                    'offset' => $offset,
                ];
            }

            if ($lineReferences === [] && $currentClip !== null) {
                preg_match_all(
                    '/\\b(?:(?:exact|planned|target)\\s+)?DURATION\\b[^\\r\\n]{0,45}?(\\d+(?:\\.\\d+)?)\\s*(seconds?|secs?|s|minutes?|mins?|m)\\b/iu',
                    $line,
                    $fieldMatches,
                    PREG_SET_ORDER | PREG_OFFSET_CAPTURE,
                );
                foreach ($fieldMatches as $match) {
                    $value = $match[1][0];
                    $offset = $match[1][1];
                    if ($this->isDurationRangeValue($line, $offset, $value)) {
                        continue;
                    }
                    $lineReferences[] = [
                        'clip' => $currentClip,
                        'value' => $value,
                        'unit' => strtolower($match[2][0]),
                        'offset' => $offset,
                    ];
                }
            }

            foreach ($lineReferences as $reference) {
                $numeric = (float) $reference['value'];
                $unit = $reference['unit'];
                $references[] = [
                    'clip' => $reference['clip'],
                    'seconds' => str_starts_with($unit, 'm') ? $numeric * 60 : $numeric,
                    'value' => $reference['value'],
                    'unit' => $unit,
                    'line_index' => $lineIndex,
                    'offset' => $reference['offset'],
                    'length' => strlen($reference['value']),
                ];
            }
        }

        return $references;
    }

    public function hasProjectOverviewSection(string $content): bool
    {
        $lines = preg_split('/\\R/u', str_replace("\\r", '', $content)) ?: [];

        return $this->projectOverviewLineRange($lines) !== null;
    }

    public function hasClipPlanSection(string $content): bool
    {
        $lines = preg_split('/\\R/u', str_replace("\\r", '', $content)) ?: [];

        return $this->clipPlanLineRange($lines) !== null;
    }

    /**
     * Conservatively compare a structured state with its natural-language exact-frame prose.
     * Only explicit contradictions fail; compatible paraphrases are not treated as verbatim copies.
     */
    public function stateProseRelation(?string $structuredState, ?string $prose): string
    {
        $state = $this->normalizeState($structuredState);
        $written = $this->normalizeState($prose);
        if ($state === '' || $written === '') {
            return 'unverified';
        }
        if ($state === $written) {
            return 'exact';
        }

        $stateMap = $this->stateMap($state);
        if ($stateMap !== null) {
            foreach ($stateMap as $value) {
                if ($this->hasOpposingStateTerms($value, $written)) {
                    return 'mismatch';
                }
            }
        } elseif ($this->hasOpposingStateTerms($state, $written)) {
            return 'mismatch';
        }

        return $this->stateTextsAreCompatible($state, $written, $stateMap === null) ? 'compatible' : 'unverified';
    }

    /** @return array<int, int> Line indexes in the final continuity-audit section that claim exact copies. */
    public function continuityAuditClaimLineIndexes(string $content): array
    {
        return array_values(array_unique([
            ...$this->continuityClaimIndexesByKind($content, 'exact'),
            ...$this->continuityClaimIndexesByKind($content, 'agreement'),
        ]));
    }

    /** @return array<int, int> */
    private function continuityClaimIndexesByKind(string $content, string $kind): array
    {
        $lines = preg_split('/\R/u', str_replace("\r", '', $content)) ?: [];
        $range = $this->finalContinuityAuditLineRange($lines);
        if ($range === null) {
            return [];
        }

        $indexes = [];
        for ($lineIndex = $range['start']; $lineIndex <= $range['end']; $lineIndex++) {
            $line = $lines[$lineIndex] ?? '';
            $matches = $kind === 'exact'
                ? $this->lineClaimsExactStateCopy($line)
                : $this->lineClaimsHandoffAgreement($line);
            if ($matches) {
                $indexes[] = $lineIndex;
            }
        }

        return $indexes;
    }

    /** @return array<int, int> */
    public function timingAuditClaimLineIndexes(string $content): array
    {
        return $this->finalAuditClaimLineIndexes($content, fn (string $line): bool => $this->lineClaimsSynchronizedTiming($line));
    }

    /** @return array<int, int> */
    public function speechPacingAuditClaimLineIndexes(string $content): array
    {
        return $this->finalAuditClaimLineIndexes($content, fn (string $line): bool => $this->lineClaimsUniversalSpeechPacing($line));
    }

    /** @param callable(string): bool $matches @return array<int, int> */
    private function finalAuditClaimLineIndexes(string $content, callable $matches): array
    {
        $lines = preg_split('/\\R/u', str_replace("\\r", '', $content)) ?: [];
        $range = $this->finalContinuityAuditLineRange($lines);
        if ($range === null) {
            return [];
        }

        $indexes = [];
        for ($lineIndex = $range['start']; $lineIndex <= $range['end']; $lineIndex++) {
            if ($matches($lines[$lineIndex] ?? '')) {
                $indexes[] = $lineIndex;
            }
        }

        return $indexes;
    }

    private function incomingType(string $text): ?string
    {
        $lines = preg_split('/\R/u', $text) ?: [];
        foreach ($lines as $line) {
            $line = str_replace(['**', '__', '`'], '', $line);
            if (preg_match('/^\s*(?:[-*+>]\s*)?(?:INCOMING\s+)?TRANSITION\s+TYPE\s*:\s*(.+)$/i', $line, $match) !== 1) {
                continue;
            }
            if (preg_match('/\bTYPE\s*([ABC])\b/i', $match[1], $type) === 1) {
                return strtoupper($type[1]);
            }
            if (preg_match('/\bINITIAL\b/i', $match[1]) === 1) {
                return 'INITIAL';
            }
        }

        return null;
    }

    private function outgoingType(string $text): ?string
    {
        $lines = preg_split('/\R/u', $text) ?: [];
        foreach ($lines as $line) {
            $line = str_replace(['**', '__', '`'], '', $line);
            if (preg_match('/^\s*(?:[-*+>]\s*)?OUTGOING\s+HANDOFF\s+TYPE\s*:\s*(.+)$/i', $line, $match) !== 1) {
                continue;
            }
            if (preg_match('/\bFINAL\b/i', $match[1]) === 1) {
                return 'FINAL';
            }
            if (preg_match('/\bTYPE\s*([ABC])\b/i', $match[1], $type) === 1) {
                return strtoupper($type[1]);
            }
        }

        return null;
    }

    public function fieldValue(string $text, string $field): ?string
    {
        foreach (preg_split('/\R/u', $text) ?: [] as $line) {
            $line = str_replace(['**', '__', '`'], '', $line);
            if (preg_match('/^\s*(?:[-*+>]\s*)?'.preg_quote($field, '/').'\s*:\s*(.*?)\s*$/iu', $line, $match) === 1) {
                return trim($match[1]);
            }
        }

        return null;
    }

    /** @param array<int, array{number: int}> $clips @param array<int, array<string, mixed>> $details @param array<int, array<string, mixed>> $issues */
    private function auditProjectOverview(string $content, array $clips, array $details, array &$issues, ?PromptProjectPlan $plan = null): void
    {
        $sourceLines = preg_split('/\\R/u', str_replace("\\r", '', $content)) ?: [];
        if ($this->projectOverviewLineRange($sourceLines) === null) {
            return;
        }

        $references = $plan?->overviewDurations ?? $this->overviewDurationReferences($content);
        $runtimeReferences = $plan?->overviewRuntimes ?? $this->overviewRuntimeReferences($content);
        if ($references === []) {
            $this->addIssue(
                $issues,
                'overview_clip_durations_unverifiable',
                'duration',
                null,
                'The project overview has no parseable per-clip duration list, so its values cannot be compared with the copy-ready clip prompts.',
                false,
            );
        }
        if ($runtimeReferences === []) {
            $this->addIssue(
                $issues,
                'overview_total_runtime_missing',
                'duration',
                null,
                'The project overview does not include a parseable total runtime to compare with the sum of copy-ready clip durations.',
                false,
            );
        }

        $actualDurations = [];
        foreach ($clips as $index => $clip) {
            $actualDurations[$clip['number']] = $details[$index]['duration'] ?? null;
        }

        if ($references !== []) {
            $referencesByClip = [];
            foreach ($references as $reference) {
                $referencesByClip[$reference['clip']][] = $reference;
                if (! array_key_exists($reference['clip'], $actualDurations)) {
                    $this->addIssue(
                        $issues,
                        'overview_clip_duration_unknown',
                        'duration',
                        $reference['clip'],
                        'The project overview lists a duration for Clip '.$reference['clip'].', but no matching copy-ready clip prompt was found.',
                        false,
                    );
                }
            }

            foreach ($actualDurations as $clipNumber => $actualDuration) {
                if ($actualDuration === null) {
                    continue;
                }

                $clipReferences = $referencesByClip[$clipNumber] ?? [];
                if ($clipReferences === []) {
                    $this->addIssue(
                        $issues,
                        'overview_clip_duration_missing',
                        'duration',
                        $clipNumber,
                        'The project overview contains numbered clip-duration references but does not list a duration for Clip '.$clipNumber.'.',
                        false,
                    );
                    continue;
                }

                foreach ($clipReferences as $reference) {
                    if (abs($reference['seconds'] - $actualDuration) > 0.01) {
                        $this->addIssue(
                            $issues,
                            'overview_clip_duration_mismatch',
                            'duration',
                            $clipNumber,
                            'Project overview lists Clip '.$clipNumber.' as '.$this->formatSeconds($reference['seconds']).' seconds, but its copy-ready prompt says '.$this->formatSeconds($actualDuration).' seconds.',
                        );
                    }
                }
            }
        }

        $durationsAvailable = count(array_filter($actualDurations, static fn (mixed $duration): bool => is_numeric($duration))) === count($clips);
        if ($runtimeReferences === [] || ! $durationsAvailable) {
            return;
        }

        $runtime = array_sum(array_map('floatval', array_values($actualDurations)));
        foreach ($runtimeReferences as $reference) {
            if (abs($reference['seconds'] - $runtime) > 0.01) {
                $this->addIssue(
                    $issues,
                    'overview_total_runtime_mismatch',
                    'duration',
                    null,
                    'Project overview total runtime is '.$this->formatSeconds($reference['seconds']).' seconds, but the copy-ready clip durations sum to '.$this->formatSeconds($runtime).' seconds.',
                );
            }
        }
    }

    /** @param array<int, array<string, mixed>> $issues */
    private function auditClipPlanDurations(string $content, array $clips, array $details, array &$issues, ?PromptProjectPlan $plan = null): void
    {
        $lines = preg_split('/\\R/u', str_replace("\\r", '', $content)) ?: [];
        if ($this->clipPlanLineRange($lines) === null) {
            return;
        }

        $references = $plan?->clipPlanDurations ?? $this->clipPlanDurationReferences($content);
        $referencesByClip = [];
        foreach ($references as $reference) {
            $referencesByClip[$reference['clip']][] = $reference;
        }

        $actualDurations = [];
        foreach ($clips as $index => $clip) {
            $actualDurations[$clip['number']] = $details[$index]['duration'] ?? null;
        }

        foreach ($referencesByClip as $clipNumber => $clipReferences) {
            if (! array_key_exists($clipNumber, $actualDurations)) {
                $this->addIssue(
                    $issues,
                    'clip_plan_duration_unknown',
                    'duration',
                    (int) $clipNumber,
                    'The clip plan lists a duration for Clip '.$clipNumber.', but no matching copy-ready clip prompt was found.',
                    false,
                );
            }
        }

        foreach ($actualDurations as $clipNumber => $actualDuration) {
            $clipReferences = $referencesByClip[$clipNumber] ?? [];
            if ($clipReferences === []) {
                $this->addIssue(
                    $issues,
                    'clip_plan_duration_missing',
                    'duration',
                    $clipNumber,
                    'The clip plan / shot list does not provide a parseable planned duration for Clip '.$clipNumber.'.',
                    false,
                );
                continue;
            }
            if ($actualDuration === null) {
                continue;
            }

            foreach ($clipReferences as $reference) {
                if (abs($reference['seconds'] - $actualDuration) > 0.01) {
                    $this->addIssue(
                        $issues,
                        'clip_plan_duration_mismatch',
                        'duration',
                        $clipNumber,
                        'The clip plan lists Clip '.$clipNumber.' as '.$this->formatSeconds($reference['seconds']).' seconds, but its copy-ready prompt says '.$this->formatSeconds($actualDuration).' seconds.',
                    );
                }
            }
        }
    }

    /** @param array<int, array<string, mixed>> $issues */
    private function auditProjectRuntimeConstraints(PromptProjectPlan $plan, array &$issues): void
    {
        $limits = $plan->projectRuntimeLimits;
        if (($limits['conflict'] ?? false) === true) {
            $this->addIssue(
                $issues,
                'project_runtime_constraint_conflict',
                'duration',
                null,
                'Saved project-wide runtime constraints conflict: minimum '.$limits['minimum'].' seconds exceeds maximum '.$limits['maximum'].' seconds. No automatic duration change is safe.',
                false,
            );

            return;
        }

        $runtime = $plan->totalRuntimeSeconds();
        if ($runtime === null) {
            return;
        }
        if (($limits['minimum'] !== null && $runtime < $limits['minimum'])
            || ($limits['maximum'] !== null && $runtime > $limits['maximum'])) {
            $this->addIssue(
                $issues,
                'project_runtime_constraint_violation',
                'duration',
                null,
                'Canonical clip durations total '.$this->formatSeconds($runtime).' seconds, outside the saved project-wide runtime constraint '.$limits['declared_label'].'.',
                false,
            );
        }
    }

    /** @param array<int, array<string, mixed>> $handoffs @param array<int, array<string, mixed>> $clips @param array<int, array<string, mixed>> $issues */
    private function auditFinalContinuityClaims(string $content, array $handoffs, array $clips, array $input, array &$issues, ?PromptProjectPlan $plan = null): void
    {
        $hasNonExactHandoff = $handoffs === [];
        $hasContradictoryHandoff = $handoffs === [];
        foreach ($handoffs as $handoff) {
            $relation = $handoff['overall_relation'] ?? 'unverified';
            $hasNonExactHandoff = $hasNonExactHandoff || $relation !== 'exact';
            $hasContradictoryHandoff = $hasContradictoryHandoff || in_array($relation, ['mismatch', 'unverified'], true);
        }
        $handoffValidatorFailed = false;
        foreach ($issues as $issue) {
            if (in_array($issue['code'] ?? '', [
                'continuity_state_mismatch', 'presentation_state_mismatch', 'handoff_type_mismatch',
                'handoff_opening_prose_mismatch', 'handoff_prose_state_mismatch',
                'type_b_transition_action_unverified', 'type_c_transition_undocumented',
            ], true)) {
                $handoffValidatorFailed = true;
                break;
            }
        }
        $hasExactClaims = $this->continuityClaimIndexesByKind($content, 'exact') !== [];
        $hasAgreementClaims = $this->continuityClaimIndexesByKind($content, 'agreement') !== [];
        $hasUnverifiablePhysicalClaim = $this->finalAuditHasUnverifiablePhysicalHandoffClaim($content);

        if (($hasNonExactHandoff && $hasExactClaims)
            || (($hasContradictoryHandoff || $handoffValidatorFailed) && $hasAgreementClaims)
            || $hasUnverifiablePhysicalClaim) {
            $this->addIssue(
                $issues,
                'continuity_audit_overclaim',
                'continuity',
                null,
                'The final continuity audit claims an exact copy or universal handoff agreement that conflicts with at least one mismatched, unverified, or validator-failing handoff.',
            );
        }

        if ($this->timingAuditClaimLineIndexes($content) !== []
            && ! $this->hasEvidenceForSynchronizedTiming($content, $clips, $issues, $plan)) {
            $this->addIssue(
                $issues,
                'timing_audit_overclaim',
                'duration',
                null,
                'The final audit claims synchronized timing, but the project overview, final clip-duration sum, clip plan/shot list, or duration-limit checks do not provide complete passing evidence.',
            );
        }

        if ($this->speechPacingAuditClaimLineIndexes($content) !== []
            && ! $this->hasEvidenceForSpeechPacing($clips, $input, $issues)) {
            $this->addIssue(
                $issues,
                'speech_pacing_overclaim',
                'narration',
                null,
                'The final audit makes a universal speech-pacing claim that is not supported by complete, passing text-based timing checks for every parsed spoken line.',
            );
        }
    }

    private function finalAuditHasUnverifiablePhysicalHandoffClaim(string $content): bool
    {
        $lines = preg_split('/\\R/u', str_replace("\\r", '', $content)) ?: [];
        $range = $this->finalContinuityAuditLineRange($lines);
        if ($range === null) {
            return false;
        }

        for ($index = $range['start']; $index <= $range['end']; $index++) {
            $line = trim(str_replace(['**', '__', '`'], '', $lines[$index] ?? ''));
            if (preg_match('/\\b(?:not|never|cannot|can\\x27t|does\\s+not|do\\s+not|without|unverified|not\\s+rendered)\\b/iu', $line) === 1) {
                continue;
            }
            if (preg_match('/\\b(?:hand[ -]?offs?|transitions?|clip[ -]to[ -]clip|continuity)\\b[^.!?;]{0,100}\\b(?:physically\\s+plausible|physically\\s+feasible|physics\\s+verified|seamless(?:ly)?|visually\\s+seamless|rendered\\s+and\\s+verified)\\b/iu', $line) === 1
                || preg_match('/\\b(?:physically\\s+plausible|physically\\s+feasible|physics\\s+verified|seamless(?:ly)?|visually\\s+seamless|rendered\\s+and\\s+verified)\\b[^.!?;]{0,100}\\b(?:hand[ -]?offs?|transitions?|clip[ -]to[ -]clip|continuity)\\b/iu', $line) === 1) {
                return true;
            }
        }

        return false;
    }

    /** @param array<int, array<string, mixed>> $clips @param array<int, array<string, mixed>> $issues */
    private function hasEvidenceForSynchronizedTiming(string $content, array $clips, array $issues, ?PromptProjectPlan $plan = null): bool
    {
        if (! $this->hasProjectOverviewSection($content)
            || ! $this->hasClipPlanSection($content)
            || $clips === []) {
            return false;
        }
        foreach ($issues as $issue) {
            if (($issue['category'] ?? null) === 'duration') {
                return false;
            }
        }

        $durations = [];
        foreach ($clips as $index => $clip) {
            $number = (int) ($clip['number'] ?? 0);
            $duration = is_numeric($clip['duration_seconds'] ?? null)
                ? (float) $clip['duration_seconds']
                : $this->durationSeconds((string) ($clip['text'] ?? ''));
            if ($number !== $index + 1 || array_key_exists($number, $durations) || $duration === null || $duration <= 0) {
                return false;
            }
            $durations[$number] = $duration;
        }

        $overviewReferences = $plan?->overviewDurations ?? $this->overviewDurationReferences($content);
        $runtimeReferences = $plan?->overviewRuntimes ?? $this->overviewRuntimeReferences($content);
        $clipPlanReferences = $plan?->clipPlanDurations ?? $this->clipPlanDurationReferences($content);
        if ($overviewReferences === [] || $runtimeReferences === [] || $clipPlanReferences === []) {
            return false;
        }

        foreach ([...$overviewReferences, ...$clipPlanReferences] as $reference) {
            if (! array_key_exists($reference['clip'], $durations)
                || abs($reference['seconds'] - $durations[$reference['clip']]) > 0.01) {
                return false;
            }
        }
        foreach ($durations as $clipNumber => $duration) {
            foreach ([$overviewReferences, $clipPlanReferences] as $references) {
                if (! in_array($clipNumber, array_column($references, 'clip'), true)) {
                    return false;
                }
            }
        }

        $runtime = array_sum($durations);
        foreach ($runtimeReferences as $reference) {
            if (abs($reference['seconds'] - $runtime) > 0.01) {
                return false;
            }
        }

        return true;
    }

    /** @param array<int, array<string, mixed>> $clips @param array<int, array<string, mixed>> $issues */
    private function hasEvidenceForSpeechPacing(array $clips, array $input, array $issues): bool
    {
        if (! $this->spokenAudioEnabled($input)) {
            return false;
        }
        foreach ($issues as $issue) {
            if (($issue['category'] ?? null) === 'narration') {
                return false;
            }
        }

        $parsedRows = 0;
        foreach ($clips as $clip) {
            $clipText = (string) ($clip['text'] ?? '');
            $rows = is_array($clip['speech_rows'] ?? null) ? $clip['speech_rows'] : $this->speechRows($clipText);
            preg_match_all('/\\bLINE\\s*:/iu', $clipText, $rawLines);
            if (count($rows) !== count($rawLines[0] ?? [])) {
                return false;
            }
            $parsedRows += count($rows);
        }

        return $parsedRows > 0;
    }

    /** @param array<int, string> $lines @return array{start: int, end: int}|null */
    private function projectOverviewLineRange(array $lines): ?array
    {
        $start = null;
        $startLevel = null;
        foreach ($lines as $index => $line) {
            $heading = $this->normalizeHeading($line);
            if (preg_match('/^PROJECT OVERVIEW\\b/i', $heading) !== 1) {
                continue;
            }

            $start = $index;
            $startLevel = $this->headingLevel($line);
            break;
        }

        if ($start === null) {
            return null;
        }

        $end = count($lines) - 1;
        for ($index = $start + 1; $index < count($lines); $index++) {
            $plain = trim(str_replace(['**', '__', '`'], '', $lines[$index]));
            $headingText = preg_replace('/^\\s*#{1,6}\\s*/u', '', $plain) ?? $plain;
            if (preg_match('/^SECTION\\s+[2-9]\\d*\\b/iu', $headingText) === 1
                || preg_match('/^COPY CLIP\\s+\\d+\\s+PROMPT\\b/iu', $this->normalizeHeading($plain)) === 1) {
                $end = $index - 1;
                break;
            }

            $level = $this->headingLevel($lines[$index]);
            if ($level !== null && ($startLevel === null || $level <= $startLevel)) {
                $end = $index - 1;
                break;
            }
        }

        return ['start' => $start, 'end' => max($start, $end)];
    }

    /** @param array<int, string> $lines @return array{start: int, end: int}|null */
    private function finalContinuityAuditLineRange(array $lines): ?array
    {
        $start = null;
        $startLevel = null;
        foreach ($lines as $index => $line) {
            if (preg_match('/^FINAL CONTINUITY AUDIT\\b/i', $this->normalizeHeading($line)) !== 1) {
                continue;
            }

            $start = $index;
            $startLevel = $this->headingLevel($line);
            break;
        }

        if ($start === null) {
            return null;
        }

        $end = count($lines) - 1;
        for ($index = $start + 1; $index < count($lines); $index++) {
            $plain = trim(str_replace(['**', '__', '`'], '', $lines[$index]));
            if (preg_match('/^COPY CLIP\\s+\\d+\\s+PROMPT\\b/iu', $this->normalizeHeading($plain)) === 1) {
                $end = $index - 1;
                break;
            }

            $level = $this->headingLevel($lines[$index]);
            if ($level !== null && ($startLevel === null || $level <= $startLevel)) {
                $end = $index - 1;
                break;
            }
        }

        return ['start' => $start, 'end' => max($start, $end)];
    }

    /** @param array<int, string> $lines @return array{start: int, end: int}|null */
    private function clipPlanLineRange(array $lines): ?array
    {
        $start = null;
        $startLevel = null;
        foreach ($lines as $index => $line) {
            $heading = $this->normalizeHeading($line);
            if (preg_match('/^(?:CLIP\\s+PLAN(?:\\s+AND\\s+CONTINUITY\\s+MAP)?|SHOT\\s+LIST)\\b/i', $heading) !== 1) {
                continue;
            }

            $start = $index;
            $startLevel = $this->headingLevel($line);
            break;
        }

        if ($start === null) {
            return null;
        }

        $end = count($lines) - 1;
        for ($index = $start + 1; $index < count($lines); $index++) {
            $plain = trim(str_replace(['**', '__', '`'], '', $lines[$index]));
            $headingText = $this->normalizeHeading($plain);
            if (preg_match('/^COPY CLIP\\s+\\d+\\s+PROMPT\\b/iu', $headingText) === 1
                || preg_match('/^COPY[- ]AND[- ]PASTE\\s+VIDEO\\s+PROMPTS?\\b/iu', $headingText) === 1) {
                $end = $index - 1;
                break;
            }

            $level = $this->headingLevel($lines[$index]);
            if ($level !== null && ($startLevel === null || $level <= $startLevel)) {
                $end = $index - 1;
                break;
            }
        }

        return ['start' => $start, 'end' => max($start, $end)];
    }

    private function containsCompoundDuration(string $text): bool
    {
        return preg_match('/\\b\\d+(?:\\.\\d+)?\\s*(?:minutes?|mins?|m)\\s*(?:(?:,|and)\\s*)?\\d+(?:\\.\\d+)?\\s*(?:seconds?|secs?|s)\\b/iu', $text) === 1;
    }

    private function isDurationRangeValue(string $line, int $offset, string $value): bool
    {
        $afterValue = substr($line, $offset + strlen($value), 16);

        return preg_match('/^\\s*(?:[–—-]|to|and)\\s*\\d/u', $afterValue) === 1;
    }

    private function headingLevel(string $line): ?int
    {
        if (preg_match('/^\\s*(#{1,6})\\s*/u', $line, $match) === 1) {
            return strlen($match[1]);
        }
        if (preg_match('/^\\s*SECTION\\s+\\d+\\b/iu', $line) === 1) {
            return 1;
        }

        return null;
    }

    private function lineClaimsSynchronizedTiming(string $line): bool
    {
        $line = trim(str_replace(['**', '__', '`'], '', $line));
        if (preg_match('/\\b(?:not|never|cannot|can\\x27t|does\\s+not|do\\s+not|without|no)\\b[^.!?;]{0,45}\\b(?:synchroni[sz](?:ed|ation)|aligned|match(?:es)?|agree|consistent|sum(?:s)?\\s+to|equal(?:s)?|same|identical)\\b/iu', $line) === 1) {
            return false;
        }

        $synchronization = '(?:synchroni[sz](?:ed|ation)|align(?:ed|ment)?|match(?:es)?|agree|consistent|sum(?:s)?\\s+to|equal(?:s)?|is\\s+equal\\s+to|same|identical)';

        return preg_match('/\\b(?:(?:all|every|each)\\s+)?(?:(?:project\\s+)?(?:clip\\s+)?)?(?:timing|durations?|runtime|duration\\s+(?:representations?|references?|fields?|values?)|timing\\s+(?:representations?|references?|fields?|values?)|(?:overview|clip[ -]plan|shot[ -]list)[ -]+(?:durations?|timing))\\b[^.!?;]{0,100}\\b'.$synchronization.'\\b/iu', $line) === 1
            || preg_match('/\\b(?:all|every|each)\\s+(?:timing\\s+)?(?:representations?|references?|fields?|values?)\\b[^.!?;]{0,80}\\b'.$synchronization.'\\b/iu', $line) === 1
            || preg_match('/\\b(?:overview|clip[ -]plan|shot[ -]list)\\b[^.!?;]{0,80}\\b(?:duration|timing|runtime|total|totals|values?|references?)\\b[^.!?;]{0,80}\\b'.$synchronization.'\\b/iu', $line) === 1;
    }

    private function lineClaimsUniversalSpeechPacing(string $line): bool
    {
        $line = trim(str_replace(['**', '__', '`'], '', $line));
        if (preg_match('/\\b(?:not|never|cannot|can\\x27t|does\\s+not|do\\s+not|did\\s+not|without|no)\\b[^.!?;]{0,45}\\b(?:all|every|each|pacing|pace|timing|timed|paced|verified|confirmed|passed)\\b/iu', $line) === 1
            || preg_match('/\\b(?:not|never|no)\\s+univers(?:al|ally)\\b/iu', $line) === 1) {
            return false;
        }

        $pacingClaim = '(?:pacing|pace|timing|timed|paced|natural(?:ly)?|fit(?:s)?|within|respect(?:s)?|pass(?:es|ed)?|meet(?:s)?|satisf(?:y|ies)|speaking\\s+rate|words?\\s+per\\s+second|wps|wpm)';

        return preg_match('/\\b(?:all|every|each)\\b[^.!?;]{0,90}\\b(?:spoken\\s+)?(?:lines?|dialogue|narration|speech(?:\\s+rows?)?|utterances?)\\b[^.!?;]{0,100}\\b'.$pacingClaim.'\\b/iu', $line) === 1
            || preg_match('/\\b(?:pacing|speech\\s+timing|dialogue\\s+timing)\\b[^.!?;]{0,100}\\b(?:all|every|each)\\s+(?:spoken\\s+)?(?:lines?|dialogue|narration|speech|utterances?)\\b/iu', $line) === 1
            || preg_match('/\\b(?:all|every|each)\\s+(?:speech|dialogue|narration)\\b[^.!?;]{0,100}\\b'.$pacingClaim.'\\b/iu', $line) === 1
            || preg_match('/\\b(?:pacing|speech\\s+timing|dialogue\\s+timing)\\b[^.!?;]{0,80}\\b(?:passed|passes|verified|confirmed|correct|natural|appropriate|safe|within)\\b/iu', $line) === 1
            || preg_match('/\\bunivers(?:al|ally)\\b[^.!?;]{0,80}\\b(?:speech|dialogue|narration|lines?|pacing|pace|timing)\\b/iu', $line) === 1;
    }

    private function lineClaimsHandoffAgreement(string $line): bool
    {
        $line = trim(str_replace(['**', '__', '`'], '', $line));
        if (preg_match('/\b(?:not|never|no longer|unresolved|unverified|mismatch(?:ed)?)\b[^.!?;]{0,90}\b(?:match(?:es|ed)?|consistent|compatible|verified|confirmed|pass(?:es|ed)?|correct|seamless|continuous|aligned|plausible|feasible)\b/iu', $line) === 1
            || preg_match('/\b(?:not|never|no)\s+univers(?:al|ally)\b/iu', $line) === 1) {
            return false;
        }

        $handoff = '(?:hand[ -]?offs?|transitions?|clip[ -]to[ -]clip|continuity)';
        $positive = '(?:match(?:es|ed)?|consistent|compatible|verified|confirmed|pass(?:es|ed)?|correct|seamless|continuous|aligned|physically\s+plausible|feasible)';

        return preg_match('/\b(?:all|every|each|both|the\s+entire|all\s+\d+)\b[^.!?;]{0,90}\b'.$handoff.'\b[^.!?;]{0,90}\b'.$positive.'\b/iu', $line) === 1
            || preg_match('/\b'.$handoff.'\b[^.!?;]{0,60}\b(?:is|are|was|were|has\s+been|have\s+been)\b[^.!?;]{0,40}\b'.$positive.'\b/iu', $line) === 1;
    }

    private function lineClaimsExactStateCopy(string $line): bool
    {
        $line = trim(str_replace(['**', '__', '`'], '', $line));
        $line = preg_replace('/\\b(?:not|never|no longer|without)\\s+(?:(?:an?|the)\\s+)?(?:exact|verbatim)\\s+(?:(?:state|opening|ending)\\s+)?(?:cop(?:y|ies)|match(?:es)?)\\b/iu', '', $line) ?? $line;
        $line = preg_replace('/\\bno\\s+(?:(?:an?|the)\\s+)?(?:exact|verbatim)\\s+(?:(?:state|opening|ending)\\s+)?(?:cop(?:y|ies)|match(?:es)?)\\b/iu', '', $line) ?? $line;

        return preg_match('/\\b(?:exact|verbatim)\\s+(?:(?:state|opening|ending)\\s+)?cop(?:y|ies)\\b|\\b(?:exact|verbatim)\\s+(?:(?:state|opening|ending)\\s+)?match(?:es)?\\b|\\b(?:cop(?:y|ies|ied)|match(?:es|ed)?)\\s+exactly\\b|\\bexactly\\s+(?:cop(?:y|ies|ied)|match(?:es|ed)?)\\b/iu', $line) === 1;
    }

    private function transitionActionConnectsStates(?string $beforeState, ?string $afterState, string $action, bool $requireTemporalSequence): bool
    {
        if ($this->normalizeState($beforeState) === '' || $this->normalizeState($afterState) === '' || trim($action) === '') {
            return false;
        }
        if (preg_match('/\\b(?:a\\s+clear\\s+cause|one\\s+visible\\s+action|result\\s+is\\s+clear)\\b/iu', $action) === 1
            || preg_match('/\\b(?:time[ -]?lapse|move|carry|lift|set|place|walk|travel|transfer|roll|push|pull|slide|open|close|turn|flow|melt|heat|cool|form|shape|compress|assemble|transform|jump|cut|dissolve|fade|advance|accelerate|change|shift|rotate|continue|arrive|leave|pass|bridge|transition)(?:s|ed|ing)?\\b/iu', $action) !== 1) {
            return false;
        }

        $beforeTokens = $this->stateTokens((string) $beforeState);
        $afterTokens = $this->stateTokens((string) $afterState);
        $actionTokens = $this->stateTokens($action);
        if (array_intersect($actionTokens, [...$beforeTokens, ...$afterTokens]) === []) {
            return false;
        }
        if (! $requireTemporalSequence) {
            return true;
        }

        $beforeEvidence = array_diff($beforeTokens, $afterTokens) ?: $beforeTokens;
        $afterEvidence = array_diff($afterTokens, $beforeTokens) ?: $afterTokens;
        $mentionsBefore = array_intersect($actionTokens, $beforeEvidence) !== [];
        $mentionsAfter = array_intersect($actionTokens, $afterEvidence) !== [];
        $hasBeforeMarker = preg_match('/\\b(?:before(?:\\s+state)?|initially|at\\s+the\\s+start|starts?\\s+(?:as|with)|from)\\b/iu', $action) === 1;
        $hasTransitionMarker = preg_match('/\\b(?:time[ -]?lapse|transition|dissolve|cut(?:s)?\\s+to|fade(?:s)?|jump\\s+forward|over\\s+time|as\\s+time\\s+passes|advances?)\\b/iu', $action) === 1;
        $hasAfterMarker = preg_match('/\\b(?:after(?:\\s+state)?|later|afterwards|eventually|ends?\\s+(?:as|with)|becomes?|resulting\\s+in|final(?:ly)?)\\b/iu', $action) === 1;

        return $mentionsBefore && $mentionsAfter && $hasBeforeMarker && $hasTransitionMarker && $hasAfterMarker;
    }

    private function compareStates(?string $left, ?string $right): string
    {
        $leftState = $this->normalizeState($left);
        $rightState = $this->normalizeState($right);
        if ($leftState === '' || $rightState === '') {
            return 'unverified';
        }
        if ($leftState === $rightState) {
            return 'exact';
        }

        $leftMap = $this->stateMap($leftState);
        $rightMap = $this->stateMap($rightState);
        if ($leftMap !== null && $rightMap !== null) {
            $sharedKeys = array_intersect(array_keys($leftMap), array_keys($rightMap));
            if ($sharedKeys !== []) {
                foreach ($sharedKeys as $key) {
                    if ($this->hasOpposingStateTerms($leftMap[$key], $rightMap[$key])
                        || ! $this->stateTextsAreCompatible($leftMap[$key], $rightMap[$key])) {
                        return 'mismatch';
                    }
                }

                return 'compatible';
            }
        }

        if ($this->hasOpposingStateTerms($leftState, $rightState)) {
            return 'mismatch';
        }

        return $this->stateTextsAreCompatible($leftState, $rightState) ? 'compatible' : 'mismatch';
    }

    private function compareWrittenStates(?string $left, ?string $right): string
    {
        $leftState = $this->normalizeState($left);
        $rightState = $this->normalizeState($right);
        if ($leftState === '' || $rightState === '') {
            return 'unverified';
        }
        if ($leftState === $rightState) {
            return 'exact';
        }
        if ($this->hasOpposingStateTerms($leftState, $rightState)) {
            return 'mismatch';
        }

        return $this->stateTextsAreCompatible($leftState, $rightState) ? 'compatible' : 'unverified';
    }

    private function stateMap(string $state): ?array
    {
        $parts = explode(';', $state);
        $values = [];
        foreach ($parts as $part) {
            if (preg_match('/^\\s*([^=;]+?)\\s*=\\s*(.*?)\\s*$/u', $part, $match) !== 1 || trim($match[2]) === '') {
                return null;
            }
            $key = strtolower(preg_replace('/\\s+/u', ' ', trim($match[1])) ?? trim($match[1]));
            $value = preg_replace('/\\s+/u', ' ', trim($match[2])) ?? trim($match[2]);
            if ($key === '' || array_key_exists($key, $values)) {
                return null;
            }
            $values[$key] = $value;
        }

        return $values === [] ? null : $values;
    }

    private function stateTextsAreCompatible(string $left, string $right, bool $checkOpposites = true): bool
    {
        if ($this->normalizeState($left) === $this->normalizeState($right)) {
            return true;
        }
        if ($checkOpposites && $this->hasOpposingStateTerms($left, $right)) {
            return false;
        }

        $leftTokens = $this->stateTokens($left);
        $rightTokens = $this->stateTokens($right);
        if ($leftTokens === [] || $rightTokens === []) {
            return false;
        }

        $overlap = count(array_intersect($leftTokens, $rightTokens));
        $smallest = min(count($leftTokens), count($rightTokens));

        return $smallest > 0 && ($overlap / $smallest) >= 0.7;
    }

    /** @return array<int, string> */
    private function stateTokens(string $text): array
    {
        $map = $this->stateMap($text);
        if ($map !== null) {
            $text = implode(' ', array_values($map));
        }

        $text = strtolower($text);
        $text = preg_replace('/\\b(?:not|never|no longer)\\s+(?:moving|in motion)\\b/u', 'stationary', $text) ?? $text;
        $text = preg_replace('/\\b(?:not|never)\\s+(?:closed|shut|sealed)\\b/u', 'open', $text) ?? $text;
        $text = preg_replace('/\\b(?:not|never)\\s+(?:open|opened)\\b/u', 'closed', $text) ?? $text;
        $text = preg_replace('/\\b(?:motionless|still|at rest)\\b/u', 'stationary', $text) ?? $text;
        $text = preg_replace('/\\b(?:in motion|rolling)\\b/u', 'moving', $text) ?? $text;
        $text = preg_replace('/\\b(?:shut|sealed)\\b/u', 'closed', $text) ?? $text;
        $text = preg_replace('/\\b(?:opened|unsealed)\\b/u', 'open', $text) ?? $text;
        $text = preg_replace('/\\b(?:filled)\\b/u', 'full', $text) ?? $text;
        $text = preg_replace('/\\b(?:vacant)\\b/u', 'empty', $text) ?? $text;

        preg_match_all('/[\\p{L}\\p{N}]+/u', $text, $matches);
        $tokens = array_values(array_unique(array_filter(
            $matches[0] ?? [],
            static fn (string $token): bool => ! in_array($token, self::STATE_STOP_WORDS, true),
        )));

        return $tokens;
    }

    private function hasOpposingStateTerms(string $left, string $right): bool
    {
        foreach (self::OPPOSING_STATE_GROUPS as $pair) {
            $leftFirst = $this->containsAnyStateTerm($left, $pair[0]);
            $leftSecond = $this->containsAnyStateTerm($left, $pair[1]);
            $rightFirst = $this->containsAnyStateTerm($right, $pair[0]);
            $rightSecond = $this->containsAnyStateTerm($right, $pair[1]);

            if (($leftFirst && $rightSecond && ! $rightFirst)
                || ($leftSecond && $rightFirst && ! $rightSecond)) {
                return true;
            }
        }

        return false;
    }

    /** @param array<int, string> $terms */
    private function containsAnyStateTerm(string $text, array $terms): bool
    {
        foreach ($terms as $term) {
            if ($this->containsStateTerm($text, $term)) {
                return true;
            }
        }

        return false;
    }

    private function containsStateTerm(string $text, string $term): bool
    {
        $pattern = '/(?<![\\p{L}\\p{N}])'.preg_quote($term, '/').'(?![\\p{L}\\p{N}])/iu';
        if (preg_match_all($pattern, $text, $matches, PREG_OFFSET_CAPTURE) === 0) {
            return false;
        }

        foreach ($matches[0] as $match) {
            $prefix = substr($text, max(0, $match[1] - 18), min(18, $match[1]));
            if (preg_match('/\\b(?:not|never|without|no longer)\\s*$/iu', $prefix) !== 1) {
                return true;
            }
        }

        return false;
    }

    private function formatSeconds(float $seconds): string
    {
        if (abs($seconds - round($seconds)) < 0.0001) {
            return (string) (int) round($seconds);
        }

        return rtrim(rtrim(number_format($seconds, 3, '.', ''), '0'), '.');
    }

    private function normalizeHeading(string $line): string
    {
        $line = trim(str_replace(['**', '__', '`'], '', $line));
        $line = preg_replace('/^\s*#{1,6}\s*/u', '', $line) ?? $line;
        $line = preg_replace('/^\s*>\s*/u', '', $line) ?? $line;
        $line = preg_replace('/^\s*SECTION\s+\d+\+?(?:\s*[—–:-]\s*|\s+)/iu', '', $line) ?? $line;
        return trim($line);
    }

    private function spokenAudioEnabled(array $input): bool
    {
        return ! in_array(strtolower((string) ($input['narration'] ?? '1')), ['0', 'false', 'off', 'none'], true);
    }

    private function wordCount(string $line): int
    {
        $line = preg_replace('/^\s*(?:\[[^\]]+\]|\([^\)]+\))\s*/u', '', $line) ?? $line;
        preg_match_all('/[\p{L}\p{N}]+(?:[\x27’-][\p{L}\p{N}]+)*/u', $line, $matches);

        return count($matches[0] ?? []);
    }

    private function sentencePauseCount(string $line): int
    {
        preg_match_all('/[.!?…](?:\s|$)/u', trim($line), $matches);

        return count($matches[0] ?? []);
    }

    public function minimumSpeechSeconds(string $line, array $input = []): float
    {
        $wordCount = $this->wordCount($line);
        $sentencePauses = $this->sentencePauseCount($line);
        $estimate = $this->speechEstimatePolicy($input);
        $wordsPerSecond = $estimate['words_per_second'];
        preg_match_all('/\b[\p{L}]{12,}\b/u', $line, $technicalWords);
        $pronunciation = count($technicalWords[0] ?? []) * $estimate['technical_word_extra_seconds'];
        $pauseMinimum = $estimate['sentence_pause_min_seconds'];
        $pausePerSentence = $estimate['sentence_pause_per_sentence_seconds'];
        $pauseMaximum = $estimate['sentence_pause_max_seconds'];
        $sentencePause = max($pauseMinimum, min($pauseMaximum, $sentencePauses * $pausePerSentence));

        return ($wordCount / $wordsPerSecond) + $sentencePause + $pronunciation;
    }

    /** One source for both first-pass planning constants and QA's text estimates. */
    public function speechEstimatePolicy(array $input = []): array
    {
        $rate = max(0.1, $this->speechPolicy('words_per_second', 2.5));
        if (preg_match('/\b(preschool|toddler|early learner|young children)\b/i', (string) ($input['audience'] ?? '')) === 1) {
            $rate = max(0.1, min($rate, $this->speechPolicy('young_audience_words_per_second', 2.2)));
        }
        $minimum = $this->speechPolicy('sentence_pause_min_seconds', 0.12);

        return [
            'words_per_second' => $rate,
            'sentence_pause_min_seconds' => $minimum,
            'sentence_pause_per_sentence_seconds' => $this->speechPolicy('sentence_pause_per_sentence_seconds', 0.12),
            'sentence_pause_max_seconds' => max($minimum, $this->speechPolicy('sentence_pause_max_seconds', 0.45)),
            'technical_word_extra_seconds' => $this->speechPolicy('technical_word_extra_seconds', 0.15),
        ];
    }

    /** Conservative text estimate; not measured TTS or rendered speech. */
    public function speechBudget(array $rows, ?float $duration, array $policy, array $input = []): array
    {
        $overhead = $rows === [] ? 0.0 : $policy['opening_visual_beat_seconds']
            + $policy['ending_pause_seconds'] + max(0, count($rows) - 1) * $policy['inter_line_pause_seconds'];
        $required = array_sum(array_map(fn (array $row): float => $this->minimumSpeechSeconds($row['line'], $input), $rows));
        $available = $duration === null ? null : max(0.0, $duration - $overhead);

        return ['available_seconds' => $available, 'required_seconds' => $required,
            'visual_and_pause_seconds' => $overhead,
            'fits' => $available === null ? null : $required <= $available + $policy['timing_tolerance_seconds']];
    }

    /** Unaffected clip bodies must survive in order, even if a safe split changes numbering. */
    public function preservesUnaffectedClips(string $before, string $after, array $input, array $issues): bool
    {
        $affected = [];
        foreach ($issues as $issue) {
            if (is_numeric($issue['clip'] ?? null)) {
                $number = (int) $issue['clip'];
                foreach ([$number - 1, $number, $number + 1] as $neighbor) {
                    $affected[$neighbor] = true;
                }
            }
        }
        $candidates = $this->extractClips($after, $input);
        $cursor = 0;
        foreach ($this->extractClips($before, $input) as $clip) {
            if (isset($affected[$clip['number']])) {
                // Consume at least its original slot; do not match a later protected clip to an earlier duplicate.
                $cursor++;
                continue;
            }
            $found = false;
            while (isset($candidates[$cursor])) {
                if (trim($candidates[$cursor++]['text']) === trim($clip['text'])) {
                    $found = true;
                    break;
                }
            }
            if (! $found) {
                return false;
            }
        }

        return true;
    }

    /** Preserve spoken text and speaker order, allowing only redistribution between clips/rows. */
    public function preservesSpokenContent(string $before, string $after, array $input): bool
    {
        $signature = function (string $text) use ($input): ?array {
            $runs = [];
            foreach ($this->canonicalProjectPlan($text, $input)->clips as $clip) {
                preg_match_all('/\bLINE\s*:/iu', str_replace(['**', '__', '`'], '', $clip['text']), $markers);
                if (count($markers[0]) !== count($clip['speech_rows'])) {
                    return null;
                }
                foreach ($clip['speech_rows'] as $row) {
                    $unquoted = preg_replace('/\A["“‘]|["”’]\z/u', '', trim($row['line'])) ?? $row['line'];
                    $words = preg_replace('/\s+/u', ' ', $unquoted);
                    $last = array_key_last($runs);
                    if ($last !== null && $runs[$last]['speaker'] === $row['speaker']) {
                        $runs[$last]['text'] .= ' '.$words;
                    } else {
                        $runs[] = ['speaker' => $row['speaker'], 'text' => $words];
                    }
                }
            }

            return $runs;
        };
        $original = $signature($before);
        $candidate = $signature($after);

        return $original !== null && $candidate !== null && ($original === [] || $original === $candidate);
    }

    public function speechTimingPolicy(string $clipText = ''): array
    {
        $outgoingType = $this->outgoingType($clipText);
        $endingPause = $this->speechPolicy('ending_pause_seconds', 0.3);
        if (in_array($outgoingType, ['A', 'B', 'C'], true)) {
            $endingPause = max($endingPause, $this->speechPolicy('handoff_closing_pause_seconds', 0.3));
        }

        return [
            'opening_visual_beat_seconds' => max(0.2, $this->speechPolicy('opening_visual_beat_seconds', 0.2)),
            'inter_line_pause_seconds' => max(0.2, $this->speechPolicy('inter_line_pause_seconds', 0.2)),
            'ending_pause_seconds' => max(0.3, $endingPause),
            'timing_tolerance_seconds' => $this->speechPolicy('timing_tolerance_seconds', 0.0001),
        ];
    }

    private function speechPolicy(string $key, float $default): float
    {
        if (! function_exists('config')) {
            return $default;
        }

        $value = config('prompt_qa.speech.'.$key, $default);

        return is_numeric($value) ? max(0.0, (float) $value) : $default;
    }

    private function normalizeState(?string $state): string
    {
        if ($state === null) {
            return '';
        }

        return preg_replace('/\s+/u', ' ', trim($state)) ?? trim($state);
    }

    /** @param array<int, array<string, mixed>> $issues */
    private function buildReview(array $issues, array $input, bool $hasStructuredPrompts, bool $isExtension, array $handoffs = []): array
    {
        $issueCategories = [];
        foreach ($issues as $issue) {
            $issueCategories[$issue['category']] = true;
        }

        $check = static function (string $id, string $label, string $category, string $detail) use ($issueCategories, $hasStructuredPrompts): array {
            $status = ! $hasStructuredPrompts
                ? ($category === 'structure' && isset($issueCategories[$category]) ? 'warning' : 'not_run')
                : (isset($issueCategories[$category]) ? 'warning' : 'passed');

            return [
                'id' => $id,
                'label' => $label,
                'status' => $status,
                'detail' => ! $hasStructuredPrompts && $category === 'structure'
                    ? 'No copy-ready prompt block was parsed; clip-level structure, timing, and handoff checks could not be run.'
                    : $detail,
            ];
        };

        $checks = [
            $check('structure', 'Prompt structure and selected settings', 'structure', $isExtension
                ? 'One copy-ready extension prompt is parsed; clip-count checks do not apply.'
                : 'Clip blocks, numbering, and any fixed manual clip count were checked.'),
            $check('duration', 'Clip limits and timing representations', 'duration', 'Copy-ready clip durations are checked against saved and explicit project-declared limits. When a project overview or clip plan / shot list is present, its parseable per-clip values are compared with copy-ready clips; a stated overview total is checked against their sum.'),
            $check('handoff', 'Type A/B/C handoff labels', 'handoff', $isExtension
                ? 'Multi-clip handoff checks do not apply to an extension prompt.'
                : 'Adjacent outgoing/incoming labels and the final marker are compared.'),
            $check('continuity', 'Structured and written opening/ending states', 'continuity', 'Structured handoffs are classified as exact or compatible; compatible Type B states are not reported as verbatim copies. Exact-frame prose is checked for explicit contradictions and unsupported exact-copy claims.'),
            $check('visual', 'Primary idea and visual representation', 'visual', 'Required clarity fields and representation labels are checked for presence.'),
            $check('narration', 'Speech-map timing estimate', 'narration', $this->spokenAudioEnabled($input)
                ? 'Clip-relative ranges, overlaps, duration bounds, and conservative word-time estimates are checked.'
                : 'Spoken audio is off; the explicit no-speech map is checked.'),
        ];

        $factSensitive = in_array((string) ($input['video_type'] ?? 'educational'), ['educational', 'documentary'], true);
        $checks[] = [
            'id' => 'factual_accuracy',
            'label' => 'Factual and chronological accuracy',
            'status' => $factSensitive ? 'human_review' : 'not_applicable',
            'detail' => $factSensitive
                ? 'Not independently verified: this endpoint does not retrieve research sources. Check important claims against reliable sources; simplified or disputed claims should be labeled.'
                : 'No factual claims were independently researched by this text-only audit.',
        ];
        $checks[] = [
            'id' => 'rendered_video',
            'label' => 'Physical plausibility and rendered visuals',
            'status' => 'human_review',
            'detail' => 'Prompt text cannot prove physical feasibility, same-clip visual/narration alignment, or what a video model actually rendered. Inspect generated frames and review causal steps.',
        ];

        $limitations = [
            'No external research sources were retrieved by this generation endpoint; do not treat the prompt as independently fact-checked.',
            'Automatic checks are structural and timing estimates. Human/source review is still needed for semantic factual correctness, physical plausibility, visual clarity, and actual generated video.',
            'Word-time estimates use a conservative average; language, accent, pronunciation, delivery, and pauses can change real speaking time.',
        ];

        return [
            'status' => ! $hasStructuredPrompts ? 'not_checked' : ($issues === [] ? 'passed' : 'needs_review'),
            'corrected' => false,
            'repair_attempted' => false,
            'checks' => $checks,
            'issues' => array_values($issues),
            'handoffs' => array_values($handoffs),
            'limitations' => $limitations,
        ];
    }

    /** @param array<int, array<string, mixed>> $issues */
    private function addIssue(array &$issues, string $code, string $category, ?int $clip, string $message, bool $repairable = true): void
    {
        foreach ($issues as $existing) {
            if ($existing['code'] === $code && $existing['clip'] === $clip && $existing['message'] === $message) {
                return;
            }
        }

        $issues[] = [
            'code' => $code,
            'category' => $category,
            'clip' => $clip,
            'message' => $message,
            'repairable' => $repairable,
        ];
    }
}
