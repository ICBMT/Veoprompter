<?php

function config($key, $default = null) { return $default; }
require __DIR__.'/../app/Services/Subtitles/SubtitleFailure.php';
require __DIR__.'/../app/Services/Subtitles/TranscriptReview.php';
require __DIR__.'/../app/Services/Subtitles/AssSubtitleGenerator.php';
require __DIR__.'/../tests/Support/SubtitlePaginationCases.php';
if (($argv[1] ?? '') === '--ass') {
    $words = [];
    foreach (range(0, 59) as $i) {
        $words[] = ['start' => $i * 0.15, 'end' => $i * 0.15 + 0.14, 'word' => 'token'.$i];
    }
    echo (new App\Services\Subtitles\AssSubtitleGenerator)->generate(App\Services\Subtitles\TranscriptReview::forAss($words),
        ['preset' => 'karaoke', 'font_size' => 46, 'highlight' => '#FFFF00', 'line_length' => 26, 'vertical_margin' => 110], 360, 640, 9);
    exit;
}
$count = 0;
Tests\Support\SubtitlePaginationCases::run(function (bool $condition, string $label) use (&$count): void {
    if (! $condition) { throw new RuntimeException($label); }
    $count++;
});
echo "PASS: {$count} subtitle pagination assertions\n";
