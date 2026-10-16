@extends('layouts.app')

@section('title', $project->name.' — Prompt Studio')
@section('eyebrow', 'PROJECT WORKSPACE')

@section('content')
@php
    $characterLabels = ['ai-choice' => 'AI chooses', 'human-male' => 'Human male', 'human-female' => 'Human female', 'robot' => 'Robot', 'fictional' => 'Fictional character'];
    $characterLabel = $characterLabels[$settings['character_type'] ?? 'ai-choice'] ?? 'Custom character';
    $videoTypeLabel = $videoTypes[$settings['video_type'] ?? 'educational'] ?? 'Educational explainer';
@endphp
<div class="studio-page" data-studio data-project-id="{{ $project->id }}">
    <section class="studio-hero project-workspace-hero">
        <div class="hero-copy">
            <div class="eyebrow-label"><span class="eyebrow-spark">✳</span> YOUR PROJECT / FLOW-READY PROMPTS</div>
            <h1>{{ $project->name }}<br><em>one world, many stories.</em></h1>
            <p>Your default video type, key subject, visual style, clip format, and sound preferences are saved here. Bring a new idea each time; the project settings stay consistent.</p>
            <div class="hero-qualities"><span><i>01</i> Saved preferences</span><b></b><span><i>02</i> Connected prompts</span><b></b><span><i>03</i> Private history</span></div>
        </div>
        <div class="storyboard-card" aria-label="Example of connected video frames">
            <div class="storyboard-topline"><span><i class="recording-dot"></i> CONTINUITY PREVIEW</span><span>FLOW / {{ $settings['aspect_ratio'] }}</span></div>
            <div class="storyboard-art">
                <div class="art-stars" aria-hidden="true"></div><div class="art-glow" aria-hidden="true"></div><div class="art-planet" aria-hidden="true"><span></span></div><div class="art-orbit art-orbit--one" aria-hidden="true"></div><div class="art-orbit art-orbit--two" aria-hidden="true"></div>
                <div class="art-character" aria-hidden="true"><span class="character-antenna"></span><span class="character-head"><i class="robot-eye robot-eye--left"></i><i class="robot-eye robot-eye--right"></i><i class="robot-mouth"></i></span><span class="character-body"><i class="robot-core"></i></span><span class="character-arm character-arm--left"></span><span class="character-arm character-arm--right"></span><span class="character-leg character-leg--left"></span><span class="character-leg character-leg--right"></span></div>
                <div class="art-location"><span>SUBJECT / CHARACTER</span><strong>{{ $characterLabel }}</strong></div><div class="art-lock"><span class="lock-check">✓</span> CONTINUITY LOCKED</div><div class="art-frame-number">01 <span>/</span> {{ str_pad((string) $settings['clip_count'], 2, '0', STR_PAD_LEFT) }}</div><div class="art-sun" aria-hidden="true"></div><div class="art-horizon" aria-hidden="true"></div>
            </div>
            <div class="storyboard-footer"><div class="frame-track" aria-hidden="true"><span class="frame-thumb is-current"><i></i></span><span class="frame-link"></span><span class="frame-thumb"><i></i></span><span class="frame-link"></span><span class="frame-thumb"><i></i></span><span class="frame-link"></span><span class="frame-thumb"><i></i></span></div><div class="storyboard-caption"><span>YOUR PROJECT / SAVED SETTINGS</span><span>↗</span></div></div>
        </div>
    </section>

    @if(!$aiReady)
        <div class="connection-notice" role="status"><span class="connection-notice-icon">!</span><span><strong>One last setup step.</strong> An administrator needs to connect an AI provider before prompts can be generated.</span>@if($isAdmin)<a href="{{ route('admin.settings', [], false) }}">Open Control room <span aria-hidden="true">↗</span></a>@else<span class="connection-contact">Contact your site administrator.</span>@endif</div>
    @endif

    <section class="workspace-section" aria-labelledby="build-heading">
        <div class="section-heading-row">
            <div><span class="section-index">01 <i>/</i> PROJECT WORKSPACE</span><h2 id="build-heading">What should we make next?</h2></div>
            <a class="project-edit-button" href="{{ route('projects.settings.edit', $project, false) }}"><span aria-hidden="true">⚙</span> Edit project settings</a>
        </div>

        <div class="project-preference-strip">
            <span><small>CHARACTER</small><strong>{{ $characterLabel }}</strong></span>
            <span><small>STYLE</small><strong>{{ $settings['visual_style'] === 'custom' ? $settings['style_detail'] : str_replace('-', ' ', $settings['visual_style']) }}</strong></span>
            <span><small>FORMAT</small><strong>{{ $settings['clip_count'] }} clips · {{ $settings['clip_duration'] }} sec / {{ $settings['extend_duration'] }} sec extension · {{ $settings['aspect_ratio'] }}</strong></span>
            <span><small>NARRATION</small><strong>{{ $settings['narration'] === '1' ? $settings['language'].' · '.$settings['narrator_voice'].' · '.$settings['narrator_tone'] : 'Off' }}</strong></span>
        </div>

        <div class="mode-tabs" role="tablist" aria-label="Choose a prompt workflow">
            <button class="mode-tab is-selected" type="button" role="tab" aria-selected="true" data-mode-tab="story"><span class="mode-icon"><svg viewBox="0 0 20 20" aria-hidden="true"><path d="M3 5.2A2.2 2.2 0 0 1 5.2 3h9.6A2.2 2.2 0 0 1 17 5.2v9.6a2.2 2.2 0 0 1-2.2 2.2H5.2A2.2 2.2 0 0 1 3 14.8V5.2Z"/><path d="m8 6.6 5.3 3.4L8 13.4V6.6Z"/></svg></span><span><strong>New video</strong><small>Explainer, film, promo, and more</small></span><span class="tab-check">✓</span></button>
            <button class="mode-tab" type="button" role="tab" aria-selected="false" data-mode-tab="extend"><span class="mode-icon"><svg viewBox="0 0 20 20" aria-hidden="true"><path d="M5 4v12l10-6L5 4Z"/><path d="M3 3v14"/></svg></span><span><strong>Extend a video</strong><small>Continue from a final frame</small></span><span class="tab-check">✓</span></button>
        </div>

        <form id="prompt-form" class="prompt-form project-prompt-form" action="{{ route('studio.generate', $project, false) }}" method="post" enctype="multipart/form-data" novalidate>
            @csrf
            <input type="hidden" name="mode" value="story" data-mode-value>
            <div class="form-grid">
                <section class="form-panel brief-panel">
                    <div class="panel-heading"><span class="panel-step">A</span><div><h3>Your new idea</h3><p>Project preferences are already set. Just describe the next prompt.</p></div></div>
                    <label class="field-label" for="project_title">Prompt kit title <span class="optional">OPTIONAL</span></label>
                    <input class="text-input input-secondary" id="project_title" name="project_title" type="text" maxlength="120" placeholder="A short label for this generated prompt kit">
                    <span class="field-hint field-hint--bottom">Leave it blank and we’ll use the topic or next action.</span>

                    <div class="mode-fields" data-story-fields>
                        <label class="field-label field-label--spaced" for="video_type">Video format</label>
                        <select class="text-input select-input" id="video_type" name="video_type" data-video-type-select required>
                            @foreach($videoTypes as $type => $label)
                                <option value="{{ $type }}" @selected(($settings['video_type'] ?? 'educational') === $type)>{{ $label }}</option>
                            @endforeach
                        </select>
                        <span class="field-hint">Starts with your project default. You can switch formats for this prompt only.</span>

                        <label class="field-label field-label--spaced" for="topic">What is this video about? <span class="required-star">*</span></label>
                        <input class="text-input input-large" id="topic" name="topic" type="text" maxlength="250" required placeholder="A subject, film premise, product, place, or idea" autocomplete="off">
                        <span class="field-hint">A subject, premise, product, place, or idea is enough. The selected format shapes the sequence.</span>
                        <div data-learning-objective-fields @if(($settings['video_type'] ?? 'educational') !== 'educational') hidden @endif>
                            <label class="field-label field-label--spaced" for="learning_objective">Learning objective <span class="optional">OPTIONAL</span></label>
                            <textarea class="text-input textarea-small" id="learning_objective" name="learning_objective" rows="2" maxlength="500" placeholder="What should viewers understand by the end?"></textarea>
                        </div>
                        <label class="field-label field-label--spaced" for="creative_direction">Creative direction <span class="optional">OPTIONAL</span></label>
                        <textarea class="text-input textarea-small" id="creative_direction" name="creative_direction" rows="3" maxlength="1500" placeholder="Genre, mood, pacing, a product detail, or an important moment"></textarea>
                        <label class="field-label field-label--spaced" for="character_idea">Character or subject direction <span class="optional">OPTIONAL</span></label>
                        <input class="text-input" id="character_idea" name="character_idea" type="text" maxlength="500" placeholder="A recurring hero, product detail, destination, or visual motif">
                        <span class="field-hint">Your saved character profile is used when a recurring character suits the video.</span>
                    </div>

                    <div class="mode-fields" data-extend-fields hidden>
                        <div class="extend-intro"><span class="extend-icon">↗</span><div><strong>Pick up exactly where the shot left off.</strong><p>Describe the final frame—or add a still image. The AI will only use details it can see or you provide.</p></div></div>
                        <label class="field-label" for="next_action">What happens next? <span class="required-star">*</span></label>
                        <textarea class="text-input textarea-large" id="next_action" name="next_action" rows="3" maxlength="800" required disabled placeholder="The explorer gently reaches toward the glowing seed. Keep the camera still."></textarea>
                        <label class="field-label field-label--spaced" for="visible_start">What is visible in the last frame? <span class="optional">OPTIONAL</span></label>
                        <textarea class="text-input textarea-small" id="visible_start" name="visible_start" rows="2" maxlength="1500" disabled placeholder="A small blue robot stands on the left, facing a glowing seed on a stone table."></textarea>
                        <div class="field-label field-label--spaced">Final-frame reference <span class="optional">OPTIONAL · UP TO 8 MB</span></div>
                        <label class="upload-zone" for="reference_frame" data-upload-zone><input id="reference_frame" name="reference_frame" type="file" accept="image/png,image/jpeg,image/webp" disabled><span class="upload-icon"><svg viewBox="0 0 24 24" aria-hidden="true"><path d="M12 16V4m0 0L7.5 8.5M12 4l4.5 4.5"/><path d="M5 14.5v4A1.5 1.5 0 0 0 6.5 20h11a1.5 1.5 0 0 0 1.5-1.5v-4"/></svg></span><span class="upload-copy"><strong data-upload-label>Drop a frame here or <i>browse files</i></strong><small>PNG, JPG or WEBP. Used for this request only.</small></span><span class="upload-clear" data-upload-clear hidden role="button" tabindex="0" aria-label="Remove selected image">×</span></label>
                    </div>
                </section>

                <section class="form-panel recipe-panel project-recipe-panel">
                    <div class="panel-heading"><span class="panel-step">B</span><div><h3>Project recipe</h3><p>These saved preferences stay the same for every prompt.</p></div></div>
                    <div class="project-recipe-list">
                        <div><span>Default video type</span><strong>{{ $videoTypeLabel }}</strong></div>
                        <div><span>Audience</span><strong>{{ $settings['audience'] }}</strong></div>
                        <div><span>Visual style</span><strong>{{ $settings['visual_style'] === 'custom' ? $settings['style_detail'] : str_replace('-', ' ', $settings['visual_style']) }}</strong></div>
                        <div><span>Character type</span><strong>{{ $characterLabel }}@if(!empty($settings['character_details'])) · {{ \Illuminate\Support\Str::limit($settings['character_details'], 100) }}@endif</strong></div>
                        <div><span>New-video length</span><strong>{{ $settings['clip_count'] }} clips · {{ $settings['clip_duration'] }} seconds each</strong></div>
                        <div><span>Extension length</span><strong>{{ $settings['extend_duration'] }} seconds for the next clip</strong></div>
                        <div><span>Frame shape</span><strong>{{ $settings['aspect_ratio'] }}</strong></div>
                        @if(($settings['video_type'] ?? 'educational') === 'educational')<div><span>Learning depth</span><strong>{{ str_replace('-', ' ', $settings['educational_depth']) }}</strong></div>@endif
                        <div><span>Narration</span><strong>{{ $settings['narration'] === '1' ? $settings['language'].' · '.$settings['narrator_voice'].' · '.$settings['narrator_accent'].' · '.$settings['narrator_tone'] : 'Off' }}</strong></div>
                        <div><span>Sound direction</span><strong>{{ $settings['music'] ? 'Music' : 'No music' }} · {{ $settings['sound_effects'] ? 'Sound effects' : 'No sound effects' }}</strong></div>
                    </div>
                    @if($settings['narration'] === '1')
                        <div class="voice-preview-card voice-preview-card--workspace">
                            <div><strong>Voice sample</strong><p>Hear an approximation using your device’s built-in speech voice.</p></div>
                            <button class="button-secondary voice-preview-button" type="button" data-voice-preview-button><span aria-hidden="true">▶</span> Preview voice</button>
                            <span class="voice-preview-status" data-voice-preview-status role="status" aria-live="polite"></span>
                        </div>
                    @endif
                    <a class="project-edit-inline" href="{{ route('projects.settings.edit', $project, false) }}">Change these defaults <span aria-hidden="true">↗</span></a>
                </section>
            </div>

            <div class="form-submit-row"><div class="form-footnote"><span class="spark-small">✳</span><span>One thoughtful prompt at a time.<br><small>Your project settings are reused automatically.</small></span></div><button class="button-primary" type="submit" data-submit-button><span class="button-copy" data-submit-label>Build my prompt kit</span><span class="button-icon" aria-hidden="true">↗</span><span class="button-loading" aria-hidden="true"><i></i><i></i><i></i></span></button></div>
        </form>

        <div class="generation-message" data-generation-message role="alert" hidden></div>
        <section class="results-section" data-results hidden aria-labelledby="results-heading">
            <div class="results-header"><div><span class="section-index">02 <i>/</i> YOUR COPY-READY PLAN</span><h2 id="results-heading">The video plan is in frame.</h2><p>Each prompt is self-contained. Copy a section, then take it straight to Flow.</p></div><div class="results-actions"><button class="button-secondary" type="button" data-download-output><span aria-hidden="true">↓</span> Save .txt</button><button class="button-secondary button-copy-all" type="button" data-copy-all><span aria-hidden="true">▢</span> Copy all</button></div></div>
            <div class="result-meta"><span class="result-status"><i></i> <span data-ready-label>PROMPT KIT READY</span></span><span>TEXT ONLY · NO VIDEO GENERATED</span></div>
            <div class="history-result-notice" data-history-notice role="status" aria-live="polite" hidden></div>
            <div class="output-content" data-output-content></div>
            <div class="voice-config-source" data-voice-config hidden data-language="{{ $settings['language'] }}" data-narrator-voice="{{ $settings['narrator_voice'] }}" data-narrator-accent="{{ $settings['narrator_accent'] }}" data-narrator-tone="{{ $settings['narrator_tone'] }}"></div>
            <div class="generated-voice-preview" data-generated-voice-preview @if($settings['narration'] !== '1') hidden @endif><div><span class="section-index">VOICE SAMPLE</span><strong>A quick preview of your chosen narrator</strong><p>Spoken by your browser or device, not the final video voice. Available voices and accents vary by device.</p></div><button class="button-secondary voice-preview-button" type="button" data-voice-preview-button><span aria-hidden="true">▶</span> Play sample</button><span class="voice-preview-status" data-voice-preview-status role="status" aria-live="polite"></span></div>
        </section>
    </section>

    <section class="project-runs-section" aria-labelledby="project-runs-heading">
        <div class="results-header"><div><span class="section-index">PROJECT ARCHIVE</span><h2 id="project-runs-heading">Prompt kits from this project</h2><p>Every completed prompt is saved here and in your private prompt library.</p></div><a class="history-open" href="{{ route('history.index', [], false) }}">Open full library <span aria-hidden="true">↗</span></a></div>
        @if($runs->count())
            <div class="project-runs-list">
                @foreach($runs as $run)
                    @php($runBrief = $run->brief ?? [])
                    <article class="project-run-card"><div class="project-run-icon">{{ $run->mode === 'extend' ? '↗' : '▶' }}</div><div class="project-run-copy"><span class="history-date">{{ $run->created_at->format('M j, Y · g:i A') }}</span><h3><a href="{{ route('history.show', $run, false) }}">{{ $run->title }}</a></h3><p>{{ $run->mode === 'extend' ? ($runBrief['next_action'] ?? 'Video extension') : ($runBrief['topic'] ?? 'Video prompt') }}</p></div><a class="project-run-open" href="{{ route('history.show', $run, false) }}" aria-label="Open {{ $run->title }}">↗</a></article>
                @endforeach
            </div>
            {{ $runs->links('components.pagination') }}
        @else
            <section class="history-empty project-runs-empty"><h3>Your first prompt is ready to begin.</h3><p>Choose New video or Extend a video above. Your generated kits will appear here.</p></section>
        @endif
    </section>
</div>
@endsection
