<?php

namespace App\Services\Subtitles;

use App\Models\SubtitleVideo;
use Illuminate\Support\Facades\Storage;

class VideoSubtitleProcessor
{
    public function __construct(private LocalMediaProcess $process, private AssSubtitleGenerator $generator) {}

    public function process(SubtitleVideo $video): void
    {
        $ffmpeg = config('subtitles.ffmpeg');
        $probe = $this->probe($video->path('original.mp4'), $video);
        $streams = $probe['streams'] ?? [];
        $visual = collect($streams)->first(fn ($s) => ($s['codec_type'] ?? '') === 'video' && empty($s['disposition']['attached_pic']));
        $audio = collect($streams)->firstWhere('codec_type', 'audio');
        $duration = (float) ($probe['format']['duration'] ?? 0);
        $width = (int) ($visual['width'] ?? 0);
        $height = (int) ($visual['height'] ?? 0);
        $rate = explode('/', $visual['avg_frame_rate'] ?? '0/1');
        $fps = (float) ($rate[0] ?? 0) / max(1, (float) ($rate[1] ?? 1));
        if (! $visual || ! is_finite($duration) || $duration <= 0 || $duration > config('subtitles.max_duration')
            || min($width, $height) < 64 || max($width, $height) > config('subtitles.max_dimension')
            || $width * $height > config('subtitles.max_pixels') || $fps <= 0 || $fps > config('subtitles.max_fps')
            || in_array($visual['color_transfer'] ?? '', ['smpte2084', 'arib-std-b67'], true)
            || ! in_array($visual['pix_fmt'] ?? '', ['yuv420p', 'yuv422p', 'yuv444p', 'yuv420p10le', 'yuv422p10le', 'yuv444p10le'], true)) {
            throw new SubtitleFailure('invalid_media');
        }
        if (! $audio) {
            throw new SubtitleFailure('no_audio');
        }
        // FFmpeg autorotates; size the ASS canvas to the displayed frame.
        $rotation = (int) ($visual['tags']['rotate'] ?? 0);
        foreach ($visual['side_data_list'] ?? [] as $sideData) {
            $rotation = (int) ($sideData['rotation'] ?? $rotation);
        }
        if (abs($rotation) % 180 === 90) {
            [$width, $height] = [$height, $width];
        }
        $video->update(['media' => ['width' => $width, 'height' => $height, 'duration' => $duration, 'fps' => $fps]]);
        $this->process->run([$ffmpeg, '-nostdin', '-y', '-v', 'error', '-protocol_whitelist', 'file', '-f', 'mov', '-i', $video->path('original.mp4'), '-map', '0:'.$audio['index'], '-vn', '-ac', '1', '-ar', '16000', '-c:a', 'pcm_s16le', $video->path('audio.wav')], $video);
        $video->update(['status' => 'transcribing']);
        if (! is_file(rtrim(config('subtitles.model_directory'), '/').'/'.config('subtitles.model').'.pt')) {
            throw new SubtitleFailure('model_unavailable');
        }
        $this->process->run([
            config('subtitles.whisper'), $video->path('audio.wav'), '--model', config('subtitles.model'),
            '--model_dir', config('subtitles.model_directory'), '--device', config('subtitles.device'),
            '--language', $video->settings['language'], '--task', 'transcribe', '--word_timestamps', 'True',
            '--output_format', 'json', '--output_dir', dirname($video->path('audio.json')),
            '--fp16', 'False', '--verbose', 'False', '--threads', (string) config('subtitles.threads'),
        ], $video, timeout: (int) config('subtitles.process_timeout'));
        $video->update(['status' => 'generating_subtitles']);
        if (! is_file($video->path('audio.json')) || filesize($video->path('audio.json')) > 16000000) {
            throw new SubtitleFailure('invalid_transcript');
        }
        $transcript = json_decode(file_get_contents($video->path('audio.json')), true, 512, JSON_THROW_ON_ERROR);
        $ass = $this->generator->generate($transcript, $video->settings, $width, $height, $duration);
        file_put_contents($video->path('captions.ass'), $ass);
        $video->update(['status' => 'rendering']);
        $outputLimit = max(1048576, (int) config('subtitles.max_output_bytes'));
        $free = disk_free_space(dirname($video->path('original.mp4')));
        if ($free === false || $free < $outputLimit + 134217728) {
            throw new SubtitleFailure('storage_limit');
        }
        // Fixed filter filename in controlled cwd avoids FFmpeg filter-language path injection.
        $copyAudio = in_array($audio['codec_name'] ?? '', ['aac', 'mp3', 'alac'], true);
        $arguments = [$ffmpeg, '-nostdin', '-y', '-v', 'error', '-threads', (string) config('subtitles.threads'),
            '-protocol_whitelist', 'file', '-f', 'mov', '-i', 'original.mp4', '-map', '0:'.$visual['index'], '-map', '0:'.$audio['index'],
            '-filter_threads', (string) config('subtitles.threads'), '-vf', 'ass=captions.ass', '-c:v', 'libx264', '-preset', 'medium', '-crf', '0', '-threads', (string) config('subtitles.threads'),
            '-pix_fmt', $visual['pix_fmt'], '-fps_mode', 'passthrough', '-c:a', $copyAudio ? 'copy' : 'aac'];
        if (! $copyAudio) {
            array_push($arguments, '-b:a', '320k');
        }
        array_push($arguments, '-fs', (string) $outputLimit, '-movflags', '+faststart', '-map_metadata', '-1', 'rendering.mp4');
        $this->process->run($arguments, $video, dirname($video->path('original.mp4')), (int) config('subtitles.process_timeout'));
        if (filesize($video->path('rendering.mp4')) >= $outputLimit - 65536) {
            throw new SubtitleFailure('output_limit');
        }
        $output = $this->probe($video->path('rendering.mp4'), $video);
        if (abs((float) ($output['format']['duration'] ?? 0) - $duration) > 0.5 || filesize($video->path('rendering.mp4')) < 100) {
            throw new SubtitleFailure('processing_failed');
        }
        if (! rename($video->path('rendering.mp4'), $video->path('output.mp4'))) {
            throw new SubtitleFailure('processing_failed');
        }
        $video->update(['status' => 'completed', 'finished_at' => now(), 'failure_code' => null]);
    }

    public function cleanup(SubtitleVideo $video): void
    {
        foreach (['audio.wav', 'audio.json', 'captions.ass', 'rendering.mp4'] as $file) {
            Storage::disk('subtitles')->delete($video->directory().'/'.$file);
        }
        if ($video->status !== 'completed') {
            Storage::disk('subtitles')->delete($video->directory().'/output.mp4');
        }
    }

    private function probe(string $path, SubtitleVideo $video): array
    {
        return json_decode($this->process->run([config('subtitles.ffprobe'), '-v', 'error', '-protocol_whitelist', 'file', '-show_streams', '-show_format', '-of', 'json', '-f', 'mov', $path], $video), true, 512, JSON_THROW_ON_ERROR);
    }
}
