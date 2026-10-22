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
    /**
     * @return array{
     *     status: string,
     *     corrected: bool,
     *     repair_attempted: bool,
     *     checks: array<int, array{id: string, label: string, status: string, detail: string}>,
     *     issues: array<int, array{code: string, category: string, clip: int|null, message: string, repairable: bool}>,
     *     limitations: array<int, string>
     * }
     */
    public function audit(string $content, array $input): array
    {
        $isExtension = ($input['mode'] ?? 'story') === 'extend';
        $clips = $isExtension
            ? $this->extractExtensionPrompt($content)
            : $this->extractStoryClips($content);

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

                    $endingState = $this->normalizeState($details[$index]['ending_state']);
                    $openingState = $this->normalizeState($next['opening_state']);
                    if ($endingState !== '' && $openingState !== '' && $endingState !== $openingState) {
                        $this->addIssue(
                            $issues,
                            'continuity_state_mismatch',
                            'continuity',
                            $nextClipNumber,
                            'Clip '.$nextClipNumber.' opening scene state does not exactly match Clip '.$clipNumber.' ending state. Review identity, objects/materials, process stage, environment, position, condition, and motion; show any change inside a Type B/C clip.',
                        );
                    }

                    if ($outgoing === 'A') {
                        $endingPresentation = $this->normalizeState($details[$index]['ending_presentation']);
                        $openingPresentation = $this->normalizeState($next['opening_presentation']);
                        if ($endingPresentation !== '' && $openingPresentation !== '' && $endingPresentation !== $openingPresentation) {
                            $this->addIssue(
                                $issues,
                                'presentation_state_mismatch',
                                'continuity',
                                $nextClipNumber,
                                'Type A requires an identical camera, lighting, and audible presentation state at the cut; Clip '.$nextClipNumber.' does not match Clip '.$clipNumber.'.',
                            );
                        }
                    }
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
        }

        return $this->buildReview($issues, $input, true, $isExtension);
    }

    /** @return array<int, array{number: int, text: string}> */
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
            $blocks[] = ['number' => $heading['number'], 'text' => implode("\n", $body)];
        }

        return $blocks;
    }

    /** @return array<int, array{number: int, text: string}> */
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

            return [['number' => 1, 'text' => implode("\n", $body)]];
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
                $sentencePauses = $this->sentencePauseCount($row['line']);
                $minimumSeconds = ($wordCount / 2.2) + max(0.12, min(0.45, $sentencePauses * 0.12));
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
            'duration' => $duration,
        ];
    }

    /** @return array<int, array{start: float, end: float, action: string, speaker: string, line: string}> */
    private function speechRows(string $text): array
    {
        $rows = [];
        foreach (preg_split('/\R/u', $text) ?: [] as $line) {
            $line = str_replace(['**', '__', '`'], '', $line);
            if (preg_match('/^\s*(?:[-*+•–]\s*)?(\d+(?:\.\d+)?)\s*(?:s)?\s*(?:–|—|-|to)\s*(\d+(?:\.\d+)?)\s*s\s*\|\s*VISIBLE ACTION:\s*(.*?)\s*\|\s*SPEAKER:\s*(.*?)\s*\|\s*LINE:\s*(.+?)\s*$/iu', $line, $match) !== 1) {
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

    private function durationSeconds(string $text): ?float
    {
        $value = $this->fieldValue($text, 'CLIP DURATION');
        if ($value === null || preg_match('/^\s*(\d+(?:\.\d+)?)\s*(?:seconds?|secs?|s)\s*$/i', $value, $match) !== 1) {
            return null;
        }

        return (float) $match[1];
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

    private function fieldValue(string $text, string $field): ?string
    {
        foreach (preg_split('/\R/u', $text) ?: [] as $line) {
            $line = str_replace(['**', '__', '`'], '', $line);
            if (preg_match('/^\s*(?:[-*+>]\s*)?'.preg_quote($field, '/').'\s*:\s*(.*?)\s*$/iu', $line, $match) === 1) {
                return trim($match[1]);
            }
        }

        return null;
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

    private function normalizeState(?string $state): string
    {
        if ($state === null) {
            return '';
        }

        return preg_replace('/\s+/u', ' ', trim($state)) ?? trim($state);
    }

    /** @param array<int, array<string, mixed>> $issues */
    private function buildReview(array $issues, array $input, bool $hasStructuredPrompts, bool $isExtension): array
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
            $check('duration', 'Clip duration limits', 'duration', ProjectSettings::isAiChoice($input['clip_duration'] ?? ProjectSettings::AI_CHOICE)
                ? 'AI-selected whole-second durations are checked against the 10-second maximum.'
                : 'Each clip duration is checked against the selected fixed duration.'),
            $check('handoff', 'Type A/B/C handoff labels', 'handoff', $isExtension
                ? 'Multi-clip handoff checks do not apply to an extension prompt.'
                : 'Adjacent outgoing/incoming labels and the final marker are compared.'),
            $check('continuity', 'Structured opening/ending states', 'continuity', 'Exact state labels are compared; Type A presentation-state strings are also matched.'),
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
