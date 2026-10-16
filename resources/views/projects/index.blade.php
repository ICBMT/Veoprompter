@extends('layouts.app')

@section('title', 'My projects — VeoPrompter')
@section('eyebrow', 'MY PROJECTS')

@section('content')
<div class="projects-page">
    <section class="history-hero projects-hero">
        <div><span class="section-index">YOUR WORKSPACE <i>/</i> PROMPT PROJECTS</span><h1>Projects that remember.</h1><p>Set your creative preferences once, then return to the same project whenever you want another prompt kit.</p></div>
        <div class="history-count"><span>{{ number_format($projects->total()) }}</span><small>{{ \Illuminate\Support\Str::plural('project', $projects->total()) }}</small></div>
    </section>

    <div class="history-toolbar"><span class="projects-toolbar-note">Each project keeps its default video type, style, sound, and clip preferences.</span><a class="button-primary history-create" href="{{ route('projects.create', [], false) }}"><span>Create project</span><span class="button-icon" aria-hidden="true">↗</span></a></div>

    @if($projects->count())
        <div class="history-grid projects-grid">
            @foreach($projects as $project)
                @php
                    $settings = \App\Support\ProjectSettings::applyDurationOptions($project->settings ?? [], $durationOptions);
                    $characterLabels = ['ai-choice' => 'AI-chosen character', 'human-male' => 'Human male', 'human-female' => 'Human female', 'robot' => 'Robot', 'fictional' => 'Fictional character'];
                    $videoTypeLabel = $videoTypes[$settings['video_type'] ?? 'educational'] ?? 'Educational explainer';
                @endphp
                <article class="history-card project-card">
                    <div class="history-card-top"><span class="history-mode">✳ PROJECT</span><span class="history-date">UPDATED {{ $project->updated_at->format('M j, Y') }}</span></div>
                    <h2><a href="{{ route('projects.show', $project, false) }}">{{ $project->name }}</a></h2>
                    <p class="history-subject">{{ $settings['audience'] ?? 'A creative workspace' }}</p>
                    <div class="project-card-preferences"><span>{{ $videoTypeLabel }}</span><span>{{ $characterLabels[$settings['character_type'] ?? 'ai-choice'] ?? 'Custom character' }}</span><span>{{ $settings['clip_count'] ?? 4 }} clips · {{ $settings['clip_duration'] ?? 8 }} sec · {{ $settings['extend_duration'] ?? 8 }} sec extension</span><span>{{ $settings['aspect_ratio'] ?? '16:9' }} · {{ str_replace('-', ' ', $settings['visual_style'] ?? '3d-family-animation') }}</span></div>
                    <div class="history-card-meta"><span>{{ number_format($project->prompt_projects_count) }} {{ \Illuminate\Support\Str::plural('prompt kit', $project->prompt_projects_count) }}</span><i></i><span>{{ ($settings['narration'] ?? '1') === '1' ? ($settings['language'] ?? 'English').' narration' : 'Narration off' }}</span></div>
                    <div class="history-card-footer"><a href="{{ route('projects.show', $project, false) }}" class="history-open">Open project <span aria-hidden="true">↗</span></a><a href="{{ route('projects.settings.edit', $project, false) }}" class="project-settings-link">Edit settings</a></div>
                </article>
            @endforeach
        </div>
        {{ $projects->links('components.pagination') }}
    @else
        <section class="history-empty">
            <span class="empty-orbit" aria-hidden="true"><i></i><b></b></span>
            <h2>Your first project sets the tone.</h2>
            <p>Choose your recurring character, visual style, voice, and format. Then create as many prompt kits as you need from one saved setup.</p>
            <a href="{{ route('projects.create', [], false) }}" class="button-primary"><span>Create your first project</span><span class="button-icon" aria-hidden="true">↗</span></a>
        </section>
    @endif
</div>
@endsection
