<?php

return [
    'ffmpeg' => env('SUBTITLE_FFMPEG', '/usr/bin/ffmpeg'),
    'ffprobe' => env('SUBTITLE_FFPROBE', '/usr/bin/ffprobe'),
    'whisper' => env('SUBTITLE_WHISPER', '/opt/whisper/bin/whisper'),
    'model' => env('SUBTITLE_WHISPER_MODEL', 'base.en'),
    'model_directory' => env('SUBTITLE_MODEL_DIRECTORY', '/opt/whisper/models'),
    'device' => env('SUBTITLE_WHISPER_DEVICE', 'cpu'),
    'threads' => (int) env('SUBTITLE_THREADS', 2),
    'max_upload_kb' => (int) env('SUBTITLE_MAX_UPLOAD_KB', 512000),
    'max_output_bytes' => (int) env('SUBTITLE_MAX_OUTPUT_BYTES', 2147483648),
    'max_duration' => (int) env('SUBTITLE_MAX_DURATION', 600),
    'max_dimension' => 4096,
    'max_pixels' => 9000000,
    'max_fps' => 60,
    'max_active_per_user' => 2,
    'retention_days' => (int) env('SUBTITLE_RETENTION_DAYS', 7),
    'process_timeout' => 3000,
    'job_timeout' => 7200,
    'font' => env('SUBTITLE_FONT', 'DejaVu Sans'),
    'presets' => ['karaoke' => 'Animated karaoke', 'plain' => 'Clean white captions'],
    'languages' => ['en' => 'English'],
];
