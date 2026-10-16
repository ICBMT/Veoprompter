@extends('layouts.app')

@section('title', $project->title.' — VeoPrompter')
@section('eyebrow', 'SAVED PROMPT KIT')

@section('content')
@php
    $characterLabels = ['ai-choice' => 'AI chooses', 'human-male' => 'Human male', 'human-female' => 'Human female', 'robot' => 'Robot', 'fictional' => 'Fictional character'];
    $clipFormat = $project->mode === 'extend'
        ? 'Next clip · '.($project->brief['clip_duration'] ?? '—').' seconds · '.($project->brief['aspect_ratio'] ?? '—')
        : ($project->brief['clip_count'] ?? '—').' clips · '.($project->brief['clip_duration'] ?? '—').' seconds · '.($project->brief['aspect_ratio'] ?? '—');
@endphp
<div class="history-detail">
    <a href="{{ route('history.index', [], false) }}" class="history-back"><span aria-hidden="true">←</span> Back to your library</a>
    <section class="history-detail-hero">
        <div><span class="history-mode {{ $project->mode === 'extend' ? 'mode-extend' : '' }}">{{ $project->mode === 'extend' ? '↗ EXTEND VIDEO' : '▶ NEW STORY' }}</span><h1>{{ $project->title }}</h1><p>{{ $summary }}</p>@if($project->studioProject)<p class="history-project-origin">Generated in <a href="{{ route('projects.show', $project->studioProject, false) }}">{{ $project->studioProject->name }}</a></p>@endif</div>
        <div class="detail-actions">
            <button class="button-secondary" type="button" data-copy-target="#saved-prompt-output"><span aria-hidden="true">▢</span> Copy all</button>
            <button class="button-secondary" type="button" data-download-target="#saved-prompt-output" data-download-name="{{ \Illuminate\Support\Str::slug($project->title) }}.txt"><span aria-hidden="true">↓</span> Save .txt</button>
            <form action="{{ route('history.destroy', $project, false) }}" method="post" data-confirm="Remove this prompt kit from your library? This cannot be undone.">@csrf @method('DELETE')<button class="history-delete detail-delete" type="submit" aria-label="Delete this prompt kit" title="Delete this prompt kit"><svg viewBox="0 0 18 18" aria-hidden="true"><path d="M3.5 5h11M7 5V3.5h4V5m2.5 0-.6 9.5H5.1L4.5 5m2.8 2v5m3.4-5v5"/></svg></button></form>
        </div>
    </section>

    <div class="saved-detail-meta"><span>CREATED {{ $project->created_at->format('M j, Y · g:i A') }}</span><i></i><span>{{ $project->provider === 'gemini' ? 'GOOGLE GEMINI' : 'OPENAI-COMPATIBLE' }} · {{ $project->model }}</span>@if($project->reference_frame_attached)<i></i><span>FRAME IMAGE WAS USED · ORIGINAL IMAGE NOT RETAINED</span>@endif</div>

    <section class="saved-brief-card">
        <div class="saved-brief-heading"><span class="section-index">THE ORIGINAL BRIEF</span><span class="saved-readonly">SAVED WITH THIS KIT</span></div>
        <div class="saved-brief-grid">
            @if($project->mode === 'extend')
                <div><span>Next action</span><strong>{{ $project->brief['next_action'] ?? '—' }}</strong></div>
                @if(!empty($project->brief['visible_start']))<div><span>Visible starting state</span><strong>{{ $project->brief['visible_start'] }}</strong></div>@endif
            @else
                <div><span>Topic</span><strong>{{ $project->brief['topic'] ?? '—' }}</strong></div>
                @if(!empty($project->brief['learning_objective']))<div><span>Learning objective</span><strong>{{ $project->brief['learning_objective'] }}</strong></div>@endif
            @endif
            <div><span>Audience</span><strong>{{ $project->brief['audience'] ?? '—' }}</strong></div>
            <div><span>Visual style</span><strong>{{ ($project->brief['visual_style'] ?? '') === 'custom' ? ($project->brief['style_detail'] ?? 'Custom') : str_replace('-', ' ', $project->brief['visual_style'] ?? '—') }}</strong></div>
            @if(!empty($project->brief['character_type']))<div><span>Main character</span><strong>{{ $characterLabels[$project->brief['character_type']] ?? 'Custom character' }}@if(!empty($project->brief['character_details'])) · {{ $project->brief['character_details'] }}@endif</strong></div>@endif
            <div><span>Clip format</span><strong>{{ $clipFormat }}</strong></div>
            @if(!empty($project->brief['narration']))<div><span>Narration</span><strong>{{ $project->brief['narration'] === '1' ? ($project->brief['language'] ?? 'English').' · '.($project->brief['narrator_voice'] ?? 'Voice unspecified').' · '.($project->brief['narrator_accent'] ?? 'Accent unspecified').' · '.($project->brief['narrator_tone'] ?? 'Tone unspecified') : 'Off' }}</strong></div>@endif
            <div><span>Sound</span><strong>{{ !empty($project->brief['music']) ? 'Music' : 'No music' }} · {{ !empty($project->brief['sound_effects']) ? 'Sound effects' : 'No sound effects' }}</strong></div>
        </div>
    </section>

    <section class="saved-output-card">
        <div class="saved-output-heading"><div><span class="section-index">YOUR COPY-READY PROMPTS</span><p>Keep this page bookmarked, or copy the complete kit back into Flow.</p></div><span class="result-status"><i></i> SAVED</span></div>
        <pre class="history-output" id="saved-prompt-output">{{ $project->content }}</pre>
    </section>
</div>
@endsection
