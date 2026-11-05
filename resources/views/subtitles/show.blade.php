@extends('layouts.app')
@section('title', 'Subtitle video — VeoPrompter')
@section('eyebrow', 'SUBTITLE STUDIO')
@push('styles')<link rel="stylesheet" href="/css/subtitles.css">@endpush
@push('scripts')<script type="module" src="/js/subtitles.js"></script>@endpush
@section('content')
<section class="history-hero"><div><span class="section-index">SUBTITLE STUDIO / YOUR VIDEO</span><h1>{{ $video->original_name }}</h1><p>Your original is preserved. Transcription pauses for your mandatory review. Nothing is added to the video until you approve the exact caption wording.</p></div></section>
<section class="subtitle-panel" data-subtitle-job data-status="{{ $video->status }}" data-status-url="{{ route('subtitles.status', $video, false) }}">
    <h2 data-job-heading>{{ ucwords(str_replace('_', ' ', $video->status)) }}</h2>
    <ol class="subtitle-steps" aria-label="Processing stages">@foreach(['uploaded' => 'Uploaded', 'extracting_audio' => 'Extract audio', 'transcribing' => 'Transcribe', 'awaiting_review' => 'Review & approve', 'approved' => 'Approved / queued', 'generating_subtitles' => 'Compose captions', 'rendering' => 'Render video', 'completed' => 'Ready'] as $key => $label)<li data-stage="{{ $key }}">{{ $label }}</li>@endforeach</ol>
    <p data-job-feedback aria-live="polite">{{ $video->failureMessage() ?? ($video->status === 'completed' ? 'Your video is ready. Preview it before downloading.' : 'Waiting for progress. Transcription and lossless rendering may take several minutes.') }}</p>
    <section class="subtitle-review" data-transcript-review
        data-review-url="{{ route('subtitles.transcript', $video, false) }}"
        data-save-url="{{ route('subtitles.transcript.save', $video, false) }}"
        data-approve-url="{{ route('subtitles.approve', $video, false) }}"
        data-original-url="{{ route('subtitles.original', $video, false) }}"
        @if($video->status !== 'awaiting_review') hidden @endif>
        @if(($video->media['source_dynamic_range'] ?? '') === 'hdr')<p class="subtitle-note">HDR source detected ({{ $video->media['source_transfer'] ?? 'HDR' }}). After approval, the subtitled output will be tone-mapped to SDR BT.709. Your original HDR upload remains unchanged.</p>@endif
        <h2>Review every caption before rendering</h2>
        <p>Read the transcript, replay the original audio, and correct spelling, names, capitalization or punctuation. Word timing/order stays fixed. Replacements share the original word's time window; major rewrites need a new transcription. Long passages are split into readable caption pages automatically, preserving the approved words and their timings.</p>
        <video controls playsinline preload="none" data-review-video aria-label="Original video for transcript review"></video>
        <details><summary>Read the full draft</summary><p class="subtitle-full-transcript" data-transcript-paragraph></p></details>
        <p data-review-feedback role="status" aria-live="polite">Loading your private transcript…</p>
        <button class="button-secondary" type="button" data-review-load>Reload saved draft</button>
        <form data-transcript-form novalidate>
            <div class="subtitle-word-list" data-review-words></div>
            <nav class="subtitle-review-pages" aria-label="Transcript word pages"><button class="button-secondary" type="button" data-review-previous>Previous words</button><span data-review-page></span><button class="button-secondary" type="button" data-review-next>Next words</button></nav>
            <button class="button-secondary" type="button" data-review-save disabled>Save draft · do not render</button>
            <label class="subtitle-approval"><input type="checkbox" data-review-confirm required disabled> I have reviewed the transcript and approve these exact captions to be added to my video.</label>
            <button class="button-primary" type="submit" data-review-approve disabled>Approve transcript & render video</button>
            <p class="subtitle-note">Approval is mandatory. Saving a draft does not start rendering. Unsaved edits stay only in this tab; save your draft before leaving. No video render starts automatically.</p>
        </form>
        <p class="subtitle-note">Review is paused, not timed out. This upload and its drafts expire {{ $video->created_at->copy()->addDays(max(1, (int) config('subtitles.retention_days')))->format('M j, Y · H:i') }} (server time).</p>
        <noscript>Enable JavaScript to review the word-by-word transcript and explicitly approve it. Rendering remains blocked until approval.</noscript>
    </section>
    @if($video->approved_at)<p class="subtitle-note">Transcript explicitly approved {{ $video->approved_at->format('M j, Y · H:i') }} · revision {{ $video->approved_revision }}. The render uses that immutable approved wording.</p>@endif
    <div data-job-output @if($video->status !== 'completed') hidden @endif>
        <video controls playsinline preload="metadata" data-job-preview @if($video->status === 'completed') src="{{ route('subtitles.preview', $video, false) }}" @endif></video>
        <a class="button-primary" data-job-download @if($video->status === 'completed') href="{{ route('subtitles.download', $video, false) }}" @endif>Download subtitled MP4 ↧</a>
    </div>
    <p class="subtitle-note">Lossless H.264 encode · original dimensions/frame timing · no watermark. HDR input is tone-mapped to SDR BT.709, not preserved as HDR. Lossless H.264 may not play in every browser; download and use VLC if needed. Transcript approval is required before captions are rendered. Review the finished video again before publishing. Retention: {{ config('subtitles.retention_days') }} days.</p>
    <a class="history-open" href="{{ route('subtitles.index', [], false) }}">← Back to Subtitle Studio</a>
</section>
@endsection
