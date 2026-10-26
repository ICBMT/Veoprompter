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
        $clips = $this->extractClips($content, $input);

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
            $details[] = $this->auditClip($clip, $input, $issues, $spokenAudioEnabled, $isExtension);
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
                    if ($this->normalizeState($endingState) !== ''
                        && $this->normalizeState($openingState) !== ''
                        && $stateRelation !== 'exact'
                        && ! ($outgoing === 'B' && $stateRelation === 'compatible')) {
                        $this->addIssue(
                            $issues,
                            'continuity_state_mismatch',
                            'continuity',
                            $nextClipNumber,
                            'Clip '.$nextClipNumber.' opening scene state does not exactly match Clip '.$clipNumber.' ending state. Review identity, objects/materials, process stage, environment, position, condition, and motion; show any change inside a Type B/C clip.',
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

                    $overallRelation = $stateRelation;
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

            $this->auditProjectOverview($content, $clips, $details, $issues);
            $this->auditFinalContinuityClaims($content, $handoffs, $issues);
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
    private function auditClip(array $clip, array $input, array &$issues, bool $spokenAudioEnabled, bool $isExtension): array
    {
        $text = $clip['text'];
        $number = $clip['number'];
        $duration = $this->durationSeconds($text);

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

            $durationChoice = $input['clip_duration'] ?? ProjectSettings::AI_CHOICE;
            if (ProjectSettings::isAiChoice($durationChoice)) {
                if ($duration > 10) {
                    $this->addIssue(
                        $issues,
                        'ai_duration_over_limit',
                        'duration',
                        $number,
                        'AI-selected Clip '.$number.' is '.$duration.' seconds; AI-selected durations must not exceed 10 seconds.',
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
        $values = [];
        foreach ($requiredFields as $field => $category) {
            $values[$field] = $this->fieldValue($text, $field);
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

        $exactOpeningFrame = $this->fieldValue($text, 'EXACT OPENING FRAME');
        $exactEndingState = $this->fieldValue($text, 'EXACT ENDING STATE');
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

        $incomingType = $isExtension ? null : $this->incomingType($text);
        $outgoingType = $isExtension ? null : $this->outgoingType($text);
        $mapMarker = $values['SPEECH/VISUAL MAP'] ?? null;
        $speechRows = $this->speechRows($text);
        if ($speechRows !== []) {
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
                }
                $previousEnd = max($previousEnd ?? 0, $row['end']);

                $wordCount = $this->wordCount($row['line']);
                $minimumSeconds = $this->minimumSpeechSeconds($row['line']);
                $availableSeconds = max(0, $row['end'] - $row['start']);
                if ($wordCount > 0 && $availableSeconds + 0.05 < $minimumSeconds) {
                    $this->addIssue(
                        $issues,
                        'speech_overrun',
                        'narration',
                        $number,
                        'Clip '.$number.' speech row '.($rowIndex + 1).' has '.$wordCount.' words in '.round($availableSeconds, 1).' seconds; allow about '.round($minimumSeconds, 1).' seconds at a natural pace plus any pronunciation/comprehension needs. Shorten it or allocate more AI-selected time; never rush.',
                    );
                }
            }

            $lastSpeechEnd = $speechRows[array_key_last($speechRows)]['end'];
            if ($duration !== null && $duration - $lastSpeechEnd < 0.25) {
                $this->addIssue(
                    $issues,
                    'speech_leaves_no_visual_tail',
                    'narration',
                    $number,
                    'Clip '.$number.' leaves no settled visual beat after the final spoken line; reserve a brief pause at the ending when the action allows.',
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

    /** @return array<int, array{start: float, end: float, action: string, speaker: string, line: string}> */
    private function speechRows(string $text): array
    {
        $rows = [];
        foreach (preg_split('/\R/u', $text) ?: [] as $line) {
            $line = str_replace(['**', '__', '`'], '', $line);
            if (preg_match('/^\s*(?:[-*+•–]\s+)?(-?\d+(?:\.\d+)?)\s*(?:s)?\s*(?:–|—|-|to)\s*(-?\d+(?:\.\d+)?)\s*s\s*\|\s*VISIBLE ACTION:\s*(.*?)\s*\|\s*SPEAKER:\s*(.*?)\s*\|\s*LINE:\s*(.+?)\s*$/iu', $line, $match) !== 1) {
                continue;
            }

            $rows[] = [
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

        $references = [];
        for ($lineIndex = $range['start']; $lineIndex <= $range['end']; $lineIndex++) {
            $line = $lines[$lineIndex] ?? '';
            preg_match_all(
                '/\\b(?:approx(?:imate(?:ly)?)?\\.?\\s+|estimated\\s+|about\\s+)?total\\s+(?:runtime|run\\s*time|duration)\\b[^\\d\\r\\n]{0,36}?(\\d+(?:\\.\\d+)?)\\s*(seconds?|secs?|s|minutes?|mins?|m)\\b/iu',
                $line,
                $matches,
                PREG_SET_ORDER | PREG_OFFSET_CAPTURE,
            );

            foreach ($matches as $match) {
                $value = $match[1][0];
                $offset = $match[1][1];
                $afterValue = substr($line, $offset + strlen($value), 12);
                if (preg_match('/^\\s*[–—-]\\s*\\d/u', $afterValue) === 1) {
                    continue;
                }

                $unit = strtolower($match[2][0]);
                $numeric = (float) $value;
                $references[] = [
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
        $lines = preg_split('/\R/u', str_replace("\r", '', $content)) ?: [];
        $range = $this->finalContinuityAuditLineRange($lines);
        if ($range === null) {
            return [];
        }

        $indexes = [];
        for ($lineIndex = $range['start']; $lineIndex <= $range['end']; $lineIndex++) {
            if ($this->lineClaimsExactStateCopy($lines[$lineIndex] ?? '')) {
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
    private function auditProjectOverview(string $content, array $clips, array $details, array &$issues): void
    {
        $sourceLines = preg_split('/\\R/u', str_replace("\\r", '', $content)) ?: [];
        if ($this->projectOverviewLineRange($sourceLines) === null) {
            return;
        }

        $references = $this->overviewDurationReferences($content);
        $runtimeReferences = $this->overviewRuntimeReferences($content);
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

    /** @param array<int, array<string, mixed>> $handoffs @param array<int, array<string, mixed>> $issues */
    private function auditFinalContinuityClaims(string $content, array $handoffs, array &$issues): void
    {
        $hasNonExactHandoff = false;
        foreach ($handoffs as $handoff) {
            if (($handoff['overall_relation'] ?? null) !== 'exact') {
                $hasNonExactHandoff = true;
                break;
            }
        }

        if ($hasNonExactHandoff && $this->continuityAuditClaimLineIndexes($content) !== []) {
            $this->addIssue(
                $issues,
                'continuity_audit_overclaim',
                'continuity',
                null,
                'The final continuity audit claims exact state copies even though at least one handoff is not verified as an exact structured and written match.',
            );
        }
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

    private function lineClaimsExactStateCopy(string $line): bool
    {
        $line = trim(str_replace(['**', '__', '`'], '', $line));
        $line = preg_replace('/\\b(?:not|never|no longer|without)\\s+(?:(?:an?|the)\\s+)?(?:exact|verbatim)\\s+(?:(?:state|opening|ending)\\s+)?(?:cop(?:y|ies)|match(?:es)?)\\b/iu', '', $line) ?? $line;

        return preg_match('/\\b(?:exact|verbatim)\\s+(?:(?:state|opening|ending)\\s+)?cop(?:y|ies)\\b|\\b(?:exact|verbatim)\\s+(?:(?:state|opening|ending)\\s+)?match(?:es)?\\b|\\b(?:cop(?:y|ies|ied)|match(?:es|ed)?)\\s+exactly\\b|\\bexactly\\s+(?:cop(?:y|ies|ied)|match(?:es|ed)?)\\b/iu', $line) === 1;
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

    public function minimumSpeechSeconds(string $line): float
    {
        $wordCount = $this->wordCount($line);
        $sentencePauses = $this->sentencePauseCount($line);

        return ($wordCount / 2.2) + max(0.12, min(0.45, $sentencePauses * 0.12));
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
            $check('duration', 'Clip duration limits and project-overview runtime', 'duration', ProjectSettings::isAiChoice($input['clip_duration'] ?? ProjectSettings::AI_CHOICE)
                ? 'AI-selected whole-second limits are checked, numbered overview durations are compared with each copy-ready clip, and a stated total runtime is checked against their sum.'
                : 'Each clip is checked against the selected fixed duration; numbered overview durations and a stated total runtime are compared with the final clip values.'),
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
