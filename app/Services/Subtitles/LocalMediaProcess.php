<?php

namespace App\Services\Subtitles;

use App\Models\SubtitleVideo;
use Symfony\Component\Process\Process;

class LocalMediaProcess
{
    public function run(array $arguments, ?SubtitleVideo $video = null, ?string $cwd = null, int $timeout = 180): string
    {
        $process = new Process($arguments, $cwd, null, null, $timeout);
        $output = '';
        try {
            $process->run(function (string $type, string $chunk) use (&$output, $process): void {
                $output = substr($output.$chunk, -65536);
                $process->clearOutput();
                $process->clearErrorOutput();
            });
            if (! $process->isSuccessful()) {
                throw new SubtitleFailure('processing_failed');
            }
        } finally {
            // Bounded private diagnostics, including partial output on timeout.
            if ($video !== null) {
                $log = $video->path('diagnostics.log');
                $previous = is_file($log) ? file_get_contents($log) : '';
                file_put_contents($log, substr($previous."\n".$output, -65536));
            }
        }

        return $output;
    }
}
