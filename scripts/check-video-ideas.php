<?php

require __DIR__.'/../app/Services/PromptGenerationException.php';
require __DIR__.'/../app/Services/Ideas/IdeaResponse.php';
$parser = new App\Services\Ideas\IdeaResponse;
$source = [['id' => 1, 'topic' => 'Energy', 'url' => 'https://trends.google.com/trending?geo=PK', 'published_at' => '2026-10-15T10:00:00Z']];
$types = ['educational', 'cinematic-story', 'documentary', 'brand-promo', 'music-video', 'travel', 'custom'];
$valid = ['ideas' => array_map(fn ($i) => ['title' => 'Angle '.$i, 'hook' => 'A clear hook', 'angle' => 'A useful perspective', 'source_id' => 1,
    'answers' => ['topic' => 'Energy '.$i, 'learning_objective' => 'Understand the mechanism', 'creative_direction' => 'Show a concrete visual progression', 'character_idea' => 'One turbine']], range(1, 10))];
$count = 0;
$assert = function ($condition, $message) use (&$count) { if (!$condition) throw new RuntimeException($message); $count++; };
foreach ($types as $type) {
    $ideas = $parser->decode(json_encode($valid), $source, $type, $types);
    $assert(count($ideas) === 10 && $ideas[0]['video_type'] === $type, 'all formats supported');
    $assert($ideas[0]['source'] === $source[0], 'only fetched source links returned');
    $assert($type === 'educational' || $ideas[0]['answers']['learning_objective'] === '', 'format-specific educational field');
}
$assert(count($parser->decode("```json\n".json_encode($valid)."\n```", $source, 'educational', $types)) === 10, 'single JSON fence accepted');
$variants = [];
$x = $valid; array_pop($x['ideas']); $variants[] = $x;
$x = $valid; $x['ideas'][0]['source_id'] = 99; $variants[] = $x;
$x = $valid; $x['ideas'][0]['source_id'] = '1'; $variants[] = $x;
$x = $valid; $x['ideas'][1]['title'] = $x['ideas'][0]['title']; $variants[] = $x;
$x = $valid; $x['ideas'][0]['answers']['clip_duration'] = 999; $variants[] = $x;
$x = $valid; $x['ideas'][0]['title'] = str_repeat('x', 101); $variants[] = $x;
$x = $valid; $x['ideas'][0]['answers']['learning_objective'] = ''; $variants[] = $x;
$x = $valid; $x['ideas'][0]['source'] = 'https://evil.example'; $variants[] = $x;
foreach ([...array_map('json_encode', $variants), '{broken', 'null', '[]'] as $bad) {
    try { $parser->decode($bad, $source, 'educational', $types); throw new RuntimeException('Invalid response accepted'); }
    catch (App\Services\PromptGenerationException) { $count++; }
}
echo "PASS: {$count} video idea response assertions\n";
