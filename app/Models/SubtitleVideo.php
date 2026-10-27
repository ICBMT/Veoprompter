<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Storage;

class SubtitleVideo extends Model
{
    use HasUuids;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['settings' => 'array', 'media' => 'array', 'started_at' => 'datetime', 'finished_at' => 'datetime'];
    }

    public function directory(): string
    {
        return 'subtitles/'.$this->getKey();
    }

    public function path(string $file): string
    {
        if (! in_array($file, ['original.mp4', 'audio.wav', 'audio.json', 'captions.ass', 'output.mp4', 'rendering.mp4', 'diagnostics.log'], true)) {
            throw new \InvalidArgumentException('Unknown subtitle artifact.');
        }

        return Storage::disk('subtitles')->path($this->directory().'/'.$file);
    }

    public function terminal(): bool
    {
        return in_array($this->status, ['completed', 'failed'], true);
    }

    public function failureMessage(): ?string
    {
        return match ($this->failure_code) {
            'invalid_media' => 'This MP4 is unsupported or exceeds the duration, resolution, frame-rate, or colour limits.',
            'no_audio' => 'This video has no usable audio track.',
            'storage_limit' => 'The server does not currently have enough free space for a lossless render. Please contact the administrator.',
            'output_limit' => 'The lossless output exceeds the server output-size limit. Your original is intact; try a shorter video or ask the administrator to raise the limit.',
            'sentence_too_long' => 'A sentence is too long to fit safely. Try a larger line length or a shorter video with clearer sentence boundaries.',
            'no_speech' => 'No recognizable English speech was found in this video.',
            default => $this->status === 'failed' ? 'Processing could not finish. Your original is unchanged. Please contact the administrator or try a new upload.' : null,
        };
    }
}
