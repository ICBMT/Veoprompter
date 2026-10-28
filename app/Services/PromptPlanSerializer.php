<?php

namespace App\Services;

/** Expand reserved references from authoritative clip fields; never infer or repair scene facts. */
final class PromptPlanSerializer
{
    public const TOKEN_PATTERN = '/\{\{VEO_[^{}\r\n]*\}\}/';

    public function __construct(private readonly PromptOutputAuditor $auditor) {}

    /** @return array{content: string, expanded: int, template_sha256: string} */
    public function serialize(string $template, array $input): array
    {
        $lines = preg_split('/\R/u', $template) ?: [];
        $plan = $this->auditor->canonicalProjectPlan($template, $input);
        $clips = $plan->clips;
        $identities = [];
        $prefixEnd = $clips[0]['heading_line'] ?? $clips[0]['start_line'] ?? count($lines);
        foreach (array_slice($lines, 0, $prefixEnd) as $line) {
            if (preg_match('/^\s*(?:[-*+>]\s*)?CANONICAL IDENTITY ([A-Za-z]+-\d+)\s*:\s*(.+)$/iu', str_replace(['**', '__', '`'], '', $line), $match)) {
                $id = strtoupper($match[1]);
                if (isset($identities[$id])) {
                    throw new PromptGenerationException('The provider supplied an ambiguous canonical identity; no template was accepted.');
                }
                $identities[$id] = trim($match[2]);
            }
        }
        $durations = $plan->clipDurations();
        $expanded = 0;
        foreach ($lines as $index => &$line) {
            // Protected spoken words, including literal token-looking words, are never interpolated.
            $parts = preg_split('/(\|\s*LINE\s*:)/iu', $line, 2, PREG_SPLIT_DELIM_CAPTURE);
            $editable = $parts[0];
            $owner = null;
            foreach ($clips as $position => $clip) {
                if ($index >= $clip['start_line'] && $index < $clip['end_line']) {
                    $owner = $position;
                    break;
                }
            }
            $editable = preg_replace_callback(self::TOKEN_PATTERN, function (array $match) use ($index, $owner, $clips, $plan, $durations, $identities, $line, &$expanded): string {
                $token = $match[0];
                $replacement = null;
                $field = fn (string $name): bool => preg_match('/^\s*(?:[-*+>]\s*)?'.preg_quote($name, '/').'\s*:\s*'.preg_quote($token, '/').'\s*$/iu', str_replace(['**', '__', '`'], '', $line)) === 1;
                if (preg_match('/^\{\{VEO_CLIP_SECONDS:(\d+)\}\}$/', $token, $number)) {
                    // Copy-ready duration fields are sources, never self-referential expressions.
                    if (! $field('CLIP DURATION') && $plan->hasCompleteSequentialClipDurations()) {
                        $replacement = isset($durations[(int) $number[1]]) ? $this->seconds($durations[(int) $number[1]]) : null;
                    }
                } elseif (preg_match('/^\{\{VEO_HANDOFF:(\d+)\}\}$/', $token, $pair) && $owner === null) {
                    $source = array_values(array_filter($clips, fn (array $clip): bool => $clip['number'] === (int) $pair[1]));
                    if (count($source) === 1) { $replacement = $this->auditor->fieldValue($source[0]['text'], 'OUTGOING HANDOFF TYPE'); }
                } elseif ($token === '{{VEO_TOTAL_SECONDS}}' && $owner === null && $plan->hasCompleteSequentialClipDurations()) {
                    $replacement = $this->seconds($plan->totalRuntimeSeconds());
                } elseif (preg_match('/^\{\{VEO_IDENTITY:([A-Za-z]+-\d+)\}\}$/', $token, $identity)) {
                    if ($owner !== null && preg_match('/^\s*(?:[-*+>]\s*)?(?:CHARACTER|SUBJECT) IDENTITY LOCK\s*:/iu', str_replace(['**', '__', '`'], '', $line))) {
                        $replacement = $identities[strtoupper($identity[1])] ?? null;
                    }
                } elseif ($owner !== null && $owner > 0) {
                    $previous = $clips[$owner - 1];
                    $type = $previous['outgoing_type'];
                    if ($token === '{{VEO_INCOMING_HANDOFF}}' && $field('TRANSITION TYPE') && in_array($type, ['A', 'B', 'C'], true)) {
                        $replacement = $this->auditor->fieldValue($previous['text'], 'OUTGOING HANDOFF TYPE');
                    } elseif ($token === '{{VEO_PREVIOUS_END_STATE}}' && $field('OPENING CONTINUITY STATE') && in_array($type, ['A', 'B', 'C'], true)) {
                        $replacement = $previous['ending_continuity_state'];
                    } elseif ($token === '{{VEO_PREVIOUS_END_PRESENTATION}}' && $field('OPENING PRESENTATION STATE') && in_array($type, ['A', 'B'], true)) {
                        $replacement = $previous['ending_presentation_state'];
                    }
                }
                if (! is_string($replacement) || $replacement === '' || preg_match(self::TOKEN_PATTERN, $replacement)) {
                    throw new PromptGenerationException('The provider supplied an unresolved or out-of-scope canonical reference at line '.($index + 1).'. No incomplete prompt template was accepted.');
                }
                $expanded++;

                return $replacement;
            }, $editable);
            if (str_contains($editable, '{{VEO_')) {
                throw new PromptGenerationException('The provider supplied a malformed canonical reference. No incomplete prompt template was accepted.');
            }
            $line = $editable.($parts[1] ?? '').($parts[2] ?? '');
        }
        unset($line);
        $content = $expanded === 0 ? $template : implode(str_contains($template, "\r\n") ? "\r\n" : "\n", $lines);

        return ['content' => $content, 'expanded' => $expanded, 'template_sha256' => hash('sha256', $template)];
    }

    private function seconds(float $seconds): string
    {
        return rtrim(rtrim(number_format($seconds, 6, '.', ''), '0'), '.');
    }
}
