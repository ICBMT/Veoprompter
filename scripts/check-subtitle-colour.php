<?php

require __DIR__.'/../app/Services/Subtitles/SubtitleFailure.php';
require __DIR__.'/../app/Services/Subtitles/SubtitleColourPipeline.php';

// JSON mode lets real-media tests execute the exact production colour plan.
if (isset($argv[1])) {
    echo json_encode(App\Services\Subtitles\SubtitleColourPipeline::forStream(json_decode($argv[1], true, 512, JSON_THROW_ON_ERROR)), JSON_THROW_ON_ERROR);
    exit;
}
require __DIR__.'/../tests/Support/SubtitleColourCases.php';
$count = 0;
Tests\Support\SubtitleColourCases::run(function (bool $condition, string $label) use (&$count): void {
    if (! $condition) {
        throw new RuntimeException($label);
    }
    $count++;
});
echo "PASS: {$count} subtitle colour assertions\n";
