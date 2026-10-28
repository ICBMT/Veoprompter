<?php

// Framework-free checks of actual production services; no provider, TTS or rendered-video calls.
function config($key, $default = null) {
    $settings = require __DIR__.'/../config/prompt_qa.php';
    $prefix = 'prompt_qa.speech.';
    return str_starts_with($key, $prefix) ? ($settings['speech'][substr($key, strlen($prefix))] ?? $default) : $default;
}
foreach (['app/Support/ProjectSettings.php', 'app/Services/PromptProjectPlan.php', 'app/Services/PromptQaCatalogue.php',
    'app/Services/PromptQaEvidence.php', 'app/Services/PromptOutputAuditor.php', 'app/Services/PromptGenerationException.php',
    'app/Services/PromptPlanSerializer.php', 'app/Services/PromptGenerationService.php', 'app/Services/PromptRepairService.php',
    'tests/Support/PromptQaCases.php'] as $file) require __DIR__.'/../'.$file;

$checks = 0;
function verify(bool $condition, string $message): void {
    if (! $condition) throw new RuntimeException($message);
    $GLOBALS['checks']++;
}
$auditor = new App\Services\PromptOutputAuditor;
$serializer = new App\Services\PromptPlanSerializer($auditor);
$input = Tests\Support\PromptQaCases::input();
$catalogue = App\Services\PromptQaCatalogue::all();
preg_match_all('/\$this->addIssue\(\s*\$issues,\s*\x27([^\x27]+)\x27/', file_get_contents(__DIR__.'/../app/Services/PromptOutputAuditor.php'), $codes);
$actual = array_values(array_unique([...$codes[1], 'prompt_structure_missing'])); sort($actual);
$listed = array_keys($catalogue); sort($listed);
$cases = array_keys(Tests\Support\PromptQaCases::all()); sort($cases);
verify($actual === $listed && $listed === $cases, 'The source, catalogue and paired regressions must cover exactly the same issue codes.');
foreach ($catalogue as $code => $entry) {
    foreach (['category', 'kind', 'defect_condition', 'earliest_stage', 'invariant', 'independent_check', 'regression_case'] as $field) verify(!empty($entry[$field]), $code.' lacks '.$field);
}
foreach (['wheel', 'seed tray', 'glass carafe'] as $topic) {
    foreach (Tests\Support\PromptQaCases::all($topic) as $code => $case) {
        verify(!in_array($code, array_column($auditor->audit($case['valid'], $case['valid_input'])['issues'], 'code'), true), 'Valid '.$topic.' incorrectly triggers '.$code);
        verify(in_array($code, array_column($auditor->audit($case['invalid'], $case['invalid_input'])['issues'], 'code'), true), 'Malformed '.$topic.' failed to trigger '.$code);
    }
}
$expected = Tests\Support\PromptQaCases::characters(Tests\Support\PromptQaCases::package());
$template = Tests\Support\PromptQaCases::template();
$compiled = $serializer->serialize($template, $input);
verify($compiled['content'] === $expected, 'Canonical references did not produce the exact literal package.');
verify($compiled['expanded'] > 0 && $compiled['template_sha256'] === hash('sha256', $template), 'Compiler evidence must identify the actual template.');
verify($auditor->audit($compiled['content'], $input)['issues'] === [], 'Independently audited serialized package must pass.');
verify($serializer->serialize($expected, $input)['content'] === $expected, 'Literal legacy bytes changed.');
$spoken = str_replace('It keeps turning.', 'Literal {{VEO_TOTAL_SECONDS}}.', $expected);
verify($serializer->serialize($spoken, $input)['content'] === $spoken, 'Exact spoken token-like words changed.');
foreach (['{{VEO_UNKNOWN}}', '{{VEO_CLIP_SECONDS:99}}', '{{VEO_PREVIOUS_END_STATE}}', '{{VEO_IDENTITY:CHAR-99}}', '{{VEO_BAD:}}', '{{VEO_unfinished'] as $token) {
    try { $serializer->serialize('Title: '.$token."\n".$expected, $input); $rejected = false; }
    catch (App\Services\PromptGenerationException) { $rejected = true; }
    verify($rejected, 'Unresolved/out-of-scope reference accepted: '.$token);
}
$ambiguous = Tests\Support\PromptQaCases::all()['metadata_field_ambiguous']['invalid'];
$result = (new App\Services\PromptRepairService($auditor, new App\Services\PromptGenerationService))->repair(null, $ambiguous, $input);
verify($result['content'] === $ambiguous && !$result['repair_report']['applied'] && $result['repair_report']['fixed'] === [], 'Ambiguous metadata was silently repaired or falsely reported fixed.');
foreach (['Do not describe this as a seamless transition.', 'This is not a seamless continuation.', 'No uninterrupted take is claimed.'] as $negative) {
    $case = Tests\Support\PromptQaCases::all()['type_c_seamless_claim']['valid'];
    $text = Tests\Support\PromptQaCases::edit($case, 2, 'PRIMARY IDEA:', $negative."\nPRIMARY IDEA:");
    verify(!in_array('type_c_seamless_claim', array_column($auditor->audit($text, $input)['issues'], 'code'), true), 'A negative Type C disclaimer was treated as a positive seamless claim.');
}
$legacy = preg_replace('/^(?:CANONICAL CHARACTER ROSTER|CANONICAL IDENTITY OBJ-01):.*\R/m', '', Tests\Support\PromptQaCases::package());
$identity = array_values(array_filter($auditor->audit($legacy, $input)['checks'], fn (array $c): bool => $c['id'] === 'identity'))[0];
verify($identity['status'] === 'not_run', 'Identity absence was misreported as verified.');
$a = ['code' => 'speech_overrun', 'clip' => 1, 'message' => 'Clip 1 speech row 2 has 12 words in 1 seconds.'];
$b = [...$a, 'message' => 'Clip 1 speech row 2 has 12 words in 2 seconds.'];
verify(App\Services\PromptQaEvidence::introduced(['issues' => [$a]], ['issues' => [$b]]) === [], 'Numeric changes fabricated a new issue.');
verify(App\Services\PromptQaEvidence::issueKey($a) !== App\Services\PromptQaEvidence::issueKey([...$b, 'clip' => 2]), 'Distinct finding locations were conflated.');
echo 'PASS: '.$checks.' refinement checks; '.count($catalogue)." source-covered codes, paired cases across three topics. No live-provider, TTS or rendered-video validation.\n";
