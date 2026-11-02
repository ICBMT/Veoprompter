<?php

namespace App\Services;

/** Compile literal saved identities and scoped references; never infer or repair scene facts. */
final class PromptPlanSerializer
{
    public const TOKEN_PATTERN = '/\{\{VEO_[^{}\r\n]*\}\}/';

    public function __construct(private readonly PromptOutputAuditor $auditor) {}

    /** @return array{content: string, expanded: int, template_sha256: string, inherited_identity_ids: list<string>} */
    public function serialize(string $template, array $input): array
    {
        $templateHash = hash('sha256', $template);
        $inherited = [];
        if (isset($input['_story_context'])) {
            [$template, $inherited] = $this->inheritIdentityDefinitions($template, $input);
        }
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
            $editable = preg_replace_callback(self::TOKEN_PATTERN, function (array $match) use ($index, $owner, $clips, $plan, $durations, $identities, $line, $input, &$expanded): string {
                $token = $match[0];
                $replacement = null;
                $field = fn (string $name): bool => preg_match('/^\s*(?:[-*+>]\s*)?'.preg_quote($name, '/').'\s*:\s*'.preg_quote($token, '/').'\s*$/iu', str_replace(['**', '__', '`'], '', $line)) === 1;
                if (in_array($token, ['{{VEO_SOURCE_END_STATE}}', '{{VEO_SOURCE_END_PRESENTATION}}'], true)) {
                    $expectedField = $token === '{{VEO_SOURCE_END_STATE}}' ? 'OPENING CONTINUITY STATE' : 'OPENING PRESENTATION STATE';
                    if ($owner === 0 && $clips[0]['number'] === 1 && isset($input['_story_context']) && $field($expectedField)) {
                        $opening = (new StoryContinuationService($this->auditor))->openingState($input);
                        $replacement = $opening[$token === '{{VEO_SOURCE_END_STATE}}' ? 'ending_state' : 'ending_presentation'];
                    }
                } elseif (preg_match('/^\{\{VEO_CLIP_SECONDS:(\d+)\}\}$/', $token, $number)) {
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

        return ['content' => $content, 'expanded' => $expanded, 'template_sha256' => $templateHash, 'inherited_identity_ids' => $inherited];
    }

    /** Fill omissions only: conflicting literal definitions are rejected, never overwritten. */
    private function inheritIdentityDefinitions(string $template, array $input): array
    {
        $stories = new StoryContinuationService($this->auditor);
        $ledger = $stories->canonicalIdentities($input);
        $plan = $this->auditor->canonicalProjectPlan($template, $input);
        $lines = preg_split('/\R/u', $template) ?: [];
        $prefixEnd = $plan->clips[0]['heading_line'] ?? $plan->clips[0]['start_line'] ?? count($lines);
        $prefix = implode("\n", array_slice($lines, 0, $prefixEnd));
        $defined = [];
        $insertAt = 0;
        foreach (array_slice($lines, 0, $prefixEnd) as $index => $line) {
            $plain = str_replace(['**', '__', '`'], '', $line);
            if (preg_match('/^\h*(?:#{1,6}\h+)?(?:[-*+>]\h*)?CANONICAL CHARACTER ROSTER(?:\h*:\h*(.*)|\h*)$/iu', $plain, $roster)) {
                // An empty header owns the following list/table; never insert into that block.
                $insertAt = trim($roster[1] ?? '') === '' ? $index : $index + 1;
            }
            if (preg_match('/^\s*(?:[-*+>]\s*)?CANONICAL IDENTITY ([A-Za-z]+-\d+)\s*:\s*(.*)$/iu', $plain, $match)) {
                $id = strtoupper($match[1]);
                if (isset($defined[$id])) {
                    throw new PromptGenerationException('The provider supplied an ambiguous canonical identity; no template was accepted.');
                }
                $defined[$id] = trim($match[2]);
                if (isset($ledger[$id]) && $defined[$id] !== $ledger[$id]) {
                    throw new PromptGenerationException('The continuation redefined canonical identity '.$id.'. It was not saved.');
                }
                if ($insertAt === 0) {
                    $insertAt = $index;
                }
            }
        }
        $missing = [];
        foreach ($stories->declaredIdentityIds($prefix, $plan->clips) as $id) {
            if (! array_key_exists($id, $defined) && isset($ledger[$id])) {
                $missing[$id] = 'CANONICAL IDENTITY '.$id.': '.$ledger[$id];
            }
        }
        if ($missing === []) {
            return [$template, []];
        }
        array_splice($lines, $insertAt, 0, array_values($missing));

        return [implode(str_contains($template, "\r\n") ? "\r\n" : "\n", $lines), array_keys($missing)];
    }

    private function seconds(float $seconds): string
    {
        return rtrim(rtrim(number_format($seconds, 6, '.', ''), '0'), '.');
    }
}
