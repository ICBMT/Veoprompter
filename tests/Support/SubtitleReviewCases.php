<?php

namespace Tests\Support;

use App\Services\Subtitles\AssSubtitleGenerator;
use App\Services\Subtitles\SubtitleFailure;
use App\Services\Subtitles\TranscriptReview;

final class SubtitleReviewCases
{
    public static function run(callable $assert): void
    {
        $review = new TranscriptReview;
        $raw = json_decode(file_get_contents(__DIR__.'/../Fixtures/subtitles/words.json'), true);
        $source = $review->normalize($raw, 4);
        $assert(count($source) === 7, 'all timed words survive normalization');
        $assert($source[1]['word'] === 'word', 'ASR leading space normalized');
        $texts = array_column($source, 'word');
        $texts[0] = 'Corrected';
        $edited = $review->replace($source, $texts);
        $assert($edited[0]['word'] === 'Corrected' && $source[0]['word'] === 'Every', 'edits preserve original source');
        $assert(array_column($source, 'start') === array_column($edited, 'start') && array_column($source, 'end') === array_column($edited, 'end'), 'editing cannot change timestamps');
        $assert(TranscriptReview::hash($source) !== TranscriptReview::hash($edited), 'approval hashes bind exact wording');
        $assert(TranscriptReview::hash($edited) === TranscriptReview::hash(json_decode(json_encode($edited), true)), 'hash stable across database JSON round trip');
        $assert(TranscriptReview::forAss($edited)['segments'][0]['words'] === $edited, 'render receives exact approved words');
        $badTexts = array_fill(0, 7, 'valid');
        foreach (['', ' ', "\u{00a0}", "a\nb", "a\0b", str_repeat('x', 121), ['word' => 'tampered', 'start' => 99]] as $invalid) {
            $bad = $badTexts;
            $bad[0] = $invalid;
            try {
                $review->replace($source, $bad);
                $assert(false, 'invalid edit accepted');
            } catch (SubtitleFailure $e) {
                $assert($e->reason === 'invalid_review', 'reject blank/control/overlong/timing-object edits');
            }
        }
        foreach ([[], ['one'], [...$badTexts, 'extra']] as $bad) {
            try {
                $review->replace($source, $bad);
                $assert(false, 'wrong word count accepted');
            } catch (SubtitleFailure $e) {
                $assert($e->reason === 'invalid_review', 'cannot add or drop timed words');
            }
        }
        foreach ([[-1, 1], [1, .5], [4, 4], [1, 5], [NAN, 1], [0, INF]] as [$start, $end]) {
            try {
                $review->normalize(['segments' => [['words' => [['word' => 'unsafe', 'start' => $start, 'end' => $end]]]]], 4);
                $assert(false, 'invalid timestamp accepted');
            } catch (SubtitleFailure $e) {
                $assert($e->reason === 'invalid_transcript', 'reject invalid or out-of-bounds times');
            }
        }
        try {
            $review->normalize(['segments' => []], 4);
            $assert(false, 'empty transcript accepted');
        } catch (SubtitleFailure $e) {
            $assert($e->reason === 'no_speech', 'empty transcript cannot be approved');
        }
        $texts[0] = '{\\pos(0,0)}HACK';
        $ass = (new AssSubtitleGenerator)->generate(TranscriptReview::forAss($review->replace($source, $texts)),
            ['preset' => 'karaoke', 'font_size' => 24, 'highlight' => '#FFFF00', 'line_length' => 26, 'vertical_margin' => 110], 720, 1280, 4);
        $assert(! str_contains($ass, '\\pos(0,0)'), 'corrected text cannot inject ASS position tags');
        $assert(str_contains($ass, 'HACK'), 'escaped approved text is still present');
    }
}
