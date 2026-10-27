<?php

namespace Tests\Unit;

use App\Services\Subtitles\AssSubtitleGenerator;
use App\Services\Subtitles\SubtitleFailure;
use Tests\TestCase;

class AssSubtitleGeneratorTest extends TestCase
{
    private function settings(): array
    {
        return ['preset' => 'karaoke', 'font_size' => 46, 'highlight' => '#FFFF00', 'line_length' => 26, 'vertical_margin' => 110];
    }

    public function test_sentence_is_one_stable_event_with_word_transforms_and_explicit_wrapping(): void
    {
        $json = json_decode(file_get_contents(base_path('tests/Fixtures/subtitles/words.json')), true);
        $ass = (new AssSubtitleGenerator)->generate($json, $this->settings(), 720, 1280, 4);
        $this->assertSame(1, substr_count($ass, 'Dialogue:'));
        $this->assertStringContainsString('PlayResX: 720', $ass);
        $this->assertStringContainsString('0:00:00.30,0:00:03.20', $ass);
        $this->assertStringContainsString('\\N', $ass);
        $this->assertStringContainsString('\\1c&H00FFFF&', $ass);
        $this->assertSame(7, substr_count($ass, '\\rBase'));
        $this->assertStringContainsString('Every', $ass);
        $this->assertStringContainsString('clarity.', $ass);
    }

    public function test_plain_captions_have_no_animation_and_scale_to_input(): void
    {
        $json = json_decode(file_get_contents(base_path('tests/Fixtures/subtitles/words.json')), true);
        $settings = $this->settings();
        $settings['preset'] = 'plain';
        $ass = (new AssSubtitleGenerator)->generate($json, $settings, 360, 640, 4);
        $this->assertStringContainsString('PlayResY: 640', $ass);
        $this->assertStringContainsString('\\fs23', $ass);
        $this->assertStringNotContains('\\t(', $ass);
    }

    public function test_timestamp_rounding_and_override_escaping(): void
    {
        $generator = new AssSubtitleGenerator;
        $this->assertSame('1:01:01.24', $generator->timestamp(3661.235));
        $this->assertSame('0:01:00.00', $generator->timestamp(59.999));
        $escaped = $generator->escape("{\\pos(0,0)}\\N\ntext");
        $this->assertStringNotContains('{', $escaped);
        $this->assertStringNotContains('\\', $escaped);
        $this->assertStringNotContains("\n", $escaped);
    }

    public function test_missing_words_is_no_speech_not_invented_timing(): void
    {
        $this->expectException(SubtitleFailure::class);
        (new AssSubtitleGenerator)->generate(['segments' => [['text' => 'Untimed speech']]], $this->settings(), 720, 1280, 4);
    }

    public function test_invalid_word_timestamps_are_rejected(): void
    {
        $this->expectException(SubtitleFailure::class);
        (new AssSubtitleGenerator)->generate(['segments' => [['words' => [['word' => 'Hello', 'start' => 2, 'end' => 1]]]]], $this->settings(), 720, 1280, 4);
    }
}
