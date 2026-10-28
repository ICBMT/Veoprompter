<?php

namespace App\Services;

/** Stable identities describe findings, not changing numeric wording in their messages. */
final class PromptQaEvidence
{
    public static function issueKey(array $issue): string
    {
        $key = ($issue['code'] ?? 'unknown').':'.($issue['clip'] ?? 'all');
        $message = (string) ($issue['message'] ?? '');
        if (preg_match('/\bspeech\s+row\s+(\d+)/i', $message, $row)) {
            $key .= ':row:'.$row[1];
        }
        if (preg_match('/`([^`]+):?`/u', $message, $field)) {
            $key .= ':field:'.strtolower(rtrim($field[1], ':'));
        }
        if (preg_match('/\bEXACT (OPENING FRAME|ENDING STATE)\b/i', $message, $phase)) {
            $key .= ':phase:'.strtolower($phase[1]);
        }

        if (($issue['category'] ?? null) === 'identity' && preg_match_all('/\b[A-Z]+-\d+\b/iu', $message, $ids)) {
            $key .= ':ids:'.implode(',', array_unique(array_map('strtoupper', $ids[0])));
        }

        return $key;
    }

    public static function introduced(array $before, array $after): array
    {
        $known = array_fill_keys(array_map(self::issueKey(...), $before['issues'] ?? []), true);

        return array_values(array_filter($after['issues'] ?? [], fn (array $issue): bool => ! isset($known[self::issueKey($issue)])));
    }
}
