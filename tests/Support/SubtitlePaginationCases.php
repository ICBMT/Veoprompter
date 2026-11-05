<?php

namespace Tests\Support;

use App\Services\Subtitles\AssSubtitleGenerator;
use App\Services\Subtitles\SubtitleFailure;
use App\Services\Subtitles\TranscriptReview;

final class SubtitlePaginationCases
{
    public static function run(callable $assert): void
    {
        $generator = new AssSubtitleGenerator;
        $settings = ['preset' => 'karaoke', 'font_size' => 46, 'highlight' => '#FFFF00', 'line_length' => 26, 'vertical_margin' => 110];
        foreach ([[720, 1280], [1280, 720], [360, 640]] as [$width, $height]) {
            foreach (['karaoke', 'plain'] as $preset) {
                $words = [];
                foreach (range(0, 199) as $i) {
                    $words[] = ['start' => $i * 0.3, 'end' => $i * 0.3 + 0.25, 'word' => 'token'.$i];
                }
                $before = TranscriptReview::hash($words);
                $ass = $generator->generate(TranscriptReview::forAss($words), [...$settings, 'preset' => $preset], $width, $height, 60);
                preg_match_all('/^Dialogue: 0,([^,]+),([^,]+),Base,,0,0,0,,(.*)$/m', $ass, $events, PREG_SET_ORDER);
                $assert(count($events) > 1, 'unpunctuated long speech uses multiple pages');
                $actual = [];
                $previousEnd = 0;
                foreach ($events as $event) {
                    preg_match_all('/token\d+/', $event[3], $tokens);
                    array_push($actual, ...$tokens[0]);
                    $assert(substr_count($event[3], '\\N') <= 3, 'no page exceeds four lines');
                    $first = (int) substr($tokens[0][0], 5);
                    $last = (int) substr($tokens[0][array_key_last($tokens[0])], 5);
                    $assert($event[1] === $generator->timestamp($words[$first]['start']), 'page starts at its actual first word');
                    $assert($event[2] === $generator->timestamp($words[$last]['end']), 'page ends at its actual last word');
                    $assert($words[$first]['start'] >= $previousEnd, 'pages do not overlap');
                    $previousEnd = $words[$last]['end'];
                }
                $assert($actual === array_column($words, 'word'), 'every approved word appears exactly once in order');
                $assert($before === TranscriptReview::hash($words), 'source words and timestamps never mutate');
                $assert(($preset === 'plain') === ! str_contains($ass, '\\t('), 'plain/karaoke animation policy retained');
            }
        }
        $fixture = json_decode(file_get_contents(__DIR__.'/../Fixtures/subtitles/words.json'), true);
        $ass = $generator->generate($fixture, $settings, 720, 1280, 4);
        $assert(substr_count($ass, 'Dialogue:') === 1, 'short fitting sentence remains one stable event');
        try {
            $generator->generate(['segments' => [['words' => [['start' => 0, 'end' => 1, 'word' => str_repeat('W', 120)]]]]], $settings, 360, 640, 2);
            $assert(false, 'unrenderable token must not be clipped');
        } catch (SubtitleFailure $exception) {
            $assert($exception->reason === 'sentence_too_long', 'genuinely unrenderable individual token fails explicitly');
        }
    }
}
