<?php

// Explicitly opt-in and use a dedicated SQLite database; never seed a real installation.
require __DIR__.'/../../vendor/autoload.php';
$app = require __DIR__.'/../../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
if (getenv('BROWSER_QA_SEED') !== '1'
    || config('database.default') !== 'sqlite'
    || basename(config('database.connections.sqlite.database')) !== 'browser-qa.sqlite'
    || ! getenv('BROWSER_PASSWORD')) {
    throw new RuntimeException('Use BROWSER_QA_SEED=1, BROWSER_PASSWORD and a dedicated browser-qa.sqlite database.');
}
$user = App\Models\User::firstOrNew(['email' => 'browser-qa@example.test']);
$user->name = 'Browser QA';
$user->password = getenv('BROWSER_PASSWORD');
$user->is_admin = true;
$user->save();
App\Models\AiSetting::updateOrCreate(['id' => 1], [
    'provider' => 'openai', 'model' => 'browser-fixture-model',
    'base_url' => 'http://127.0.0.1:8901/v1', 'api_key' => 'browser-fixture-only',
    'allow_clip_duration_choice' => false, 'allow_extension_duration_choice' => false,
]);
$text = Tests\Support\PromptQaCases::package();
$next = Tests\Support\PromptQaCases::edit($text, 1,
    'OPENING CONTINUITY STATE: '.Tests\Support\PromptQaCases::state(),
    'OPENING CONTINUITY STATE: {{VEO_SOURCE_END_STATE}}');
$next = Tests\Support\PromptQaCases::edit($next, 1,
    'OPENING PRESENTATION STATE: camera=static; lighting=daylight; audio=quiet',
    'OPENING PRESENTATION STATE: {{VEO_SOURCE_END_PRESENTATION}}');
$path = storage_path('app/browser-provider-fixtures.json');
file_put_contents($path, json_encode([
    'short' => $text,
    'long' => $text.Tests\Support\StoryContinuationCases::footer(),
    'next' => $next.Tests\Support\StoryContinuationCases::footer(),
    'ending' => $next.Tests\Support\StoryContinuationCases::footer('ended'),
], JSON_THROW_ON_ERROR));
echo "Isolated browser account ready; fixtures: {$path}\n";

// Optional synthetic media plus a checked-in transcript: not a Whisper/transcription-quality test.
if ($media = getenv('BROWSER_QA_MEDIA')) {
    if (! is_file($media)) {
        throw new RuntimeException('BROWSER_QA_MEDIA must be a real synthetic MP4.');
    }
    $words = (new App\Services\Subtitles\TranscriptReview)->normalize(
        json_decode(file_get_contents(base_path('tests/Fixtures/subtitles/words.json')), true), 4);
    $video = App\Models\SubtitleVideo::create([
        'user_id' => $user->id, 'upload_token' => (string) Illuminate\Support\Str::uuid(),
        'original_name' => 'browser-review.mp4', 'size_bytes' => filesize($media),
        'status' => 'awaiting_review', 'transcript_source' => $words, 'transcript_draft' => $words,
        'transcript_revision' => 1, 'review_ready_at' => now(),
        'media' => ['width' => 360, 'height' => 640, 'duration' => 4],
        'settings' => ['preset' => 'karaoke', 'language' => 'en', 'font_size' => 46,
            'highlight' => '#FFFF00', 'line_length' => 26, 'vertical_margin' => 110],
    ]);
    Illuminate\Support\Facades\Storage::disk('subtitles')->put($video->directory().'/original.mp4', file_get_contents($media));
    echo "Synthetic transcript review ready: /subtitles/{$video->id}\n";
}
