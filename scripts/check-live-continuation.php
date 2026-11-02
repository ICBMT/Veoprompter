<?php

use App\Models\AiSetting;
use App\Services\AiProviderSetupService;
use App\Services\PromptGenerationException;
use App\Services\PromptGenerationService;
use App\Services\PromptOutputAuditor;
use App\Services\StoryContinuationService;
use App\Support\ProjectSettings;
use Illuminate\Contracts\Console\Kernel;

// Opt-in live service-pipeline probe. No database writes, real user stories or printed credentials.
require __DIR__.'/../vendor/autoload.php';
$app = require __DIR__.'/../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

$key = getenv('GEMINI_API_KEY');
$model = getenv('GEMINI_MODEL');
if (getenv('ALLOW_LIVE_PROVIDER_TEST') !== '1' || ! $key || ! $model) {
    fwrite(STDERR, "Opt in with ALLOW_LIVE_PROVIDER_TEST=1, GEMINI_API_KEY and an exact GEMINI_MODEL ID. This makes billable provider calls. Never paste keys into reports.\n");
    exit(2);
}
if (! config('app.key')) {
    config(['app.key' => 'base64:'.base64_encode(random_bytes(32))]);
}
try {
    $setup = new AiProviderSetupService;
    $models = $setup->models('gemini', null, $key);
    if (! in_array($model, array_column($models, 'id'), true)) {
        throw new RuntimeException('The requested exact model ID was not returned by the provider model list. Select an accessible generateContent model in the Control room; no silent model substitution was made.');
    }
    $setup->testConnection('gemini', null, $model, $key);
    echo "PASS: requested model is listed and the connection test returned final text.\n";
    $settings = new AiSetting(['provider' => 'gemini', 'model' => $model, 'api_key' => $key]);
    $brief = [...ProjectSettings::defaults(),
        'mode' => 'story', 'video_type' => 'cinematic-story', 'content_form' => 'long',
        'audience' => 'Adults', 'clip_count' => 2, 'clip_duration' => 'auto', 'narration' => '1',
        'topic' => 'Two small clockwork couriers investigate why a workshop clock has stopped.',
        'creative_direction' => 'An ongoing gentle mystery with a small stable cast and physically plausible workshop actions. Each batch has two meaningful clips.'];
    $generator = new PromptGenerationService;
    $stories = new StoryContinuationService(new PromptOutputAuditor);
    $generated = $generator->generateWithQualityReview($settings, $brief);
    $content = $generated['content'];
    $context = $stories->initial($content, $brief);
    echo 'PASS: long opening parsed; QA findings: '.count($generated['quality_review']['issues']).".\n";
    foreach (['continue', 'finish'] as $index => $intent) {
        $input = $stories->prepare($content, $brief, $context, 'open', $intent, '', 'set');
        $input['_story_context']['part'] = 1;
        $input['_story_context']['batch'] = $index + 2;
        $generated = $generator->generateWithQualityReview($settings, $input);
        $context = $stories->accept($generated['content'], $input);
        $content = $generated['content'];
        echo 'PASS: '.$intent.' passed canonical identity and opening gates; QA findings: '.count($generated['quality_review']['issues']).".\n";
    }
    echo "No database rows or saved source stories were changed. This is a live application-service probe, not browser/rendered-video testing.\n";
} catch (PromptGenerationException|App\Services\AiProviderSetupException|RuntimeException $exception) {
    // Domain messages are controlled/sanitized; never dump requests, response bodies or stack traces.
    fwrite(STDERR, 'FAIL: '.$exception->getMessage()."\n");
    exit(1);
} catch (Throwable) {
    fwrite(STDERR, "FAIL: unexpected application error. Inspect private server diagnostics without sharing credentials.\n");
    exit(1);
}
