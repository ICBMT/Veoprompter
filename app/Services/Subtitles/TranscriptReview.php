<?php

namespace App\Services\Subtitles;

/** Text-only corrections: timestamps and token order always come from the private source record. */
final class TranscriptReview
{
    public static function limit(): int
    {
        return function_exists('config') ? max(1, (int) config('subtitles.max_review_words', 6000)) : 6000;
    }

    public function normalize(array $raw, float $duration): array
    {
        if (! is_finite($duration) || $duration <= 0 || ! is_array($raw['segments'] ?? null)) {
            throw new SubtitleFailure('invalid_transcript');
        }
        $words = [];
        $previous = 0.0;
        foreach ($raw['segments'] as $segment) {
            if (! is_array($segment) || ! is_array($segment['words'] ?? null)) {
                throw new SubtitleFailure('invalid_transcript');
            }
            foreach ($segment['words'] as $word) {
                if (! is_array($word) || ! is_string($word['word'] ?? null) || ! mb_check_encoding($word['word'], 'UTF-8')
                    || ! is_numeric($word['start'] ?? null) || ! is_numeric($word['end'] ?? null)) {
                    throw new SubtitleFailure('invalid_transcript');
                }
                $text = trim(preg_replace('/\s+/u', ' ', $word['word']));
                if ($text === '') {
                    continue;
                }
                $start = (float) $word['start'];
                $end = (float) $word['end'];
                if (! is_finite($start) || ! is_finite($end) || $start < 0 || $end < $start || $start < $previous - 0.1
                    || $start >= $duration || $end > $duration + 0.5 || mb_strlen($text) > 1000
                    || preg_match('/[\x00-\x1F\x7F]/u', $text)) {
                    throw new SubtitleFailure('invalid_transcript');
                }
                $start = max($previous, $start);
                $end = min($duration, max($start + 0.01, $end));
                if ($end <= $start) {
                    throw new SubtitleFailure('invalid_transcript');
                }
                $words[] = ['start' => $start, 'end' => $end, 'word' => $text];
                $previous = $end;
                if (count($words) > self::limit()) {
                    throw new SubtitleFailure('invalid_transcript');
                }
            }
        }
        if ($words === []) {
            throw new SubtitleFailure('no_speech');
        }

        return $words;
    }

    public function replace(array $source, array $texts): array
    {
        if ($source === [] || count($source) > self::limit() || ! array_is_list($texts) || count($source) !== count($texts)) {
            throw new SubtitleFailure('invalid_review');
        }
        $words = [];
        foreach ($source as $i => $word) {
            $text = $texts[$i];
            if (! is_string($text) || ! mb_check_encoding($text, 'UTF-8') || trim($text) === '' || mb_strlen($text) > 120
                || preg_match('/[\x00-\x1F\x7F]/u', $text)) {
                throw new SubtitleFailure('invalid_review');
            }
            $text = trim(preg_replace('/\s+/u', ' ', $text));
            if ($text === '') {
                throw new SubtitleFailure('invalid_review');
            }
            $words[] = ['start' => (float) $word['start'], 'end' => (float) $word['end'], 'word' => $text];
        }

        return $words;
    }

    public static function hash(array $words): string
    {
        $canonical = array_map(fn (array $word): array => ['start' => (float) $word['start'], 'end' => (float) $word['end'], 'word' => $word['word']], $words);

        return hash('sha256', json_encode($canonical, JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION | JSON_THROW_ON_ERROR));
    }

    public static function forAss(array $words): array
    {
        return ['segments' => [['words' => $words]]];
    }
}
