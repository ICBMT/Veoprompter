<?php

namespace Tests\Feature;

use App\Models\SubtitleVideo;
use App\Models\User;
use App\Services\Subtitles\AssSubtitleGenerator;
use App\Services\Subtitles\LocalMediaProcess;
use App\Services\Subtitles\TranscriptReview;
use App\Services\Subtitles\VideoSubtitleProcessor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Mockery;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class VideoSubtitleProcessorTest extends TestCase
{
    use RefreshDatabase;

    public static function colourProfiles(): array
    {
        return ['SDR' => [[]], 'SDR 10-bit' => [['pix_fmt' => 'yuv420p10le', 'color_transfer' => 'bt709']],
            'PQ' => [['color_transfer' => 'smpte2084', 'color_primaries' => 'bt2020', 'color_space' => 'bt2020nc', 'pix_fmt' => 'yuv420p10le']],
            'HLG' => [['color_transfer' => 'arib-std-b67', 'color_primaries' => 'bt2020', 'color_space' => 'bt2020nc', 'pix_fmt' => 'yuv420p10le']]];
    }

    #[DataProvider('colourProfiles')]
    public function test_pipeline_uses_safe_commands_word_timestamps_lossless_encoding_and_stage_transitions(array $colour): void
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
        $runner->shouldReceive('run')->andReturnUsing(function (array $arguments, SubtitleVideo $current) use (&$stages, $colour): string {
            $stages[] = $current->fresh()->status;
            $this->assertNotContains($current->original_name, $arguments);
            if (in_array('-show_streams', $arguments, true)) {
                return json_encode(['format' => ['duration' => '4.0'], 'streams' => [
                    ['index' => 0, 'codec_type' => 'video', 'width' => 720, 'height' => 1280, 'avg_frame_rate' => '24/1', 'pix_fmt' => 'yuv420p', ...($arguments[array_key_last($arguments)] === $current->path('rendering.mp4')
                        ? ['color_transfer' => 'bt709', 'color_primaries' => 'bt709', 'color_space' => 'bt709'] : $colour)],
                    ['index' => 1, 'codec_type' => 'audio', 'codec_name' => 'aac'],
                ]]);
            }
            if (in_array('--word_timestamps', $arguments, true)) {
                $this->assertContains('True', $arguments);
                $this->assertSame('transcribing', $current->fresh()->status);
                file_put_contents($current->path('audio.json'), file_get_contents(base_path('tests/Fixtures/subtitles/words.json')));
            } elseif (in_array('rendering.mp4', $arguments, true)) {
                $this->assertSame('rendering', $current->fresh()->status);
                $filter = $arguments[array_search('-vf', $arguments, true) + 1];
                if (in_array($colour['color_transfer'] ?? '', ['smpte2084', 'arib-std-b67'], true)) {
                    $this->assertStringContainsString('tonemap=', $filter);
                    $this->assertStringEndsWith('ass=captions.ass', $filter);
                    $this->assertSame('bt709', $arguments[array_search('-color_trc', $arguments, true) + 1]);
                    $this->assertSame('hdr', $current->media['source_dynamic_range']);
                } else {
                    $this->assertSame('ass=captions.ass', $filter);
                    $this->assertNotContains('-color_trc', $arguments);
                    $this->assertSame($colour['pix_fmt'] ?? 'yuv420p', $arguments[array_search('-pix_fmt', $arguments, true) + 1]);
                }
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
        $this->assertSame('awaiting_review', $video->fresh()->status);
        $this->assertNotContains('rendering', $stages);
        $disk->assertMissing($video->directory().'/captions.ass');
        $disk->assertMissing($video->directory().'/output.mp4');
        $processor->cleanup($video->refresh());
        $this->assertCount(7, $video->fresh()->transcript_draft);
        $video->update(['status' => 'generating_subtitles', 'transcript_approved' => $video->transcript_draft,
            'approved_revision' => $video->transcript_revision, 'approved_by' => $video->user_id,
            'approved_at' => now(), 'approved_hash' => TranscriptReview::hash($video->transcript_draft)]);
        $processor->render($video->refresh());
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
