<?php

use App\Models\User;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Hash;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Artisan::command('veoprompter:admin:create {name} {email}', function (string $name, string $email) {
    if (! filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $this->error('Please provide a valid email address.');

        return 1;
    }

    $password = $this->secret('Choose a password (at least 12 characters)');

    if (! is_string($password) || strlen($password) < 12) {
        $this->error('The password must contain at least 12 characters. No account was changed.');

        return 1;
    }

    $user = User::query()->firstOrNew(['email' => strtolower($email)]);
    $isNewUser = ! $user->exists;
    $user->name = $name;
    $user->is_admin = true;

    if ($isNewUser) {
        $user->password = Hash::make($password);
    }

    $user->save();

    $this->info(($isNewUser ? 'Administrator account created for ' : 'Administrator access enabled for ').$user->email.'.');
    $this->line('Sign in at '.rtrim((string) config('app.url'), '/').'/login');

    return 0;
})->purpose('Create or promote a VeoPrompter administrator account');

Artisan::command('subtitles:doctor', function () {
    $runner = app(\App\Services\Subtitles\LocalMediaProcess::class);
    try {
        foreach (['ffmpeg', 'ffprobe'] as $binary) {
            $result = $runner->run([config('subtitles.'.$binary), '-version'], timeout: 20);
            $this->line(strtok($result, "\n"));
        }
        $filters = $runner->run([config('subtitles.ffmpeg'), '-filters'], timeout: 20);
        $encoders = $runner->run([config('subtitles.ffmpeg'), '-encoders'], timeout: 20);
        if (! preg_match('/\bass\s+V->V/', $filters) || ! str_contains($encoders, 'libx264')) {
            throw new \RuntimeException('FFmpeg needs the libass filter and libx264 encoder.');
        }
        $runner->run([config('subtitles.whisper'), '--help'], timeout: 30);
        if (! is_file(rtrim(config('subtitles.model_directory'), '/').'/'.config('subtitles.model').'.pt')) {
            throw new \RuntimeException('Whisper model is not provisioned locally. Install it before starting the worker.');
        }
        $this->info('Executable and model checks passed. Verify the configured font with fc-match and run a real speech sample before deployment.');
        return 0;
    } catch (\Throwable $e) {
        $this->error('Subtitle dependencies not ready: '.$e->getMessage());
        return 1;
    }
})->purpose('Check local subtitle executables, libass, encoder, and preinstalled Whisper model');

Artisan::command('subtitles:maintain', function () {
    $disk = \Illuminate\Support\Facades\Storage::disk('subtitles');
    $processor = app(\App\Services\Subtitles\VideoSubtitleProcessor::class);
    \App\Models\SubtitleVideo::query()->eachById(function ($video) use ($disk, $processor): void {
        $lock = \Illuminate\Support\Facades\Cache::lock('subtitle-video:'.$video->id, 60);
        if (! $lock->get()) {
            return;
        }
        try {
            if ($video->status === 'uploaded' && $video->created_at->lt(now()->subDay())) {
                $video->update(['status' => 'failed', 'failure_code' => 'processing_failed', 'finished_at' => now()]);
                $processor->cleanup($video);
            } elseif ($video->status === 'uploaded' && $video->created_at->lt(now()->subMinutes(5))) {
                \App\Jobs\ProcessSubtitleVideo::dispatch($video->id);
            } elseif (! $video->terminal() && $video->started_at?->lt(now()->subSeconds(config('subtitles.job_timeout') + 300))) {
                $video->update(['status' => 'failed', 'failure_code' => 'processing_failed', 'finished_at' => now()]);
                $processor->cleanup($video);
            }
            if ($video->terminal() && $video->created_at->lt(now()->subDays(max(1, config('subtitles.retention_days'))))) {
                $disk->deleteDirectory($video->directory());
                $video->delete();
            }
        } finally {
            $lock->release();
        }
    });
    // Account deletion cascades the database rows, not files. Reap their private directories.
    foreach ($disk->directories('subtitles') as $directory) {
        $id = basename($directory);
        if (\Illuminate\Support\Str::isUuid($id)
            && $disk->lastModified($directory) < now()->subDay()->timestamp
            && ! \App\Models\SubtitleVideo::whereKey($id)->exists()) {
            $lock = \Illuminate\Support\Facades\Cache::lock('subtitle-video:'.$id, 60);
            if ($lock->get()) {
                try {
                    if (! \App\Models\SubtitleVideo::whereKey($id)->exists()) {
                        $disk->deleteDirectory($directory);
                    }
                } finally {
                    $lock->release();
                }
            }
        }
    }
    $this->info('Subtitle recovery and retention sweep completed.');
})->purpose('Recover queued uploads, mark abandoned jobs failed, and prune retained subtitle files');

\Illuminate\Support\Facades\Schedule::command('subtitles:maintain')->everyTenMinutes()->withoutOverlapping();
