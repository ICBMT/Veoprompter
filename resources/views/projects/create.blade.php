@extends('layouts.app')

@section('title', 'Create project — VeoPrompter')
@section('eyebrow', 'NEW PROJECT')

@section('content')
@php
    $videoTypeIcons = ['educational' => '✳', 'cinematic-story' => '▶', 'documentary' => '◉', 'brand-promo' => '◇', 'music-video' => '♫', 'travel' => '↗', 'custom' => '✦'];
    $durationStepOffset = (int) (!$durationOptions['clip_duration']) + (int) (!$durationOptions['extend_duration']);
    $videoTypeDescriptions = [
        'educational' => 'Clear, accurate explainers with a useful takeaway.',
        'cinematic-story' => 'Fiction, short films, character arcs, and movie scenes.',
        'documentary' => 'Fact-led stories, profiles, and real-world subjects.',
        'brand-promo' => 'Product showcases, launches, and brand films.',
        'music-video' => 'Rhythmic, image-led sequences shaped by a musical mood.',
        'travel' => 'Destination films, travel diaries, and atmospheric reels.',
        'custom' => 'Anything else — your creative direction leads.',
    ];
@endphp
<div class="project-settings-page project-wizard-page">
    <a class="history-back" href="{{ route('projects.index', [], false) }}"><span aria-hidden="true">←</span> Back to projects</a>
    <section class="project-settings-hero"><div><span class="section-index">YOUR WORKSPACE <i>/</i> PROJECT SETUP</span><h1>Let’s set up your project.</h1><p>We’ll make one choice at a time. Your preferences are saved together, and you can change any of them later in Edit settings.</p></div><span class="project-settings-mark" aria-hidden="true">✳</span></section>

    <form class="project-settings-form project-wizard" action="{{ route('projects.store', [], false) }}" method="post" novalidate data-project-wizard>
        @csrf
        @include('components.validation-summary')

        <div class="wizard-progress" aria-label="Project setup progress">
            <div class="wizard-progress-heading"><span class="section-index" data-wizard-step-count>STEP 1</span><strong data-wizard-step-group>PROJECT BASICS</strong></div>
            <div class="wizard-progress-track" role="progressbar" aria-label="Project setup progress" aria-valuemin="1" aria-valuemax="21" aria-valuenow="1"><span data-wizard-progress></span></div>
            <p data-wizard-progress-note>One small choice at a time. You can go back whenever you like.</p>
        </div>
        @if(!$durationOptions['clip_duration'] || !$durationOptions['extend_duration'])
            <div class="duration-policy-note" role="status"><span aria-hidden="true">◷</span><p><strong>AI-selected timing adapts to each beat.</strong> @if(!$durationOptions['clip_duration'] && !$durationOptions['extend_duration'])Simple clips stay short; complex action, transformations, camera moves, and speech can receive more room, up to 10 seconds, without filler.@elseif(!$durationOptions['clip_duration'])Simple beats stay brief; complex new-video clips can use more time, up to 10 seconds, without filler.@else Simple extensions stay brief; complex continuation action or speech can use more time, up to 10 seconds, without filler.@endif</p></div>
        @endif
        <p class="wizard-validation" data-wizard-validation role="alert" aria-live="assertive" hidden></p>
        <span class="sr-only" data-wizard-announcement aria-live="polite"></span>

        <div class="wizard-stage">
            <section class="wizard-step" data-wizard-step data-wizard-group="PROJECT BASICS" data-wizard-title="Name your project">
                <div class="wizard-question"><span class="wizard-question-number" data-wizard-number>01</span><h2 tabindex="-1">What should we call this project?</h2><p>Choose a name that helps you find this creative workspace again.</p></div>
                <div class="wizard-answer wizard-answer--narrow"><label class="field-label" for="name">Project name</label><input class="text-input input-large" id="name" name="name" type="text" maxlength="100" required value="{{ old('name') }}" placeholder="For example, Ocean science stories" autofocus><span class="field-hint">You can rename it later.</span></div>
            </section>

            <section class="wizard-step" data-wizard-step data-wizard-group="PROJECT BASICS" data-wizard-title="Choose your audience">
                <div class="wizard-question"><span class="wizard-question-number" data-wizard-number>02</span><h2 tabindex="-1">Who are these videos for?</h2><p>This helps choose vocabulary, pacing, and tone for the people who will watch.</p></div>
                <div class="wizard-answer wizard-answer--narrow"><label class="field-label" for="audience">Your audience</label><input class="text-input input-large" id="audience" name="audience" type="text" maxlength="160" required value="{{ old('audience', $settings['audience']) }}" placeholder="Curious kids, new learners, film fans"><span class="field-hint">A short description is plenty.</span></div>
            </section>

            <section class="wizard-step" data-wizard-step data-wizard-group="PROJECT BASICS" data-wizard-title="Choose a video type">
                <div class="wizard-question"><span class="wizard-question-number" data-wizard-number>03</span><h2 tabindex="-1">What kind of videos do you want to make?</h2><p>Choose a default. You can switch formats for an individual prompt later.</p></div>
                <div class="wizard-choice-grid wizard-choice-grid--three">
                    @foreach($videoTypes as $type => $label)
                        <label class="wizard-choice wizard-choice--format"><input type="radio" name="video_type" value="{{ $type }}" data-summary-label="{{ $label }}" @checked(old('video_type', $settings['video_type']) === $type) @if($type === 'educational') required @endif><span class="wizard-choice-icon" aria-hidden="true">{{ $videoTypeIcons[$type] ?? '✳' }}</span><span class="wizard-choice-title">{{ $label }}</span><span class="wizard-choice-description">{{ $videoTypeDescriptions[$type] ?? 'Describe the kind of video you have in mind.' }}</span></label>
                    @endforeach
                </div>
            </section>

            <section class="wizard-step" data-wizard-step data-wizard-group="LOOK &amp; CHARACTER" data-wizard-title="Choose a visual style">
                <div class="wizard-question"><span class="wizard-question-number" data-wizard-number>04</span><h2 tabindex="-1">What should your project look like?</h2><p>Choose the visual language you want to keep consistent from prompt to prompt.</p></div>
                <div class="wizard-choice-grid wizard-choice-grid--visual-styles">
                    <label class="wizard-choice wizard-choice--visual"><input type="radio" id="visual_style" name="visual_style" value="3d-family-animation" data-summary-label="Polished 3D family animation" @checked(old('visual_style', $settings['visual_style']) === '3d-family-animation') required><span class="wizard-choice-media"><img src="{{ asset('images/project-wizard/style-3d.jpg') }}" alt="" loading="lazy" decoding="async"></span><span class="wizard-choice-title">Polished 3D animation</span><span class="wizard-choice-description">Rounded characters, dimensional scenes, and polished lighting.</span></label>
                    <label class="wizard-choice wizard-choice--visual"><input type="radio" name="visual_style" value="disney-pixar-inspired" data-summary-label="Disney/Pixar-style 3D animation" @checked(old('visual_style', $settings['visual_style']) === 'disney-pixar-inspired')><span class="wizard-choice-media"><img src="{{ asset('images/project-wizard/style-stylized-3d-family.jpg') }}" alt="" loading="lazy" decoding="async"></span><span class="wizard-choice-title">Disney/Pixar-style 3D</span><span class="wizard-choice-description">Expressive characters, rich worlds, and warm feature-animation polish.</span></label>
                    <label class="wizard-choice wizard-choice--visual"><input type="radio" name="visual_style" value="japanese-anime" data-summary-label="Japanese anime-style animation" @checked(old('visual_style', $settings['visual_style']) === 'japanese-anime')><span class="wizard-choice-media"><img src="{{ asset('images/project-wizard/style-japanese-anime.jpg') }}" alt="" loading="lazy" decoding="async"></span><span class="wizard-choice-title">Japanese anime style</span><span class="wizard-choice-description">Expressive linework, nuanced emotion, and cinematic painted backgrounds.</span></label>
                    <label class="wizard-choice wizard-choice--visual"><input type="radio" name="visual_style" value="2d-illustration" data-summary-label="Expressive 2D illustration" @checked(old('visual_style', $settings['visual_style']) === '2d-illustration')><span class="wizard-choice-media"><img src="{{ asset('images/project-wizard/style-2d.jpg') }}" alt="" loading="lazy" decoding="async"></span><span class="wizard-choice-title">Expressive 2D illustration</span><span class="wizard-choice-description">Drawn shapes, graphic compositions, and illustration textures.</span></label>
                    <label class="wizard-choice wizard-choice--visual"><input type="radio" name="visual_style" value="cinematic-realism" data-summary-label="Cinematic live-action realism" @checked(old('visual_style', $settings['visual_style']) === 'cinematic-realism')><span class="wizard-choice-media"><img src="{{ asset('images/project-wizard/style-cinematic.jpg') }}" alt="" loading="lazy" decoding="async"></span><span class="wizard-choice-title">Cinematic realism</span><span class="wizard-choice-description">Natural materials, believable light, and a film-like camera.</span></label>
                    <label class="wizard-choice wizard-choice--visual"><input type="radio" name="visual_style" value="stop-motion" data-summary-label="Tactile stop-motion" @checked(old('visual_style', $settings['visual_style']) === 'stop-motion')><span class="wizard-choice-media"><img src="{{ asset('images/project-wizard/style-stop-motion.jpg') }}" alt="" loading="lazy" decoding="async"></span><span class="wizard-choice-title">Stop-motion</span><span class="wizard-choice-description">Handmade textures and a crafted, frame-by-frame feel.</span></label>
                    <label class="wizard-choice wizard-choice--visual wizard-choice--custom"><input type="radio" name="visual_style" value="custom" data-summary-label="Custom visual style" @checked(old('visual_style', $settings['visual_style']) === 'custom')><span class="wizard-choice-icon">✳</span><span class="wizard-choice-title">I have my own style</span><span class="wizard-choice-description">Describe a look, palette, texture, or medium of your choice.</span></label>
                </div>
            </section>

            <section class="wizard-step" data-wizard-step data-wizard-group="LOOK &amp; CHARACTER" data-wizard-title="Describe your custom style" data-wizard-when="visual_style:custom">
                <div class="wizard-question"><span class="wizard-question-number" data-wizard-number>05</span><h2 tabindex="-1">Describe the visual style.</h2><p>Tell us what the custom look should feel like. A few details are enough.</p></div>
                <div class="wizard-answer wizard-answer--narrow" data-custom-style><label class="field-label" for="style_detail">Your style description</label><textarea class="text-input textarea-large" id="style_detail" name="style_detail" rows="4" maxlength="500" placeholder="For example, soft paper-cut textures, deep ocean blues, and warm storybook lighting">{{ old('style_detail', $settings['style_detail']) }}</textarea><span class="field-hint">This description will be reused across your project.</span></div>
            </section>

            <section class="wizard-step" data-wizard-step data-wizard-group="LOOK &amp; CHARACTER" data-wizard-title="Choose a main character">
                <div class="wizard-question"><span class="wizard-question-number" data-wizard-number>06</span><h2 tabindex="-1">What should be the recurring focus?</h2><p>Any character that appears gets a canonical identity repeated in each clip and shot; the prompt avoids unplanned extras, duplicates, or substitutes. When a character does not suit the format, the video can focus on its product, place, or visual subject instead.</p></div>
                <div class="wizard-choice-grid wizard-choice-grid--two">
                    <label class="wizard-choice"><input type="radio" name="character_type" value="ai-choice" data-character-type-select data-summary-label="Let the AI choose" @checked(old('character_type', $settings['character_type']) === 'ai-choice') required><span class="wizard-choice-icon">✳</span><span class="wizard-choice-title">Let the AI choose</span><span class="wizard-choice-description">It will add a fitting character when one helps; otherwise the video stays focused on its main subject.</span></label>
                    <label class="wizard-choice"><input type="radio" name="character_type" value="human-male" data-character-type-select data-summary-label="Human male" @checked(old('character_type', $settings['character_type']) === 'human-male')><span class="wizard-choice-icon">◉</span><span class="wizard-choice-title">Human male</span><span class="wizard-choice-description">A boy or man as the recurring lead character.</span></label>
                    <label class="wizard-choice"><input type="radio" name="character_type" value="human-female" data-character-type-select data-summary-label="Human female" @checked(old('character_type', $settings['character_type']) === 'human-female')><span class="wizard-choice-icon">◉</span><span class="wizard-choice-title">Human female</span><span class="wizard-choice-description">A girl or woman as the recurring lead character.</span></label>
                    <label class="wizard-choice"><input type="radio" name="character_type" value="robot" data-character-type-select data-summary-label="Robot or animated machine" @checked(old('character_type', $settings['character_type']) === 'robot')><span class="wizard-choice-icon">▣</span><span class="wizard-choice-title">Robot or machine</span><span class="wizard-choice-description">A robot or animated machine when the video benefits from a recurring character.</span></label>
                    <label class="wizard-choice"><input type="radio" name="character_type" value="fictional" data-character-type-select data-summary-label="Fictional character or creature" @checked(old('character_type', $settings['character_type']) === 'fictional')><span class="wizard-choice-icon">✧</span><span class="wizard-choice-title">Fictional character</span><span class="wizard-choice-description">A custom creature or character that you describe next.</span></label>
                </div>
            </section>

            <section class="wizard-step" data-wizard-step data-wizard-group="LOOK &amp; CHARACTER" data-wizard-title="Describe your character" data-wizard-when="character_type:fictional">
                <div class="wizard-question"><span class="wizard-question-number" data-wizard-number>07</span><h2 tabindex="-1">Tell us about your character.</h2><p>What do they look like, and what makes them memorable?</p></div>
                <div class="wizard-answer wizard-answer--narrow"><label class="field-label" for="character_details">Character description</label><textarea class="text-input textarea-large" id="character_details" name="character_details" rows="4" maxlength="500" @if(old('character_type', $settings['character_type']) === 'fictional') required @endif placeholder="For example, a shy moon fox with a silver tail and a curious, gentle personality">{{ old('character_details', $settings['character_details']) }}</textarea><span class="field-hint">This becomes the canonical identity: its defining features are repeated in every clip and shot, and it will not be swapped, duplicated, or changed unless a story change is planned.</span></div>
            </section>

            <section class="wizard-step" data-wizard-step data-wizard-group="VIDEO FORMAT" data-wizard-title="Choose the number of clips">
                <div class="wizard-question"><span class="wizard-question-number" data-wizard-number>08</span><h2 tabindex="-1">How many clips should this video have?</h2><p>Choose a fixed count, let AI use the minimum practical number, or let it use more clips when that improves logical flow and visual quality. Neither AI choice has an eight-clip cap.</p></div>
                <div class="wizard-choice-grid wizard-choice-grid--four wizard-choice-grid--compact">
                    <label class="wizard-choice wizard-choice--number wizard-choice--ai"><input type="radio" name="clip_count" value="auto" data-summary-label="Let AI decide · minimum practical count" @checked((string) old('clip_count', $settings['clip_count']) === 'auto') required><span class="wizard-choice-title">AI decides · minimum</span><span class="wizard-choice-description">Minimum practical number</span></label>
                    <label class="wizard-choice wizard-choice--number wizard-choice--ai"><input type="radio" name="clip_count" value="quality" data-summary-label="Let AI decide · maximum quality" @checked((string) old('clip_count', $settings['clip_count']) === 'quality')><span class="wizard-choice-title">AI decides · maximum quality</span><span class="wizard-choice-description">Separate important stages for clarity</span></label>
                    @for($count = 2; $count <= 8; $count++)
                        <label class="wizard-choice wizard-choice--number"><input type="radio" name="clip_count" value="{{ $count }}" data-summary-label="{{ $count }} clips" @checked((int) old('clip_count', $settings['clip_count']) === $count)><span class="wizard-choice-title">{{ $count }}</span><span class="wizard-choice-description">clips</span></label>
                    @endfor
                </div>
            </section>

            @if($durationOptions['clip_duration'])
                <section class="wizard-step" data-wizard-step data-wizard-group="VIDEO FORMAT" data-wizard-title="Choose video clip duration">
                    <div class="wizard-question"><span class="wizard-question-number" data-wizard-number>09</span><h2 tabindex="-1">How long should each video clip be?</h2><p>Choose a fixed length, or let AI match the time to each beat: simple actions stay short, while coordinated moves, transformations, camera work, and speech can use more time, up to 10 seconds, without padding.</p></div>
                    <div class="wizard-choice-grid wizard-choice-grid--four wizard-choice-grid--compact">
                        <label class="wizard-choice wizard-choice--number wizard-choice--ai"><input type="radio" name="clip_duration" value="auto" data-summary-label="AI selects a complexity-matched duration · up to 10 seconds" @checked((string) old('clip_duration', $settings['clip_duration']) === 'auto') required><span class="wizard-choice-title">Let AI decide</span><span class="wizard-choice-description">Simple beats stay short · complex beats get up to 10 sec</span></label>
                        @foreach([4,6,8,10,12,15,20] as $duration)
                            <label class="wizard-choice wizard-choice--number"><input type="radio" name="clip_duration" value="{{ $duration }}" data-summary-label="{{ $duration }} seconds per video clip" @checked((string) old('clip_duration', $settings['clip_duration']) === (string) $duration)><span class="wizard-choice-title">{{ $duration }}</span><span class="wizard-choice-description">seconds</span></label>
                        @endforeach
                    </div>
                </section>
            @else
                <input type="hidden" name="clip_duration" value="auto" data-summary-label="AI selects a complexity-matched duration · up to 10 seconds per clip">
            @endif

            @if($durationOptions['extend_duration'])
                <section class="wizard-step" data-wizard-step data-wizard-group="VIDEO FORMAT" data-wizard-title="Choose extension length">
                    <div class="wizard-question"><span class="wizard-question-number" data-wizard-number>{{ str_pad((string) (10 - (int) (!$durationOptions['clip_duration'])), 2, '0', STR_PAD_LEFT) }}</span><h2 tabindex="-1">How long should a video extension be?</h2><p>Choose a fixed length, or let AI match the time to the continuation: simple actions stay brief and complex movement, camera work, or speech can take longer, up to 10 seconds without padding.</p></div>
                    <div class="wizard-choice-grid wizard-choice-grid--four wizard-choice-grid--compact">
                        <label class="wizard-choice wizard-choice--number wizard-choice--ai"><input type="radio" name="extend_duration" value="auto" data-summary-label="AI selects a complexity-matched extension · up to 10 seconds" @checked((string) old('extend_duration', $settings['extend_duration']) === 'auto') required><span class="wizard-choice-title">Let AI decide</span><span class="wizard-choice-description">Simple action stays brief · complex action gets more room</span></label>
                        @foreach([4,6,8,10,12,15,20] as $duration)
                            <label class="wizard-choice wizard-choice--number"><input type="radio" name="extend_duration" value="{{ $duration }}" data-summary-label="{{ $duration }} seconds per extension" @checked((string) old('extend_duration', $settings['extend_duration']) === (string) $duration)><span class="wizard-choice-title">{{ $duration }}</span><span class="wizard-choice-description">seconds</span></label>
                        @endforeach
                    </div>
                </section>
            @else
                <input type="hidden" name="extend_duration" value="auto" data-summary-label="AI selects a complexity-matched extension · up to 10 seconds">
            @endif

            <section class="wizard-step" data-wizard-step data-wizard-group="VIDEO FORMAT" data-wizard-title="Choose a frame shape">
                <div class="wizard-question"><span class="wizard-question-number" data-wizard-number>{{ str_pad((string) (11 - $durationStepOffset), 2, '0', STR_PAD_LEFT) }}</span><h2 tabindex="-1">Where will people watch your videos?</h2><p>Choose a frame shape that fits the screen or platform you have in mind.</p></div>
                <div class="aspect-options wizard-aspect-options" role="group" aria-label="Video aspect ratio">
                    @foreach(['16:9' => ['ratio-wide', 'Landscape', 'Wide frame for standard landscape video and desktop viewing.'], '9:16' => ['ratio-tall', 'Vertical', 'Tall frame for phone-first shorts and stories.'], '1:1' => ['ratio-square', 'Square', 'Balanced square frame for social feeds and flexible crops.'], '4:3' => ['ratio-classic', 'Classic', 'Classic television-style frame with a slightly taller image.']] as $ratio => [$icon, $label, $description])
                        <label class="aspect-choice @if(old('aspect_ratio', $settings['aspect_ratio']) === $ratio) is-selected @endif" data-tooltip="{{ $description }}" title="{{ $description }}"><input type="radio" name="aspect_ratio" value="{{ $ratio }}" data-summary-label="{{ $ratio }} · {{ $label }}" @checked(old('aspect_ratio', $settings['aspect_ratio']) === $ratio) @if($ratio === '16:9') required @endif><span class="ratio-icon {{ $icon }}"></span><span>{{ $ratio }}</span><span class="sr-only">{{ $label }}. {{ $description }}</span></label>
                    @endforeach
                </div>
            </section>

            <section class="wizard-step" data-wizard-step data-wizard-group="LEARNING STYLE" data-wizard-title="Choose learning depth" data-wizard-when="video_type:educational">
                <div class="wizard-question"><span class="wizard-question-number" data-wizard-number>{{ str_pad((string) (12 - $durationStepOffset), 2, '0', STR_PAD_LEFT) }}</span><h2 tabindex="-1">How much detail should the explanations include?</h2><p>You can keep the explanation simple or add a little more depth.</p></div>
                <div class="wizard-choice-grid wizard-choice-grid--three">
                    <label class="wizard-choice"><input type="radio" name="educational_depth" value="clear" data-summary-label="Simple and accessible" @checked(old('educational_depth', $settings['educational_depth']) === 'clear') required><span class="wizard-choice-title">Simple &amp; accessible</span><span class="wizard-choice-description">An introductory explanation with familiar words and one clear learning point.</span></label>
                    <label class="wizard-choice"><input type="radio" name="educational_depth" value="balanced" data-summary-label="Balanced detail" @checked(old('educational_depth', $settings['educational_depth']) === 'balanced')><span class="wizard-choice-title">Balanced detail</span><span class="wizard-choice-description">Accessible language with useful context and a little more explanation.</span></label>
                    <label class="wizard-choice"><input type="radio" name="educational_depth" value="deep-dive" data-summary-label="A little more depth" @checked(old('educational_depth', $settings['educational_depth']) === 'deep-dive')><span class="wizard-choice-title">A little more depth</span><span class="wizard-choice-description">Richer details for curious viewers, while keeping the story understandable.</span></label>
                </div>
            </section>

            <section class="wizard-step" data-wizard-step data-wizard-group="SOUND &amp; VOICE" data-wizard-title="Choose spoken audio">
                <div class="wizard-question"><span class="wizard-question-number" data-wizard-number>{{ str_pad((string) (13 - $durationStepOffset), 2, '0', STR_PAD_LEFT) }}</span><h2 tabindex="-1">Should there be spoken audio?</h2><p>Choose no speech, or decide whether an off-screen narrator or on-screen characters should speak. Every line stays tied to an action visibly shown in that clip; invisible processes need a clear same-clip visualization.</p></div>
                <div class="wizard-choice-grid wizard-choice-grid--two">
                    <label class="wizard-choice"><input type="radio" name="narration" value="1" data-summary-label="Spoken audio on" @checked(old('narration', $settings['narration']) === '1') required><span class="wizard-choice-icon">◖</span><span class="wizard-choice-title">Yes, include speech</span><span class="wizard-choice-description">Choose voice-over narration or character dialogue in the next step.</span></label>
                    <label class="wizard-choice"><input type="radio" name="narration" value="0" data-summary-label="No spoken audio" @checked(old('narration', $settings['narration']) === '0')><span class="wizard-choice-icon">◌</span><span class="wizard-choice-title">No spoken audio</span><span class="wizard-choice-description">No narrator or character speech; keep visuals, music, and effects as selected.</span></label>
                </div>
            </section>

            <section class="wizard-step" data-wizard-step data-wizard-group="SOUND &amp; VOICE" data-wizard-title="Choose who speaks" data-wizard-when="narration:1">
                <div class="wizard-question"><span class="wizard-question-number" data-wizard-number>{{ str_pad((string) (14 - $durationStepOffset), 2, '0', STR_PAD_LEFT) }}</span><h2 tabindex="-1">Who should speak?</h2><p>Choose one speaking style. Each character voice is defined from that character’s description and kept distinct across clips.</p></div>
                <div class="wizard-choice-grid wizard-choice-grid--two">
                    <label class="wizard-choice"><input type="radio" name="speech_mode" value="voiceover" data-summary-label="Voice-over narrator" @checked(old('speech_mode', $settings['speech_mode'] ?? 'voiceover') === 'voiceover') required><span class="wizard-choice-icon">◖</span><span class="wizard-choice-title">Voice-over narrator</span><span class="wizard-choice-description">One off-screen voice describes the visible action or result in that clip. Choose its language, voice, accent, and tone next.</span></label>
                    <label class="wizard-choice"><input type="radio" name="speech_mode" value="character-dialogue" data-summary-label="On-screen character dialogue" @checked(old('speech_mode', $settings['speech_mode'] ?? 'voiceover') === 'character-dialogue')><span class="wizard-choice-icon">◗</span><span class="wizard-choice-title">Characters speak in-scene</span><span class="wizard-choice-description">The named character stays on screen and speaks about the action visibly performed in that clip. Each speaker gets a distinct, stable voice identity; no narrator or single-voice TTS preview.</span></label>
                </div>
            </section>

            <div class="wizard-voice-steps voice-fields" data-voice-fields>
                <section class="wizard-step" data-wizard-step data-wizard-group="SOUND &amp; VOICE" data-wizard-title="Choose a language" data-wizard-when="narration:1">
                    <div class="wizard-question"><span class="wizard-question-number" data-wizard-number>{{ str_pad((string) (15 - $durationStepOffset), 2, '0', STR_PAD_LEFT) }}</span><h2 tabindex="-1">What language should they speak?</h2><p>This applies to voice-over or character dialogue. Gemini TTS samples are available only for the voice-over narrator.</p></div>
                    <div class="wizard-choice-grid wizard-choice-grid--three">
                        @foreach(['English', 'Urdu', 'Spanish', 'French', 'Arabic', 'Hindi'] as $language)
                            <label class="wizard-choice wizard-choice--language"><input type="radio" name="language" value="{{ $language }}" data-summary-label="{{ $language }}" @checked(old('language', $settings['language']) === $language) @if($language === 'English') required @endif><span class="wizard-choice-icon">Aa</span><span class="wizard-choice-title">{{ $language }}</span><span class="wizard-choice-description">{{ $language }} narration or dialogue</span></label>
                        @endforeach
                    </div>
                    <div class="voice-preview-card wizard-voice-preview" data-voiceover-only @if(old('speech_mode', $settings['speech_mode'] ?? 'voiceover') !== 'voiceover') hidden @endif><div><strong>Preview this language</strong><p>Generate a narrator sample in this language using the selected voice, accent, and tone. Character dialogue uses character-specific voice profiles instead.</p></div><button class="button-secondary voice-preview-button" type="button" data-voice-preview-button><span aria-hidden="true">▶</span> Preview sample</button><span class="voice-preview-status" data-voice-preview-status role="status" aria-live="polite"></span></div>
                </section>

                <section class="wizard-step" data-wizard-step data-wizard-group="SOUND &amp; VOICE" data-wizard-title="Choose a narrator voice" data-wizard-when="narration:1&amp;speech_mode:voiceover">
                    <div class="wizard-question"><span class="wizard-question-number" data-wizard-number>{{ str_pad((string) (16 - $durationStepOffset), 2, '0', STR_PAD_LEFT) }}</span><h2 tabindex="-1">Which narrator voice style do you prefer?</h2><p>The final voice depends on the video tool. This guides the single off-screen narrator.</p></div>
                    <div class="wizard-choice-grid wizard-choice-grid--two">
                        <label class="wizard-choice"><input type="radio" name="narrator_voice" value="feminine" data-summary-label="Feminine voice" @checked(old('narrator_voice', $settings['narrator_voice']) === 'feminine') required><span class="wizard-choice-icon">◖</span><span class="wizard-choice-title">Feminine</span><span class="wizard-choice-description">A feminine-presenting narrator voice; Gemini TTS can preview this voice.</span></label>
                        <label class="wizard-choice"><input type="radio" name="narrator_voice" value="masculine" data-summary-label="Masculine voice" @checked(old('narrator_voice', $settings['narrator_voice']) === 'masculine')><span class="wizard-choice-icon">◗</span><span class="wizard-choice-title">Masculine</span><span class="wizard-choice-description">A masculine-presenting narrator voice; Gemini TTS can preview this voice.</span></label>
                    </div>
                    <div class="voice-preview-card wizard-voice-preview" data-voiceover-only><div><strong>Preview this narrator voice</strong><p>Gemini TTS generates the selected narrator style with your current language, accent, and tone.</p></div><button class="button-secondary voice-preview-button" type="button" data-voice-preview-button><span aria-hidden="true">▶</span> Preview sample</button><span class="voice-preview-status" data-voice-preview-status role="status" aria-live="polite"></span></div>
                </section>

                <section class="wizard-step" data-wizard-step data-wizard-group="SOUND &amp; VOICE" data-wizard-title="Choose an accent" data-wizard-when="narration:1&amp;speech_mode:voiceover">
                    <div class="wizard-question"><span class="wizard-question-number" data-wizard-number>{{ str_pad((string) (17 - $durationStepOffset), 2, '0', STR_PAD_LEFT) }}</span><h2 tabindex="-1">Which accent should the narrator use?</h2><p>Accent choices guide English pronunciation. For other languages, the preview uses the closest available voice for that language.</p></div>
                    <div class="wizard-choice-grid wizard-choice-grid--three">
                        @foreach(['American English', 'British English', 'Pakistani English', 'Australian English', 'Neutral'] as $accent)
                            <label class="wizard-choice wizard-choice--compact"><input type="radio" name="narrator_accent" value="{{ $accent }}" data-summary-label="{{ $accent }}" @checked(old('narrator_accent', $settings['narrator_accent']) === $accent) @if($accent === 'American English') required @endif><span class="wizard-choice-title">{{ $accent }}</span><span class="wizard-choice-description">{{ $accent === 'Pakistani English' ? 'Pakistani pronunciation for English narration.' : $accent.' pronunciation' }}</span></label>
                        @endforeach
                    </div>
                    <div class="voice-preview-card wizard-voice-preview"><div><strong>Preview this accent</strong><p>The generated sample applies this English accent when compatible with the selected language.</p></div><button class="button-secondary voice-preview-button" type="button" data-voice-preview-button><span aria-hidden="true">▶</span> Preview sample</button><span class="voice-preview-status" data-voice-preview-status role="status" aria-live="polite"></span></div>
                </section>

                <section class="wizard-step" data-wizard-step data-wizard-group="SOUND &amp; VOICE" data-wizard-title="Choose a tone" data-wizard-when="narration:1&amp;speech_mode:voiceover">
                    <div class="wizard-question"><span class="wizard-question-number" data-wizard-number>{{ str_pad((string) (18 - $durationStepOffset), 2, '0', STR_PAD_LEFT) }}</span><h2 tabindex="-1">What should the narrator sound like?</h2><p>Tone shapes the final prompt and the generated sample’s delivery.</p></div>
                    <div class="wizard-choice-grid wizard-choice-grid--two">
                        @foreach(['warm and friendly' => 'Gentle and welcoming, with a softer pace.', 'curious and lively' => 'Energetic and bright, with a quicker pace.', 'calm and thoughtful' => 'Slower and reflective for careful explanations.', 'confident and clear' => 'Steady and assured, with clear phrasing.'] as $tone => $description)
                            <label class="wizard-choice"><input type="radio" name="narrator_tone" value="{{ $tone }}" data-summary-label="{{ ucfirst($tone) }}" @checked(old('narrator_tone', $settings['narrator_tone']) === $tone) @if($tone === 'warm and friendly') required @endif><span class="wizard-choice-title">{{ ucfirst($tone) }}</span><span class="wizard-choice-description">{{ $description }}</span></label>
                        @endforeach
                    </div>
                    <div class="voice-preview-card wizard-voice-preview"><div><strong>Preview the complete setup</strong><p>Preview this exact combination. Its first sample is generated once, then cached for faster playback.</p></div><button class="button-secondary voice-preview-button" type="button" data-voice-preview-button><span aria-hidden="true">▶</span> Preview sample</button><span class="voice-preview-status" data-voice-preview-status role="status" aria-live="polite"></span></div>
                </section>
            </div>

            <section class="wizard-step" data-wizard-step data-wizard-group="SOUND &amp; VOICE" data-wizard-title="Choose background music">
                <div class="wizard-question"><span class="wizard-question-number" data-wizard-number>{{ str_pad((string) (19 - $durationStepOffset), 2, '0', STR_PAD_LEFT) }}</span><h2 tabindex="-1">Should the videos include background music?</h2><p>This adds a music direction to your prompts. No audio is generated here.</p></div>
                <div class="wizard-choice-grid wizard-choice-grid--two">
                    <label class="wizard-choice"><input type="radio" name="music" value="1" data-summary-label="Include subtle background music" @checked((bool) old('music', $settings['music'])) required><span class="wizard-choice-icon">♫</span><span class="wizard-choice-title">Yes, add subtle music</span><span class="wizard-choice-description">Keep it soft and never let it compete with dialogue or narration.</span></label>
                    <label class="wizard-choice"><input type="radio" name="music" value="0" data-summary-label="No background music" @checked(!(bool) old('music', $settings['music']))><span class="wizard-choice-icon">♪</span><span class="wizard-choice-title">No background music</span><span class="wizard-choice-description">Leave music out of the video prompt.</span></label>
                </div>
            </section>

            <section class="wizard-step" data-wizard-step data-wizard-group="SOUND &amp; VOICE" data-wizard-title="Choose sound effects">
                <div class="wizard-question"><span class="wizard-question-number" data-wizard-number>{{ str_pad((string) (20 - $durationStepOffset), 2, '0', STR_PAD_LEFT) }}</span><h2 tabindex="-1">Should the videos include sound effects?</h2><p>You can ask for small sounds that match the action, or leave them out.</p></div>
                <div class="wizard-choice-grid wizard-choice-grid--two">
                    <label class="wizard-choice"><input type="radio" name="sound_effects" value="1" data-summary-label="Include sound effects" @checked((bool) old('sound_effects', $settings['sound_effects'])) required><span class="wizard-choice-icon">⌁</span><span class="wizard-choice-title">Yes, use fitting effects</span><span class="wizard-choice-description">Use restrained sounds only when they match what is happening.</span></label>
                    <label class="wizard-choice"><input type="radio" name="sound_effects" value="0" data-summary-label="No sound effects" @checked(!(bool) old('sound_effects', $settings['sound_effects']))><span class="wizard-choice-icon">◌</span><span class="wizard-choice-title">No sound effects</span><span class="wizard-choice-description">Leave sound effects out of the video prompt.</span></label>
                </div>
            </section>

            <section class="wizard-step wizard-review-step" data-wizard-step data-wizard-group="READY TO CREATE" data-wizard-title="Review your project" data-wizard-review>
                <div class="wizard-question"><span class="wizard-question-number">✓</span><h2 tabindex="-1">Your project is ready to take shape.</h2><p>Here’s a quick look at the preferences you chose. You can edit these at any time.</p></div>
                <dl class="wizard-review-list">
                    @foreach(['name' => 'Project', 'audience' => 'Audience', 'video_type' => 'Default video type', 'visual_style' => 'Visual style', 'style_detail' => 'Custom style', 'character_type' => 'Main character', 'character_details' => 'Character description', 'clip_count' => 'Video clips', 'clip_duration' => 'Video clip duration', 'extend_duration' => 'Extension duration', 'aspect_ratio' => 'Frame shape', 'educational_depth' => 'Learning depth', 'narration' => 'Spoken audio', 'speech_mode' => 'Who speaks', 'language' => 'Language', 'narrator_voice' => 'Narrator voice', 'narrator_accent' => 'Narrator accent', 'narrator_tone' => 'Narrator tone', 'music' => 'Background music', 'sound_effects' => 'Sound effects'] as $field => $label)
                        <div data-wizard-review-row="{{ $field }}"><dt>{{ $label }}</dt><dd data-wizard-summary-for="{{ $field }}">—</dd></div>
                    @endforeach
                </dl>
                <div class="voice-preview-card wizard-voice-preview" data-wizard-review-voice-preview data-voiceover-only @if(old('narration', $settings['narration']) !== '1' || old('speech_mode', $settings['speech_mode'] ?? 'voiceover') !== 'voiceover') hidden @endif><div><strong>Hear your complete narrator setup</strong><p>Play your Gemini TTS narrator sample. It is generated on first request, then cached. Character-specific voices are defined in prompts instead.</p></div><button class="button-secondary voice-preview-button" type="button" data-voice-preview-button><span aria-hidden="true">▶</span> Preview sample</button><span class="voice-preview-status" data-voice-preview-status role="status" aria-live="polite"></span></div>
            </section>
        </div>

        <div class="wizard-navigation">
            <button class="button-secondary wizard-back-button" type="button" data-wizard-back hidden><span aria-hidden="true">←</span> Back</button>
            <span class="wizard-navigation-note" data-wizard-navigation-note>Choose an option, then continue.</span>
            <button class="button-primary wizard-next-button" type="button" data-wizard-next><span>Continue</span><span class="button-icon" aria-hidden="true">↗</span></button>
            <button class="button-primary wizard-submit-button" type="submit" data-wizard-submit><span>Create project</span><span class="button-icon" aria-hidden="true">↗</span></button>
        </div>
        <noscript><p class="wizard-noscript">JavaScript is off, so all project questions are displayed together. You can still complete this form and save your project.</p></noscript>
    </form>
</div>
@endsection
