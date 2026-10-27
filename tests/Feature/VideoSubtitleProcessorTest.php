<?php

namespace Tests\Feature;

use App\Models\SubtitleVideo;
use App\Models\User;
use App\Services\Subtitles\AssSubtitleGenerator;
use App\Services\Subtitles\LocalMediaProcess;
use App\Services\Subtitles\VideoSubtitleProcessor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Mockery;
use Tests\TestCase;

class VideoSubtitleProcessorTest extends TestCase
{
    use RefreshDatabase;

    public function test_pipeline_uses_safe_commands_word_timestamps_lossless_encoding_and_stage_transitions(): void
    {
        Storage::fake('subtitles');
        $disk = Storage::disk('subtitles');
        $disk->put('models/base.en.pt', 'model stub; never executed');
        config(['subtitles.model_directory' => $disk->path('models'), 'subtitles.max_output_bytes' => 1048576]);
        $video = SubtitleVideo::create([
            'user_id' => User::factory()->create()->id, 'upload_token' => (string) Str::uuid(),
            'original_name' => "untrusted';video.mp4", 'size_bytes' => 8, 'status' => 'extracting_audio',
            'settings' => ['preset' => 'karaoke', 'language' => 'en', 'font_size' => 46, 'highlight' => '#FFFF00', 'line_length' => 26, 'vertical_margin' => 110],
        ]);
        $disk->put($video->directory().'/original.mp4', 'original');
        $runner = Mockery::mock(LocalMediaProcess::class);
        $stages = [];
        $runner->shouldReceive('run')->andReturnUsing(function (array $arguments, SubtitleVideo $current) use (&$stages): string {
            $stages[] = $current->fresh()->status;
            $this->assertNotContains($current->original_name, $arguments);
            if (in_array('-show_streams', $arguments, true)) {
                return json_encode(['format' => ['duration' => '4.0'], 'streams' => [
                    ['index' => 0, 'codec_type' => 'video', 'width' => 720, 'height' => 1280, 'avg_frame_rate' => '24/1', 'pix_fmt' => 'yuv420p'],
                    ['index' => 1, 'codec_type' => 'audio', 'codec_name' => 'aac'],
                ]]);
            }
            if (in_array('--word_timestamps', $arguments, true)) {
                $this->assertContains('True', $arguments);
                $this->assertSame('transcribing', $current->fresh()->status);
                file_put_contents($current->path('audio.json'), file_get_contents(base_path('tests/Fixtures/subtitles/words.json')));
            } elseif (in_array('rendering.mp4', $arguments, true)) {
                $this->assertSame('rendering', $current->fresh()->status);
                $this->assertContains('ass=captions.ass', $arguments);
                $this->assertContains('libx264', $arguments);
                $this->assertSame('0', $arguments[array_search('-crf', $arguments, true) + 1]);
                $this->assertSame('copy', $arguments[array_search('-c:a', $arguments, true) + 1]);
                $this->assertStringContainsString('Dialogue:', file_get_contents($current->path('captions.ass')));
                file_put_contents($current->path('rendering.mp4'), str_repeat('output', 100));
            } else {
                $this->assertContains('16000', $arguments);
                $this->assertContains('pcm_s16le', $arguments);
                file_put_contents($current->path('audio.wav'), 'test audio');
            }
            return '';
        });
        $processor = new VideoSubtitleProcessor($runner, new AssSubtitleGenerator);
        $processor->process($video);
        $this->assertSame('completed', $video->fresh()->status);
        $this->assertContains('transcribing', $stages);
        $this->assertContains('rendering', $stages);
        $this->assertSame('original', file_get_contents($video->path('original.mp4')));
        $processor->cleanup($video->refresh());
        $disk->assertExists($video->directory().'/output.mp4');
        $disk->assertMissing($video->directory().'/audio.json');
        $disk->assertMissing($video->directory().'/captions.ass');
    }
}
