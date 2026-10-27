@extends('layouts.app')
@section('title', 'Video Subtitle Studio — VeoPrompter')
@section('eyebrow', 'SUBTITLE STUDIO')
@push('styles')<link rel="stylesheet" href="/css/subtitles.css">@endpush
@push('scripts')<script src="/js/subtitles.js" defer></script>@endpush
@section('content')
<section class="history-hero"><div><span class="section-index">THE FINAL TOUCH / LOCAL TRANSCRIPTION</span><h1>Give every word its moment.</h1><p>Upload a finished video from any generation platform. Add calm, word-highlighted captions, then take your video home.</p></div></section>
<div class="subtitle-layout">
<section class="subtitle-panel">
    <h2>Video Subtitle Studio</h2>
    <p>Your original stays unchanged. Processing uses local Whisper and FFmpeg, not a paid transcription API.</p>
    @if($errors->any())<div class="subtitle-error" role="alert"><ul>@foreach($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul></div>@endif
    <form action="{{ route('subtitles.store', [], false) }}" method="post" enctype="multipart/form-data" data-subtitle-upload>
        @csrf
        <input type="hidden" name="upload_token" value="{{ old('upload_token', $uploadToken) }}">
        <label class="subtitle-drop">Choose your finished MP4
            <input type="file" name="video" accept="video/mp4,.mp4" required data-video-file>
            <small>Up to {{ round(config('subtitles.max_upload_kb') / 1024) }} MB · {{ config('subtitles.max_duration') }} seconds · SDR only · maximum 60 fps</small>
        </label>
        <label>Caption style<select name="preset">@foreach(config('subtitles.presets') as $key => $label)<option value="{{ $key }}" @selected(old('preset', 'karaoke') === $key)>{{ $label }}</option>@endforeach</select></label>
        <details class="subtitle-controls"><summary>Fine-tune captions</summary>
            <div class="subtitle-control-grid">
                <label>Language<select name="language">@foreach(config('subtitles.languages') as $key => $label)<option value="{{ $key }}" @selected(old('language', 'en') === $key)>{{ $label }}</option>@endforeach</select></label>
                <label>Font size (720 × 1280 reference)<input name="font_size" type="number" min="24" max="64" value="{{ old('font_size', 46) }}" required></label>
                <label>Word highlight<input name="highlight" type="color" value="{{ old('highlight', '#FFFF00') }}" required></label>
                <label>Target characters per line<input name="line_length" type="number" min="16" max="36" value="{{ old('line_length', 26) }}" required></label>
                <label>Bottom margin (reference pixels)<input name="vertical_margin" type="number" min="40" max="260" value="{{ old('vertical_margin', 110) }}" required></label>
            </div>
        </details>
        <p class="subtitle-note">Lossless H.264 rendering preserves resolution and frame timing without a lossy video quality setting. Subtitles change pixels; the result is not bit-identical and may be much larger. Compatible audio is copied; other audio is converted to AAC.</p>
        <button class="button-primary" type="submit"><span>Upload & create subtitles</span><span aria-hidden="true">↗</span></button>
        <p data-upload-feedback role="status"></p>
    </form>
</section>
<aside class="subtitle-panel subtitle-style-preview"><span class="section-index">DEFAULT PRESET</span><div class="subtitle-demo" aria-label="Style illustration: white captions with yellow current word">Every word.<br>A little <mark>more</mark> clarity.</div><h2>Animated karaoke</h2><p>Whole sentences stay visible. The spoken word gently highlights in yellow, then returns to white. No popping, flashing, or jumping between words.</p><p class="subtitle-note">Illustration only. Whisper can make mistakes; review names, punctuation, and timing before publishing. Videos are kept for {{ config('subtitles.retention_days') }} days, including originals.</p></aside>
</div>
<section class="subtitle-recent"><h2>Your subtitle videos</h2><div class="history-grid">
@forelse($videos as $video)
<article class="history-card"><span class="history-mode">{{ str_replace('_', ' ', strtoupper($video->status)) }}</span><h3><a href="{{ route('subtitles.show', $video, false) }}">{{ $video->original_name }}</a></h3><p>{{ $video->created_at->format('M j, Y · H:i') }}</p><a class="history-open" href="{{ route('subtitles.show', $video, false) }}">{{ $video->status === 'completed' ? 'Preview & download' : 'View progress' }} ↗</a></article>
@empty<p>No uploads yet. Your finished videos will appear here.</p>@endforelse
</div>{{ $videos->links('components.pagination') }}</section>
@endsection
