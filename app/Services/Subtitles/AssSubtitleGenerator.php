<?php

namespace App\Services\Subtitles;

class AssSubtitleGenerator
{
    public function generate(array $transcript, array $settings, int $width, int $height, float $duration): string
    {
        $scale = min($width / 720, $height / 1280);
        $fontSize = max(12, (int) round($settings['font_size'] * $scale));
        $margin = max(12, (int) round(45 * $scale));
        $vertical = max(12, (int) round($settings['vertical_margin'] * $scale));
        $font = preg_replace('/[^\pL\pN _-]/u', '', (string) config('subtitles.font', 'DejaVu Sans')) ?: 'DejaVu Sans';
        $outline = max(1, round(3 * $scale, 2));
        $shadow = max(0.5, round(1.2 * $scale, 2));
        $header = "[Script Info]\nScriptType: v4.00+\nPlayResX: {$width}\nPlayResY: {$height}\nWrapStyle: 2\nScaledBorderAndShadow: yes\n\n[V4+ Styles]\n";
        $header .= "Format: Name, Fontname, Fontsize, PrimaryColour, SecondaryColour, OutlineColour, BackColour, Bold, Italic, Underline, StrikeOut, ScaleX, ScaleY, Spacing, Angle, BorderStyle, Outline, Shadow, Alignment, MarginL, MarginR, MarginV, Encoding\n";
        $header .= "Style: Base,{$font},{$fontSize},&H00FFFFFF,&H00FFFFFF,&H00141414,&H80000000,-1,0,0,0,100,100,0,0,1,{$outline},{$shadow},2,{$margin},{$margin},{$vertical},1\n\n[Events]\nFormat: Layer, Start, End, Style, Name, MarginL, MarginR, MarginV, Effect, Text\n";
        $sentences = $this->sentences($transcript, $duration);
        if ($sentences === []) {
            throw new SubtitleFailure('no_speech');
        }
        $colour = $this->colour($settings['highlight']);
        $events = [];
        $previousEnd = 0.0;
        foreach ($sentences as $words) {
            $start = max($previousEnd, $words[0]['start']);
            $lastEnd = $words[array_key_last($words)]['end'];
            $end = min($duration, max($start + 0.01, $lastEnd));
            if ($end <= $start) {
                continue;
            }
            // One stable event per sentence, not one event per spoken word.
            $lineLimit = (int) $settings['line_length'];
            $sentenceSize = $fontSize;
            $lines = $this->wrap($words, $lineLimit, ($width - 2 * $margin) / $sentenceSize);
            while (count($lines) > 4 && $sentenceSize > max(12, (int) round(24 * $scale))) {
                $sentenceSize--;
                $lines = $this->wrap($words, (int) floor($lineLimit * $fontSize / $sentenceSize), ($width - 2 * $margin) / $sentenceSize);
            }
            if (count($lines) > 4) {
                throw new SubtitleFailure('sentence_too_long');
            }
            $parts = [];
            foreach ($lines as $line) {
                $pieces = [];
                foreach ($line as $word) {
                    $text = $this->escape($word['text']);
                    $tags = '{\\rBase\\fs'.$sentenceSize.'}';
                    if ($settings['preset'] === 'karaoke') {
                        $from = max(0, (int) round(($word['start'] - $start) * 1000));
                        $until = max($from + 1, (int) round(($word['end'] - $start) * 1000));
                        $fade = min(45, max(1, (int) floor(($until - $from) / 3)));
                        // Reset per span: inactive/completed words stay white, only the current word turns yellow.
                        $tags = '{\\rBase\\fs'.$sentenceSize.'\\1c&HFFFFFF&\\t('.$from.','.($from + $fade).',\\1c'.$colour.')\\t('.max($from + $fade, $until - $fade).','.$until.',\\1c&HFFFFFF&)}';
                    }
                    $pieces[] = $tags.$text;
                }
                $parts[] = implode(' ', $pieces);
            }
            $events[] = 'Dialogue: 0,'.$this->timestamp($start).','.$this->timestamp($end).',Base,,0,0,0,,'.implode('\\N', $parts);
            $previousEnd = $end;
        }

        return $header.implode("\n", $events)."\n";
    }

