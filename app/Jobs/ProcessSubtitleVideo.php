<?php

namespace App\Jobs;

use App\Models\SubtitleVideo;
use App\Services\Subtitles\SubtitleFailure;
use App\Services\Subtitles\VideoSubtitleProcessor;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Cache;
use Throwable;

class ProcessSubtitleVideo implements ShouldQueue
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
            return;
        }
        try {
            // Atomic claim also protects against duplicate dispatches after the lease expires.
            $claimed = SubtitleVideo::whereKey($this->videoId)->where('status', 'uploaded')
                ->update(['status' => 'extracting_audio', 'started_at' => now(), 'updated_at' => now()]);
            if (! $claimed || ! ($video = SubtitleVideo::find($this->videoId))) {
                return;
            }
            try {
                $processor->process($video);
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
        $video = SubtitleVideo::find($this->videoId);
        if ($video && ! $video->terminal()) {
            $video->update(['status' => 'failed', 'failure_code' => 'processing_failed', 'finished_at' => now()]);
            app(VideoSubtitleProcessor::class)->cleanup($video);
        }
    }
}
