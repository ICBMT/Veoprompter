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
        return ['settings' => 'array', 'media' => 'array', 'started_at' => 'datetime', 'finished_at' => 'datetime',
            'transcript_source' => 'array', 'transcript_draft' => 'array', 'transcript_approved' => 'array',
            'transcript_revision' => 'integer', 'approved_revision' => 'integer', 'approved_by' => 'integer',
            'approved_at' => 'datetime', 'review_ready_at' => 'datetime'];
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

    public function hasApprovedTranscript(): bool
    {
        if (! $this->approved_at || (int) $this->approved_by !== (int) $this->user_id
            || $this->approved_revision !== $this->transcript_revision || ! is_array($this->transcript_approved)
            || $this->transcript_approved === [] || ! is_string($this->approved_hash)) {
            return false;
        }
        try {
            return hash_equals($this->approved_hash, \App\Services\Subtitles\TranscriptReview::hash($this->transcript_approved));
        } catch (\Throwable) {
            return false;
        }
    }

    public function terminal(): bool
    {
        return in_array($this->status, ['completed', 'failed'], true);
    }

    public function failureMessage(): ?string
    {
        return match ($this->failure_code) {
            'invalid_media' => 'This MP4 is unsupported or exceeds the duration, resolution, frame-rate, or colour limits.',
            'unsupported_hdr' => 'This HDR colour profile is not supported. Use HDR10/PQ or HLG with BT.2020 colour, or export an SDR copy. Dolby Vision requires an HDR10/HLG-compatible base layer.',
            'no_audio' => 'This video has no usable audio track.',
            'storage_limit' => 'The server does not currently have enough free space for a lossless render. Please contact the administrator.',
            'output_limit' => 'The lossless output exceeds the server output-size limit. Your original is intact; try a shorter video or ask the administrator to raise the limit.',
            'sentence_too_long' => 'A sentence is too long to fit safely. Try a larger line length or a shorter video with clearer sentence boundaries.',
            'approval_required' => 'Rendering was blocked because a valid transcript approval was missing. Your original is unchanged.',
            'invalid_transcript' => 'The transcript could not be aligned safely. Your original is unchanged; try a clearer or shorter recording.',
            'no_speech' => 'No recognizable English speech was found in this video.',
            default => $this->status === 'failed' ? 'Processing could not finish. Your original is unchanged. Please contact the administrator or try a new upload.' : null,
        };
    }
}