    public function timestamp(float $seconds): string
    {
        $cs = max(0, (int) round($seconds * 100));

        return sprintf('%d:%02d:%02d.%02d', intdiv($cs, 360000), intdiv($cs % 360000, 6000), intdiv($cs % 6000, 100), $cs % 100);
    }

    public function escape(string $text): string
    {
        // Literal full-width characters cannot inject ASS overrides or line breaks.
        return str_replace(['\\', '{', '}', "\r", "\n"], ['＼', '｛', '｝', ' ', ' '], $text);
    }

    private function colour(string $hex): string
    {
        if (! preg_match('/^#[0-9a-f]{6}$/i', $hex)) {
            throw new SubtitleFailure('invalid_settings');
        }

        return '&H'.strtoupper(substr($hex, 5, 2).substr($hex, 3, 2).substr($hex, 1, 2)).'&';
    }

    private function sentences(array $transcript, float $duration): array
    {
        $sentences = [];
        $sentence = [];
        $previous = 0.0;
        foreach ($transcript['segments'] ?? [] as $segment) {
            foreach ($segment['words'] ?? [] as $word) {
                if (! is_array($word) || ! is_numeric($word['start'] ?? null) || ! is_numeric($word['end'] ?? null)) {
                    throw new SubtitleFailure('invalid_transcript');
                }
                $start = (float) $word['start'];
                $end = (float) $word['end'];
                $text = trim(preg_replace('/\s+/u', ' ', (string) ($word['word'] ?? '')) ?? '');
                if ($text === '') {
                    continue;
                }
                if (! is_finite($start) || ! is_finite($end) || $start < 0 || $end < $start || $start < $previous - 0.1 || $end > $duration + 0.5) {
                    throw new SubtitleFailure('invalid_transcript');
                }
                $start = max($previous, $start);
                $end = min($duration, max($start + 0.01, $end));
                $sentence[] = ['text' => $text, 'start' => $start, 'end' => $end];
                $previous = $end;
                if (preg_match('/[.!?][”"\x27)]*$/u', $text)) {
                    $sentences[] = $sentence;
                    $sentence = [];
                }
            }
        }
        if ($sentence !== []) {
            $sentences[] = $sentence;
        }

        return $sentences;
    }

    private function widthEstimate(string $text): float
    {
        // Conservative bold-font em estimates also guard wide glyphs and large custom fonts.
        $width = 0.0;
        foreach (mb_str_split($text) as $char) {
            $width += preg_match('/[MW@%]/u', $char) ? 1.15
                : (preg_match('/[ilI.,!\x27:;]/u', $char) ? 0.4
                    : (preg_match('/[A-Z]/u', $char) ? 0.95
                        : (preg_match('/[a-z0-9]/u', $char) ? 0.75 : 1.2)));
        }
        return $width;
    }

    private function wrap(array $words, int $limit, float $maxEm): array
    {
        $lines = [[]];
        $length = 0;
        $em = 0.0;
        foreach ($words as $word) {
            $wordLength = mb_strlen($word['text']);
            $wordEm = $this->widthEstimate($word['text']);
            if ($wordLength > $limit * 2 || $wordEm > $maxEm) {
                throw new SubtitleFailure('sentence_too_long');
            }
            if ($length > 0 && ($length + 1 + $wordLength > $limit || $em + 0.4 + $wordEm > $maxEm)) {
                $lines[] = [];
                $length = 0;
                $em = 0.0;
            }
            $em += ($length > 0 ? 0.4 : 0) + $wordEm;
            $lines[array_key_last($lines)][] = $word;
            $length += ($length > 0 ? 1 : 0) + $wordLength;
        }

        return $lines;
    }
}
