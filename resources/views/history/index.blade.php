@extends('layouts.app')

@section('title', 'Prompt history — VeoPrompter')
@section('eyebrow', 'MY PROMPT LIBRARY')

@section('content')
<div class="history-page">
    <section class="history-hero">
        <div><span class="section-index">YOUR WORKSPACE <i>/</i> SAVED PROMPTS</span><h1>Your prompt library</h1><p>Stories you’ve shaped, kept close. Open a project to copy its prompts again or remove it when you’re done.</p></div>
        <div class="history-count"><span>{{ number_format($totalProjects) }}</span><small>{{ \Illuminate\Support\Str::plural('saved kit', $totalProjects) }}</small></div>
    </section>

    <div class="history-toolbar">
        <form class="history-search" action="{{ route('history.index', [], false) }}" method="get" role="search">
            <svg viewBox="0 0 20 20" aria-hidden="true"><circle cx="8.8" cy="8.8" r="5.5"/><path d="m13 13 4 4"/></svg>
            <input type="search" name="q" value="{{ $search }}" maxlength="100" placeholder="Search your saved projects" aria-label="Search saved projects">
            @if($search)<a href="{{ route('history.index', [], false) }}" class="search-clear" aria-label="Clear search">×</a>@endif
            <button type="submit">Search</button>
        </form>
        <a class="button-primary history-create" href="{{ route('studio', [], false) }}"><span>New prompt kit</span><span class="button-icon" aria-hidden="true">↗</span></a>
    </div>

    @if($projects->count())
        <div class="history-grid">
            @foreach($projects as $project)
                @php
                    $brief = $project->brief ?? [];
                    $subject = $project->mode === 'extend' ? ($brief['next_action'] ?? 'Video extension') : ($brief['topic'] ?? 'Educational video');
                @endphp
                <article class="history-card">
                    <div class="history-card-top"><span class="history-mode {{ $project->mode === 'extend' ? 'mode-extend' : '' }}">{{ $project->mode === 'extend' ? '↗ EXTENSION' : '▶ NEW STORY' }}</span><span class="history-date">{{ $project->created_at->format('M j, Y') }}</span></div>
                    <h2><a href="{{ route('history.show', $project, false) }}">{{ $project->title }}</a></h2>
                    <p class="history-subject">{{ \Illuminate\Support\Str::limit($subject, 120) }}</p>
                    <div class="history-card-meta"><span>{{ $project->mode === 'extend' ? '1 extension prompt' : ($brief['clip_count'] ?? 4).' clips' }}</span><i></i><span>{{ $brief['clip_duration'] ?? 8 }} sec each</span><i></i><span>{{ $project->brief['aspect_ratio'] ?? '16:9' }}</span></div>
                    <div class="history-card-footer"><a href="{{ route('history.show', $project, false) }}" class="history-open">Open project <span aria-hidden="true">↗</span></a><form action="{{ route('history.destroy', $project, false) }}" method="post" data-confirm="Remove this prompt kit from your library? This cannot be undone.">@csrf @method('DELETE')<button type="submit" class="history-delete" aria-label="Delete {{ $project->title }}" title="Delete project"><svg viewBox="0 0 18 18" aria-hidden="true"><path d="M3.5 5h11M7 5V3.5h4V5m2.5 0-.6 9.5H5.1L4.5 5m2.8 2v5m3.4-5v5"/></svg></button></form></div>
                </article>
            @endforeach
        </div>
        {{ $projects->links('components.pagination') }}
    @else
        <section class="history-empty">
            <span class="empty-orbit" aria-hidden="true"><i></i><b></b></span>
            @if($search)
                <h2>No matches this time.</h2><p>Try a shorter title or clear your search to browse every saved prompt kit.</p><a href="{{ route('history.index', [], false) }}" class="button-secondary">Clear search</a>
            @else
                <h2>Your next story starts here.</h2><p>When you’re signed in, each prompt kit is saved automatically. Come back whenever you need it.</p><a href="{{ route('studio', [], false) }}" class="button-primary"><span>Create your first prompt kit</span><span class="button-icon" aria-hidden="true">↗</span></a>
            @endif
        </section>
    @endif
</div>
@endsection
