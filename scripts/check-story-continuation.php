<?php

// Run actual domain services without Laravel, database, provider, TTS or rendered video.
foreach (['app/Support/ProjectSettings.php', 'app/Services/PromptProjectPlan.php', 'app/Services/PromptQaCatalogue.php',
    'app/Services/PromptOutputAuditor.php', 'app/Services/PromptGenerationException.php', 'app/Services/StoryContinuationService.php',
    'tests/Support/PromptQaCases.php', 'tests/Support/StoryContinuationCases.php'] as $file) {
    require __DIR__.'/../'.$file;
}
$count = 0;
Tests\Support\StoryContinuationCases::run(function (bool $condition, string $message) use (&$count): void {
    if (! $condition) {
        throw new RuntimeException($message);
    }
    $count++;
});
echo "PASS: {$count} story-continuation contract checks. No live-model, HTTP/database, TTS or rendered-video validation.\n";
