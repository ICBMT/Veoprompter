@extends('layouts.app')

@section('title', 'Prompt Studio — VeoPrompter')
@section('eyebrow', 'PROMPT STUDIO')

@section('content')
<div class="studio-page" data-studio>
    <section class="studio-hero">
        <div class="hero-copy">
            <div class="eyebrow-label"><span class="eyebrow-spark">✳</span> THE FLOW-READY STORY STUDIO</div>
            <h1>Make every frame<br><em>belong to the next.</em></h1>
            <p>Turn one idea into a connected sequence of educational video prompts—designed for clear action, memorable characters, and seamless handoffs in Flow.</p>
            <div class="hero-qualities">
                <span><i>01</i> Character lock</span><b></b><span><i>02</i> Scene continuity</span><b></b><span><i>03</i> Copy-ready</span>
            </div>
        </div>

        <div class="storyboard-card" aria-label="Example of connected video frames">
            <div class="storyboard-topline"><span><i class="recording-dot"></i> CONTINUITY PREVIEW</span><span>FLOW / 16:9</span></div>
            <div class="storyboard-art">
                <div class="art-stars" aria-hidden="true"></div>
                <div class="art-glow" aria-hidden="true"></div>
                <div class="art-planet" aria-hidden="true"><span></span></div>
                <div class="art-orbit art-orbit--one" aria-hidden="true"></div>
                <div class="art-orbit art-orbit--two" aria-hidden="true"></div>
                <div class="art-character" aria-hidden="true">
                    <span class="character-antenna"></span><span class="character-head"><i class="robot-eye robot-eye--left"></i><i class="robot-eye robot-eye--right"></i><i class="robot-mouth"></i></span>
                    <span class="character-body"><i class="robot-core"></i></span><span class="character-arm character-arm--left"></span><span class="character-arm character-arm--right"></span><span class="character-leg character-leg--left"></span><span class="character-leg character-leg--right"></span>
                </div>
                <div class="art-location"><span>SCENE 01</span><strong>A curious first step</strong></div>
                <div class="art-lock"><span class="lock-check">✓</span> CHARACTER LOCKED</div>
                <div class="art-frame-number">01 <span>/</span> 04</div>
                <div class="art-sun" aria-hidden="true"></div>
                <div class="art-horizon" aria-hidden="true"></div>
            </div>
            <div class="storyboard-footer">
                <div class="frame-track" aria-hidden="true"><span class="frame-thumb is-current"><i></i></span><span class="frame-link"></span><span class="frame-thumb"><i></i></span><span class="frame-link"></span><span class="frame-thumb"><i></i></span><span class="frame-link"></span><span class="frame-thumb"><i></i></span></div>
                <div class="storyboard-caption"><span>ONE STORY, FOUR HANDOFFS</span><span>↗</span></div>
            </div>
        </div>
    </section>

    @if(!$aiReady)
        <div class="connection-notice" role="status"><span class="connection-notice-icon">!</span><span><strong>One last setup step.</strong> An administrator needs to connect an AI provider before prompts can be generated.</span><a href="{{ $isAdmin ? route('admin.settings', [], false) : route('login', [], false) }}">{{ $isAdmin ? 'Open Control room' : 'Admin sign in' }} <span aria-hidden="true">↗</span></a></div>
    @endif

    <section class="workspace-section" aria-labelledby="build-heading">
        <div class="section-heading-row">
            <div><span class="section-index">01 <i>/</i> START WITH A BRIEF</span><h2 id="build-heading">What are we making?</h2></div>
            <span class="secure-note"><svg viewBox="0 0 16 16" aria-hidden="true"><path d="M4.5 7V5.2a3.5 3.5 0 0 1 7 0V7M3.5 7h9v7h-9z"/></svg> Your brief stays on this request</span>
        </div>

        <div class="mode-tabs" role="tablist" aria-label="Choose a prompt workflow">
            <button class="mode-tab is-selected" type="button" role="tab" aria-selected="true" data-mode-tab="story">
                <span class="mode-icon"><svg viewBox="0 0 20 20" aria-hidden="true"><path d="M3 5.2A2.2 2.2 0 0 1 5.2 3h9.6A2.2 2.2 0 0 1 17 5.2v9.6a2.2 2.2 0 0 1-2.2 2.2H5.2A2.2 2.2 0 0 1 3 14.8V5.2Z"/><path d="m8 6.6 5.3 3.4L8 13.4V6.6Z"/></svg></span>
                <span><strong>New story</strong><small>Plan a connected educational video</small></span><span class="tab-check">✓</span>
            </button>
            <button class="mode-tab" type="button" role="tab" aria-selected="false" data-mode-tab="extend">
                <span class="mode-icon"><svg viewBox="0 0 20 20" aria-hidden="true"><path d="M5 4v12l10-6L5 4Z"/><path d="M3 3v14"/></svg></span>
                <span><strong>Extend a video</strong><small>Continue from a final frame</small></span><span class="tab-check">✓</span>
            </button>
        </div>

        <form id="prompt-form" class="prompt-form" action="{{ route('studio.generate', [], false) }}" method="post" enctype="multipart/form-data" novalidate>
            @csrf
            <input type="hidden" name="mode" value="story" data-mode-value>
            <div class="form-grid">
                <section class="form-panel brief-panel">
                    <div class="panel-heading"><span class="panel-step">A</span><div><h3>Your idea</h3><p>Give the story a clear learning destination.</p></div></div>

                    <div class="mode-fields" data-story-fields>
                        <label class="field-label" for="topic">What should the video teach? <span class="required-star">*</span></label>
                        <input class="text-input input-large" id="topic" name="topic" type="text" maxlength="250" required placeholder="For example, how bees help flowers grow" autocomplete="off">
                        <span class="field-hint">A topic, question, or idea is plenty. The AI will shape it into a story.</span>

                        <label class="field-label field-label--spaced" for="learning_objective">Learning objective <span class="optional">OPTIONAL</span></label>
                        <textarea class="text-input textarea-small" id="learning_objective" name="learning_objective" rows="2" maxlength="500" placeholder="What should viewers understand by the end?"></textarea>

                        <label class="field-label field-label--spaced" for="character_idea">Character direction <span class="optional">OPTIONAL</span></label>
                        <input class="text-input" id="character_idea" name="character_idea" type="text" maxlength="500" placeholder="A tiny bee with a yellow scarf, or leave it to the AI">
                    </div>

                    <div class="mode-fields" data-extend-fields hidden>
                        <div class="extend-intro"><span class="extend-icon">↗</span><div><strong>Pick up exactly where the shot left off.</strong><p>Describe the final frame—or add a still image. The AI will only use details it can see or you provide.</p></div></div>
                        <label class="field-label" for="next_action">What happens next? <span class="required-star">*</span></label>
                        <textarea class="text-input textarea-large" id="next_action" name="next_action" rows="3" maxlength="800" required disabled placeholder="The explorer gently reaches toward the glowing seed. Keep the camera still."></textarea>
                        <label class="field-label field-label--spaced" for="visible_start">What is visible in the last frame? <span class="optional">OPTIONAL</span></label>
                        <textarea class="text-input textarea-small" id="visible_start" name="visible_start" rows="2" maxlength="1500" disabled placeholder="A small blue robot stands on the left, facing a glowing seed on a stone table."></textarea>

                        <div class="field-label field-label--spaced">Final-frame reference <span class="optional">OPTIONAL · UP TO 8 MB</span></div>
                        <label class="upload-zone" for="reference_frame" data-upload-zone>
                            <input id="reference_frame" name="reference_frame" type="file" accept="image/png,image/jpeg,image/webp" disabled>
                            <span class="upload-icon"><svg viewBox="0 0 24 24" aria-hidden="true"><path d="M12 16V4m0 0L7.5 8.5M12 4l4.5 4.5"/><path d="M5 14.5v4A1.5 1.5 0 0 0 6.5 20h11a1.5 1.5 0 0 0 1.5-1.5v-4"/></svg></span>
                            <span class="upload-copy"><strong data-upload-label>Drop a frame here or <i>browse files</i></strong><small>PNG, JPG or WEBP. Sent securely to your connected AI provider for this request only.</small></span>
                            <span class="upload-clear" data-upload-clear hidden role="button" tabindex="0" aria-label="Remove selected image">×</span>
                        </label>
                    </div>

                    <div class="field-divider"></div>
                    <label class="field-label" for="audience">Who is it for?</label>
                    <input class="text-input" id="audience" name="audience" type="text" maxlength="160" required value="Curious kids and families" placeholder="Curious kids, new learners, or science fans">
                    <span class="field-hint">Keep it specific to get the right vocabulary and pace.</span>
                </section>

                <section class="form-panel recipe-panel">
                    <div class="panel-heading"><span class="panel-step">B</span><div><h3>Set the scene</h3><p>A few creative choices go a long way.</p></div></div>

                    <label class="field-label" for="visual_style">Visual style</label>
                    <select class="text-input select-input" id="visual_style" name="visual_style" required>
                        <option value="3d-family-animation" selected>Polished 3D family animation</option>
                        <option value="2d-illustration">Expressive 2D illustration</option>
                        <option value="cinematic-realism">Cinematic live-action realism</option>
                        <option value="stop-motion">Tactile stop-motion</option>
                        <option value="custom">I have a style in mind…</option>
                    </select>
                    <div data-custom-style hidden><label class="sr-only" for="style_detail">Describe your visual style</label><input class="text-input input-secondary" id="style_detail" name="style_detail" type="text" maxlength="500" disabled placeholder="Describe the look, texture, and feel"></div>

                    <div class="setting-pair" data-story-only>
                        <div class="field-group"><label class="field-label" for="clip_count">Number of clips</label><div class="select-wrap"><select class="text-input select-input" id="clip_count" name="clip_count" required><option>2</option><option>3</option><option selected>4</option><option>5</option><option>6</option><option>7</option><option>8</option></select><span class="select-suffix">SCENES</span></div></div>
                        <div class="field-group"><label class="field-label" for="clip_duration">Seconds per clip</label><div class="select-wrap"><select class="text-input select-input" id="clip_duration" name="clip_duration" required><option value="4">4 seconds</option><option value="6">6 seconds</option><option value="8" selected>8 seconds</option><option value="10">10 seconds</option><option value="12">12 seconds</option><option value="15">15 seconds</option><option value="20">20 seconds</option></select><span class="select-suffix">EACH</span></div></div>
                    </div>
                    <div class="field-group extend-duration" data-extend-only hidden><label class="field-label" for="extend_duration">Extension length</label><div class="select-wrap"><select class="text-input select-input" id="extend_duration" data-duration-copy><option value="4">4 seconds</option><option value="6">6 seconds</option><option value="8" selected>8 seconds</option><option value="10">10 seconds</option><option value="12">12 seconds</option><option value="15">15 seconds</option><option value="20">20 seconds</option></select><span class="select-suffix">NEW CLIP</span></div></div>

                    <div class="field-group"><label class="field-label" for="aspect_ratio">Frame shape</label><div class="aspect-options" role="group" aria-label="Video aspect ratio">
                        <label class="aspect-choice is-selected"><input type="radio" name="aspect_ratio" value="16:9" checked><span class="ratio-icon ratio-wide"></span><span>16:9</span></label>
                        <label class="aspect-choice"><input type="radio" name="aspect_ratio" value="9:16"><span class="ratio-icon ratio-tall"></span><span>9:16</span></label>
                        <label class="aspect-choice"><input type="radio" name="aspect_ratio" value="1:1"><span class="ratio-icon ratio-square"></span><span>1:1</span></label>
                        <label class="aspect-choice"><input type="radio" name="aspect_ratio" value="4:3"><span class="ratio-icon ratio-classic"></span><span>4:3</span></label>
                    </div></div>

                    <details class="advanced-settings" open>
                        <summary><span><i class="advanced-spark">✳</i> Sound &amp; voice</span><span class="summary-state">TUNE <b>⌄</b></span></summary>
                        <div class="advanced-body">
                            <div class="narration-choice"><span class="field-label">Narration</span><div class="toggle-options" role="group" aria-label="Narration preference">
                                <label class="toggle-choice is-active"><input type="radio" name="narration" value="1" checked><span>On</span></label>
                                <label class="toggle-choice"><input type="radio" name="narration" value="0"><span>Off</span></label>
                            </div></div>
                            <div class="voice-fields" data-voice-fields>
                                <div class="setting-pair"><div class="field-group"><label class="field-label" for="language">Language</label><select class="text-input select-input" id="language" name="language" required><option selected>English</option><option>Urdu</option><option>Spanish</option><option>French</option><option>Arabic</option><option>Hindi</option></select></div>
                                    <div class="field-group"><label class="field-label" for="narrator_voice">Voice</label><select class="text-input select-input" id="narrator_voice" name="narrator_voice" required><option value="feminine" selected>Feminine</option><option value="masculine">Masculine</option></select></div></div>
                                <div class="setting-pair"><div class="field-group"><label class="field-label" for="narrator_accent">Accent</label><select class="text-input select-input" id="narrator_accent" name="narrator_accent" required><option selected>American English</option><option>British English</option><option>Pakistani English</option><option>Australian English</option><option>Neutral</option></select></div>
                                    <div class="field-group"><label class="field-label" for="narrator_tone">Tone</label><select class="text-input select-input" id="narrator_tone" name="narrator_tone" required><option selected>warm and friendly</option><option>curious and lively</option><option>calm and thoughtful</option><option>confident and clear</option></select></div></div>
                            </div>
                            <div class="sound-toggles">
                                <label class="switch-row"><span><strong>Background music</strong><small>Soft, never competing with narration</small></span><input type="checkbox" name="music" value="1"><i class="switch-track"></i></label>
                                <label class="switch-row"><span><strong>Sound effects</strong><small>Only when they match the action</small></span><input type="checkbox" name="sound_effects" value="1" checked><i class="switch-track"></i></label>
                            </div>
                        </div>
                    </details>

                    <div class="field-group depth-group"><label class="field-label" for="educational_depth">Learning depth</label><select class="text-input select-input" id="educational_depth" name="educational_depth"><option value="clear" selected>Simple &amp; accessible</option><option value="balanced">Balanced detail</option><option value="deep-dive">A little more depth</option></select></div>
                </section>
            </div>

            <div class="form-submit-row">
                <div class="form-footnote"><span class="spark-small">✳</span><span>One thoughtful prompt at a time.<br><small>You'll get a complete, copy-ready plan.</small></span></div>
                <button class="button-primary" type="submit" data-submit-button>
                    <span class="button-copy" data-submit-label>Build my prompt kit</span><span class="button-icon" aria-hidden="true">↗</span>
                    <span class="button-loading" aria-hidden="true"><i></i><i></i><i></i></span>
                </button>
            </div>
        </form>

        <div class="generation-message" data-generation-message role="alert" hidden></div>
        <section class="results-section" data-results hidden aria-labelledby="results-heading">
            <div class="results-header"><div><span class="section-index">02 <i>/</i> YOUR COPY-READY PLAN</span><h2 id="results-heading">The story is in frame.</h2><p>Each prompt is self-contained. Copy a section, then take it straight to Flow.</p></div>
                <div class="results-actions"><button class="button-secondary" type="button" data-download-output><span aria-hidden="true">↓</span> Save .txt</button><button class="button-secondary button-copy-all" type="button" data-copy-all><span aria-hidden="true">▢</span> Copy all</button></div>
            </div>
            <div class="result-meta"><span class="result-status"><i></i> PROMPT KIT READY</span><span>TEXT ONLY · NO VIDEO GENERATED</span></div>
            <div class="output-content" data-output-content></div>
        </section>

        <section class="principles-row" aria-label="Prompt quality principles">
            <div><span class="principle-num">01</span><span><strong>Stable by design</strong><small>Character, world, and voice are locked in.</small></span></div>
            <div><span class="principle-num">02</span><span><strong>Every cut accounted for</strong><small>Clear opening and ending states for each clip.</small></span></div>
            <div><span class="principle-num">03</span><span><strong>Made for real workflows</strong><small>Standalone prompts, ready for Google Flow.</small></span></div>
        </section>
    </section>
</div>
@endsection
