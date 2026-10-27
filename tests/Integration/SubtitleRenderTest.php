<?php

namespace Tests\Integration;

use App\Services\Subtitles\AssSubtitleGenerator;
use Symfony\Component\Process\Process;
use Tests\TestCase;

/** Opt-in real libass test. No Whisper model or network required. */
class SubtitleRenderTest extends TestCase
{
    public function test_lossless_burn_has_stable_wrapped_captions_and_moving_word_highlight(): void
    {
        $ffmpeg = getenv('SUBTITLE_TEST_FFMPEG');
        if (! $ffmpeg || ! is_executable($ffmpeg)) {
            $this->markTestSkipped('Set SUBTITLE_TEST_FFMPEG to an FFmpeg with libass/libx264.');
        }
        $directory = sys_get_temp_dir().'/subtitle-render-'.bin2hex(random_bytes(8));
        mkdir($directory, 0700, true);
        $run = function (array $args) use ($ffmpeg, $directory): string {
            $process = new Process([$ffmpeg, '-nostdin', '-v', 'error', ...$args], $directory, timeout: 120);
            $process->mustRun();
            return $process->getOutput();
        };
        try {
            $json = json_decode(file_get_contents(base_path('tests/Fixtures/subtitles/words.json')), true);
            $settings = ['preset' => 'karaoke', 'font_size' => 46, 'highlight' => '#FFFF00', 'line_length' => 26, 'vertical_margin' => 110];
            foreach ([[360, 640], [1280, 720]] as [$width, $height]) {
                $ass = (new AssSubtitleGenerator)->generate($json, $settings, $width, $height, 4);
                file_put_contents($directory.'/captions.ass', $ass);
                $run(['-y', '-f', 'lavfi', '-i', "color=c=0x253e50:s={$width}x{$height}:r=24:d=4", '-f', 'lavfi', '-i', 'sine=frequency=440:duration=4', '-c:v', 'libx264', '-pix_fmt', 'yuv420p', '-c:a', 'aac', '-shortest', 'original.mp4']);
                $hash = hash_file('sha256', $directory.'/original.mp4');
                $run(['-y', '-i', 'original.mp4', '-vf', 'ass=captions.ass', '-c:v', 'libx264', '-crf', '0', '-preset', 'medium', '-pix_fmt', 'yuv420p', '-fps_mode', 'passthrough', '-c:a', 'copy', '-movflags', '+faststart', 'output.mp4']);
                $this->assertSame($hash, hash_file('sha256', $directory.'/original.mp4'));
                $first = $run(['-ss', '0.5', '-i', 'output.mp4', '-frames:v', '1', '-f', 'rawvideo', '-pix_fmt', 'rgb24', '-']);
                $later = $run(['-ss', '2.3', '-i', 'output.mp4', '-frames:v', '1', '-f', 'rawvideo', '-pix_fmt', 'rgb24', '-']);
                $this->assertSame($width * $height * 3, strlen($first));
                $a = $this->pixels($first, $width);
                $b = $this->pixels($later, $width);
                $this->assertGreaterThan(20, $a['yellow']);
                $this->assertGreaterThan(20, $b['yellow']);
                $this->assertGreaterThan($a['yellow_y'] + 5, $b['yellow_y']);
                // The text bounds remain fixed, with safe margins on both aspect ratios.
                $this->assertEqualsWithDelta($a['min_x'], $b['min_x'], 2);
                $this->assertEqualsWithDelta($a['max_y'], $b['max_y'], 2);
                $this->assertGreaterThan(10, $a['min_x']);
                $this->assertLessThan($height - 10, $a['max_y']);
                $originalAudio = $run(['-i', 'original.mp4', '-map', '0:a:0', '-c:a', 'copy', '-f', 'hash', '-hash', 'sha256', '-']);
                $outputAudio = $run(['-i', 'output.mp4', '-map', '0:a:0', '-c:a', 'copy', '-f', 'hash', '-hash', 'sha256', '-']);
                $this->assertSame($originalAudio, $outputAudio);
            }
        } finally {
            foreach (glob($directory.'/*') as $file) {
                unlink($file);
            }
            rmdir($directory);
        }
    }

    private function pixels(string $rgb, int $width): array
    {
        $yellow = 0;
        $yellowY = 0;
        $minX = $width;
        $maxY = 0;
        for ($i = 0, $length = strlen($rgb); $i < $length; $i += 3) {
            $r = ord($rgb[$i]);
            $g = ord($rgb[$i + 1]);
            $b = ord($rgb[$i + 2]);
            if ($r > 170 && $g > 170) {
                $x = intdiv($i, 3) % $width;
                $y = intdiv(intdiv($i, 3), $width);
                $minX = min($minX, $x);
                $maxY = max($maxY, $y);
                if ($b < 90) {
                    $yellow++;
                    $yellowY += $y;
                }
            }
        }
        return ['yellow' => $yellow, 'yellow_y' => $yellowY / max(1, $yellow), 'min_x' => $minX, 'max_y' => $maxY];
    }
}
