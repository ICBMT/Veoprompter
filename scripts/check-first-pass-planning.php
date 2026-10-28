<?php

// Standalone policy smoke, without Composer, Laravel, provider calls or video generation.
// Run: php scripts/check-first-pass-planning.php
function config($key, $default = null)
{
    $settings = require __DIR__.'/../config/prompt_qa.php';
    $prefix = 'prompt_qa.speech.';
    if (! is_string($key) || ! str_starts_with($key, $prefix)) {
        return $default;
    }

    $name = substr($key, strlen($prefix));

    return $GLOBALS['speechOverrides'][$name] ?? $settings['speech'][$name] ?? $default;
}

function resource_path($path) { return __DIR__.'/../resources/'.$path; }
function filled($value) { return $value !== null && trim((string) $value) !== ''; }

require __DIR__.'/../app/Support/ProjectSettings.php';
require __DIR__.'/../app/Services/PromptOutputAuditor.php';
require __DIR__.'/../app/Services/PromptGenerationService.php';

$checks = 0;
function verify($condition, $message)
{
    if (! $condition) {
        throw new RuntimeException($message);
    }
    $GLOBALS['checks']++;
}
function policy($method, ...$arguments)
{
    return (new ReflectionMethod(App\Services\PromptGenerationService::class, $method))
        ->invoke(new App\Services\PromptGenerationService, ...$arguments);
}

foreach (array_keys(App\Support\ProjectSettings::videoTypes()) as $type) {
    foreach (['0', '1'] as $audio) {
        $input = ['mode' => 'story', 'video_type' => $type, 'narration' => $audio,
            'clip_count' => 'quality', 'clip_duration' => 'auto'];
        $system = policy('systemInstructions', $input);
        verify(substr_count($system, 'FIRST-PASS PRODUCTION CONTRACT — VERSION 2') === 1, 'Missing/duplicated final contract');
        verify(strpos($system, 'FIRST-PASS PRODUCTION CONTRACT — VERSION 2') > strpos($system, 'MASTER SPECIFICATION'), 'Wrong contract precedence');
        verify(str_contains($system, 'words_per_second: 2.5'), 'Planning rate differs from QA');
        verify(str_contains($system, 'Copy strings, do not paraphrase them.'), 'Exact state-copy discipline absent');
        verify(! str_contains(policy('buildCreativeBrief', $input, false), '- 0.0–1.8s'), 'Zero-second speech example remains in request brief');
    }
}
foreach (['0', '1'] as $audio) {
    $system = policy('systemInstructions', ['mode' => 'extend', 'narration' => $audio]);
    verify(str_contains($system, 'A single extension remains one prompt'), 'Extension count guard absent');
    verify(str_contains($system, 'silent clips use the explicit silent marker'), 'Silent-clip guard absent');
}
$GLOBALS['speechOverrides'] = ['words_per_second' => 1.7, 'young_audience_words_per_second' => 1.4,
    'opening_visual_beat_seconds' => 0.5, 'inter_line_pause_seconds' => 0.4,
    'ending_pause_seconds' => 0.8, 'handoff_closing_pause_seconds' => 1.2];
$contract = policy('firstPassProductionContract', ['audience' => 'Preschool children']);
foreach (['words_per_second: 1.4', 'Minimum opening visual seconds: 0.5',
    'Minimum inter-line pause seconds: 0.4', 'Minimum closing visual seconds: 1.2'] as $constant) {
    verify(str_contains($contract, $constant), 'Configured QA constant not reflected in planning: '.$constant);
}
$auditor = new App\Services\PromptOutputAuditor;
$estimate = $auditor->speechEstimatePolicy(['audience' => 'Preschool children']);
verify(abs($auditor->minimumSpeechSeconds('It moves.', ['audience' => 'Preschool children'])
    - (2 / $estimate['words_per_second'] + $estimate['sentence_pause_min_seconds'])) < 0.0001, 'QA estimate no longer matches exported planning constants');

echo "PASS: {$checks} first-pass policy checks across all selected video types, speech modes, extensions and custom QA settings. No live-provider or rendered-video validation.\n";
