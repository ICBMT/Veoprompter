@extends('layouts.app')

@section('title', $project->title.' — VeoPrompter')
@section('eyebrow', 'SAVED PROMPT KIT')

@section('content')
@php
    $characterLabels = ['ai-choice' => 'AI chooses', 'human-male' => 'Human male', 'human-female' => 'Human female', 'robot' => 'Robot', 'fictional' => 'Fictional character'];
    $videoTypeLabel = \App\Support\ProjectSettings::videoTypes()[$project->brief['video_type'] ?? 'educational'] ?? 'Other / custom video';
    $clipFormat = $project->mode === 'extend'
        ? 'Next clip · '.\App\Support\ProjectSettings::formatDuration($project->brief['clip_duration'] ?? '—', true).' · '.($project->brief['aspect_ratio'] ?? '—')
        : \App\Support\ProjectSettings::formatClipCount($project->brief['clip_count'] ?? '—').' · '.\App\Support\ProjectSettings::formatDuration($project->brief['clip_duration'] ?? '—', true).' · '.($project->brief['aspect_ratio'] ?? '—');
@endphp
<div class="history-detail">
    <a href="{{ route('history.index', [], false) }}" class="history-back"><span aria-hidden="true">←</span> Back to your library</a>
    <section class="history-detail-hero">
        <div><span class="history-mode {{ $project->mode === 'extend' ? 'mode-extend' : '' }}">{{ $project->mode === 'extend' ? '↗ EXTEND VIDEO' : '▶ NEW VIDEO' }}</span><h1>{{ $project->title }}</h1><p>{{ $summary }}</p>@if($project->studioProject)<p class="history-project-origin">Generated in <a href="{{ route('projects.show', $project->studioProject, false) }}">{{ $project->studioProject->name }}</a></p>@endif</div>
        <div class="detail-actions">
            <button class="button-secondary" type="button" data-copy-target="#saved-prompt-output"><span aria-hidden="true">▢</span> Copy all</button>
            <button class="button-secondary" type="button" data-download-target="#saved-prompt-output" data-download-name="{{ \Illuminate\Support\Str::slug($project->title) }}.txt"><span aria-hidden="true">↓</span> Save .txt</button>
            <button class="button-secondary button-flow" type="button" data-send-to-flow data-prompt-target="#saved-prompt-output" data-prompt-title="{{ $project->title }} · saved prompt" data-prompt-mode="{{ $project->mode }}" aria-describedby="saved-flow-transfer-status"><span aria-hidden="true">↗</span><span data-flow-button-label>Send to Flow</span></button>
            <form action="{{ route('history.destroy', $project, false) }}" method="post" data-confirm="Remove this prompt kit from your library? This cannot be undone.">@csrf @method('DELETE')<button class="history-delete detail-delete" type="submit" aria-label="Delete this prompt kit" title="Delete this prompt kit"><svg viewBox="0 0 18 18" aria-hidden="true"><path d="M3.5 5h11M7 5V3.5h4V5m2.5 0-.6 9.5H5.1L4.5 5m2.8 2v5m3.4-5v5"/></svg></button></form>
        </div>
    </section>

    <p class="flow-transfer-status" id="saved-flow-transfer-status" data-flow-transfer-status role="status" aria-live="polite" hidden></p>

    <div class="saved-detail-meta"><span>CREATED {{ $project->created_at->format('M j, Y · g:i A') }}</span><i></i><span>{{ $project->provider === 'gemini' ? 'GOOGLE GEMINI' : 'OPENAI-COMPATIBLE' }} · {{ $project->model }}</span>@if($project->reference_frame_attached)<i></i><span>FRAME IMAGE WAS USED · ORIGINAL IMAGE NOT RETAINED</span>@endif</div>

    <section class="saved-brief-card">
        <div class="saved-brief-heading"><span class="section-index">THE ORIGINAL BRIEF</span><span class="saved-readonly">SAVED WITH THIS KIT</span></div>
        <div class="saved-brief-grid">
            <div><span>Video type</span><strong>{{ $videoTypeLabel }}</strong></div>
            @if($project->mode === 'extend')
                <div><span>Next action</span><strong>{{ $project->brief['next_action'] ?? '—' }}</strong></div>
                @if(!empty($project->brief['visible_start']))<div><span>Visible starting state</span><strong>{{ $project->brief['visible_start'] }}</strong></div>@endif
            @else
                <div><span>Video idea</span><strong>{{ $project->brief['topic'] ?? '—' }}</strong></div>
                @if(($project->brief['video_type'] ?? 'educational') === 'educational' && !empty($project->brief['learning_objective']))<div><span>Learning objective</span><strong>{{ $project->brief['learning_objective'] }}</strong></div>@endif
                @if(!empty($project->brief['creative_direction']))<div><span>Creative direction</span><strong>{{ $project->brief['creative_direction'] }}</strong></div>@endif
            @endif
            <div><span>Audience</span><strong>{{ $project->brief['audience'] ?? '—' }}</strong></div>
            <div><span>Visual style</span><strong>{{ empty($project->brief['visual_style']) ? '—' : \App\Support\ProjectSettings::visualStyleLabel($project->brief['visual_style'], $project->brief['style_detail'] ?? null) }}</strong></div>
            @if(!empty($project->brief['character_type']))<div><span>Main character</span><strong>{{ $characterLabels[$project->brief['character_type']] ?? 'Custom character' }}@if(!empty($project->brief['character_details'])) · {{ $project->brief['character_details'] }}@endif</strong></div>@endif
            <div><span>Clip format</span><strong>{{ $clipFormat }}</strong></div>
            @if(array_key_exists('narration', $project->brief))
                @php($savedSpeechMode = $project->brief['speech_mode'] ?? 'voiceover')
                <div><span>Spoken audio</span><strong>{{ \App\Support\ProjectSettings::speechModeLabel($savedSpeechMode, $project->brief['narration']) }}@if($project->brief['narration'] === '1' && $savedSpeechMode === 'character-dialogue') · {{ $project->brief['language'] ?? 'English' }}@elseif($project->brief['narration'] === '1') · {{ $project->brief['language'] ?? 'English' }} · {{ $project->brief['narrator_voice'] ?? 'Voice unspecified' }} · {{ $project->brief['narrator_accent'] ?? 'Accent unspecified' }} · {{ $project->brief['narrator_tone'] ?? 'Tone unspecified' }}@endif</strong></div>
            @endif
            <div><span>Sound</span><strong>{{ !empty($project->brief['music']) ? 'Music' : 'No music' }} · {{ !empty($project->brief['sound_effects']) ? 'Sound effects' : 'No sound effects' }}</strong></div>
        </div>
    </section>

    @if(is_array($project->quality_review))
        @php($qualityReview = $project->quality_review)
        <section class="saved-quality-review" data-quality-review-status="{{ $qualityReview['status'] ?? 'not_checked' }}" aria-label="Automated prompt quality review">
            <div class="quality-review-heading"><div><span class="section-index">AUTOMATED TEXT QA · NOT A FACT CHECK</span><strong>{{ match($qualityReview['status'] ?? 'not_checked') { 'passed' => 'Structured checks passed', 'needs_review' => 'Review recommended', default => 'Some checks could not run' } }}</strong></div>@if(!empty($qualityReview['corrected']))<span class="quality-review-corrected">TARGETED CORRECTION APPLIED</span>@endif</div>
            @if(!empty($qualityReview['checks']))
                <ul class="quality-review-checks">@foreach($qualityReview['checks'] as $check)<li data-check-status="{{ $check['status'] ?? 'not_run' }}"><strong>{{ $check['label'] ?? 'Quality check' }}</strong><span>{{ $check['detail'] ?? '' }}</span></li>@endforeach</ul>
            @endif
            @if(!empty($qualityReview['issues']))<div class="quality-review-issues"><strong>Items to review</strong><ul>@foreach($qualityReview['issues'] as $issue)<li>{{ $issue['message'] ?? 'A prompt issue needs review.' }}</li>@endforeach</ul></div>@endif
        </section>
    @endif

    <section class="saved-output-card">
        <div class="saved-output-heading"><div><span class="section-index">YOUR COPY-READY PROMPTS</span><p>Send the saved kit to the extension, then copy each part into a text field when ready.</p></div><span class="result-status"><i></i> SAVED</span></div>
        <pre class="history-output" id="saved-prompt-output">{{ $project->content }}</pre>
    </section>
</div>
@endsection
