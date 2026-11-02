<?php

namespace App\Jobs;

use App\Models\SubtitleVideo;
use App\Services\Subtitles\SubtitleFailure;
use App\Services\Subtitles\VideoSubtitleProcessor;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Cache;
use Throwable;

class RenderSubtitleVideo implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 7200;

    public bool $failOnTimeout = true;

    public function __construct(public string $videoId)
    {
        $this->onConnection('subtitles')->onQueue('subtitles');
    }

    public function handle(VideoSubtitleProcessor $processor): void
    {
        $lock = Cache::lock('subtitle-video:'.$this->videoId, $this->timeout + 60);
        if (! $lock->get()) {
            // Approval can arrive while the transcription worker is still cleaning temporary files.
            if (SubtitleVideo::whereKey($this->videoId)->where('status', 'approved')->exists()) {
                self::dispatch($this->videoId)->delay(now()->addSeconds(5));
            }

            return;
        }
        try {
            $claimed = SubtitleVideo::whereKey($this->videoId)->where('status', 'approved')
                ->whereNotNull('approved_at')->whereNotNull('approved_by')->whereNotNull('approved_hash')
                ->whereColumn('approved_revision', 'transcript_revision')
                ->update(['status' => 'generating_subtitles', 'started_at' => now(), 'updated_at' => now()]);
            if (! $claimed || ! ($video = SubtitleVideo::find($this->videoId))) {
                return;
            }
            try {
                // Processor independently verifies owner/revision/snapshot hash before any media command.
                $processor->render($video);
            } catch (Throwable $exception) {
                $video->update(['status' => 'failed', 'failure_code' => $exception instanceof SubtitleFailure ? $exception->reason : 'processing_failed', 'finished_at' => now()]);
                report($exception);
            } finally {
                $processor->cleanup($video->refresh());
            }
        } finally {
            $lock->release();
        }
    }

    public function failed(?Throwable $exception): void
    {
        $lock = Cache::lock('subtitle-video:'.$this->videoId, 60);
        if (! $lock->get()) {
            return;
        }
        try {
            $video = SubtitleVideo::find($this->videoId);
            if ($video && in_array($video->status, ['generating_subtitles', 'rendering'], true)) {
                $video->update(['status' => 'failed', 'failure_code' => 'processing_failed', 'finished_at' => now()]);
                app(VideoSubtitleProcessor::class)->cleanup($video);
            }
        } finally {
            $lock->release();
        }
    }
}
