<?php

// Standalone generator smoke: works without Composer; output is an ASS file on stdout.
function config($key, $default = null) { return $default; }
require __DIR__.'/../app/Services/Subtitles/SubtitleFailure.php';
require __DIR__.'/../app/Services/Subtitles/AssSubtitleGenerator.php';

$width = (int) ($argv[1] ?? 720);
$height = (int) ($argv[2] ?? 1280);
if ($width < 64 || $height < 64 || max($width, $height) > 4096) {
    throw new RuntimeException('Invalid smoke dimensions.');
}
$generator = new App\Services\Subtitles\AssSubtitleGenerator;
$fixture = json_decode(file_get_contents(__DIR__.'/../tests/Fixtures/subtitles/words.json'), true, 512, JSON_THROW_ON_ERROR);
$settings = ['preset' => 'karaoke', 'font_size' => 46, 'highlight' => '#FFFF00', 'line_length' => 26, 'vertical_margin' => 110];
$ass = $generator->generate($fixture, $settings, $width, $height, 4);
if (substr_count($ass, 'Dialogue:') !== 1 || ! str_contains($ass, '\N') || $generator->timestamp(59.999) !== '0:01:00.00') {
    throw new RuntimeException('ASS smoke assertions failed.');
}
if (str_contains($generator->escape('{'.chr(92).'pos(0,0)}'), chr(92))) {
    throw new RuntimeException('ASS escaping failed.');
}
echo $ass;
