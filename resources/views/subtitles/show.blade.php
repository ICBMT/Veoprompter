@extends('layouts.app')
@section('title', 'Subtitle video — VeoPrompter')
@section('eyebrow', 'SUBTITLE STUDIO')
@push('styles')<link rel="stylesheet" href="/css/subtitles.css">@endpush
@push('scripts')<script src="/js/subtitles.js" defer></script>@endpush
@section('content')
<section class="history-hero"><div><span class="section-index">SUBTITLE STUDIO / YOUR VIDEO</span><h1>{{ $video->original_name }}</h1><p>Your original is preserved. This page can be closed safely while the background worker finishes.</p></div></section>
<section class="subtitle-panel" data-subtitle-job data-status="{{ $video->status }}" data-status-url="{{ route('subtitles.status', $video, false) }}">
    <h2 data-job-heading>{{ ucwords(str_replace('_', ' ', $video->status)) }}</h2>
    <ol class="subtitle-steps" aria-label="Processing stages">@foreach(['uploaded' => 'Uploaded', 'extracting_audio' => 'Extract audio', 'transcribing' => 'Transcribe', 'generating_subtitles' => 'Compose captions', 'rendering' => 'Render video', 'completed' => 'Ready'] as $key => $label)<li data-stage="{{ $key }}">{{ $label }}</li>@endforeach</ol>
    <p data-job-feedback aria-live="polite">{{ $video->failureMessage() ?? ($video->status === 'completed' ? 'Your video is ready. Preview it before downloading.' : 'Waiting for progress. Transcription and lossless rendering may take several minutes.') }}</p>
    <div data-job-output @if($video->status !== 'completed') hidden @endif>
        <video controls playsinline preload="metadata" data-job-preview @if($video->status === 'completed') src="{{ route('subtitles.preview', $video, false) }}" @endif></video>
        <a class="button-primary" data-job-download @if($video->status === 'completed') href="{{ route('subtitles.download', $video, false) }}" @endif>Download subtitled MP4 ↧</a>
    </div>
    <p class="subtitle-note">Lossless video encode · original dimensions/frame timing · no watermark. Lossless H.264 may not play in every browser; download and use VLC if needed. Review transcription before publishing. Retention: {{ config('subtitles.retention_days') }} days.</p>
    <a class="history-open" href="{{ route('subtitles.index', [], false) }}">← Back to Subtitle Studio</a>
</section>
@endsection
