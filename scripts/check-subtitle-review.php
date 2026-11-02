<?php

// Actual pure transcript and ASS services; no Laravel/FFmpeg/Whisper or PHPUnit required.
function config($key, $default = null)
{
    return $default;
}
require __DIR__.'/../app/Services/Subtitles/SubtitleFailure.php';
require __DIR__.'/../app/Services/Subtitles/TranscriptReview.php';
require __DIR__.'/../app/Services/Subtitles/AssSubtitleGenerator.php';
require __DIR__.'/../tests/Support/SubtitleReviewCases.php';
$count = 0;
Tests\Support\SubtitleReviewCases::run(function (bool $condition, string $label) use (&$count): void {
    if (! $condition) {
        throw new RuntimeException($label);
    }
    $count++;
});
echo "PASS: {$count} subtitle review service assertions\n";
