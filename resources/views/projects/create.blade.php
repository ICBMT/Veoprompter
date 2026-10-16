@extends('layouts.app')

@section('title', 'Create project — VeoPrompter')
@section('eyebrow', 'NEW PROJECT')

@section('content')
<div class="project-settings-page project-wizard-page">
    <a class="history-back" href="{{ route('projects.index', [], false) }}"><span aria-hidden="true">←</span> Back to projects</a>
    <section class="project-settings-hero"><div><span class="section-index">YOUR WORKSPACE <i>/</i> PROJECT SETUP</span><h1>Let’s set up your project.</h1><p>We’ll make one choice at a time. Your preferences are saved together, and you can change any of them later in Edit settings.</p></div><span class="project-settings-mark" aria-hidden="true">✳</span></section>

    <form class="project-settings-form project-wizard" action="{{ route('projects.store', [], false) }}" method="post" novalidate data-project-wizard>
        @csrf
        @include('components.validation-summary')

        <div class="wizard-progress" aria-label="Project setup progress">
            <div class="wizard-progress-heading"><span class="section-index" data-wizard-step-count>STEP 1</span><strong data-wizard-step-group>PROJECT BASICS</strong></div>
            <div class="wizard-progress-track" role="progressbar" aria-label="Project setup progress" aria-valuemin="1" aria-valuemax="19" aria-valuenow="1"><span data-wizard-progress></span></div>
            <p data-wizard-progress-note>One small choice at a time. You can go back whenever you like.</p>
        </div>
        <p class="wizard-validation" data-wizard-validation role="alert" aria-live="assertive" hidden></p>
        <span class="sr-only" data-wizard-announcement aria-live="polite"></span>

        <div class="wizard-stage">
            <section class="wizard-step" data-wizard-step data-wizard-group="PROJECT BASICS" data-wizard-title="Name your project">
                <div class="wizard-question"><span class="wizard-question-number" data-wizard-number>01</span><h2 tabindex="-1">What should we call this project?</h2><p>Choose a name that helps you find this creative workspace again.</p></div>
                <div class="wizard-answer wizard-answer--narrow"><label class="field-label" for="name">Project name</label><input class="text-input input-large" id="name" name="name" type="text" maxlength="100" required value="{{ old('name') }}" placeholder="For example, Ocean science stories" autofocus><span class="field-hint">You can rename it later.</span></div>
            </section>

            <section class="wizard-step" data-wizard-step data-wizard-group="PROJECT BASICS" data-wizard-title="Choose your audience">
                <div class="wizard-question"><span class="wizard-question-number" data-wizard-number>02</span><h2 tabindex="-1">Who are these videos for?</h2><p>This helps choose the right vocabulary, pacing, and level of explanation.</p></div>
                <div class="wizard-answer wizard-answer--narrow"><label class="field-label" for="audience">Your audience</label><input class="text-input input-large" id="audience" name="audience" type="text" maxlength="160" required value="{{ old('audience', $settings['audience']) }}" placeholder="Curious kids, new learners, or science fans"><span class="field-hint">A short description is plenty.</span></div>
            </section>

            <section class="wizard-step" data-wizard-step data-wizard-group="LOOK &amp; CHARACTER" data-wizard-title="Choose a visual style">
                <div class="wizard-question"><span class="wizard-question-number" data-wizard-number>03</span><h2 tabindex="-1">What should your project look like?</h2><p>Choose the visual language you want to keep consistent from prompt to prompt.</p></div>
                <div class="wizard-choice-grid wizard-choice-grid--two">
                    <label class="wizard-choice"><input type="radio" id="visual_style" name="visual_style" value="3d-family-animation" data-summary-label="Polished 3D family animation" @checked(old('visual_style', $settings['visual_style']) === '3d-family-animation') required><span class="wizard-choice-icon">◈</span><span class="wizard-choice-title">Polished 3D animation</span><span class="wizard-choice-description">Rounded characters, dimensional scenes, and polished lighting.</span></label>
                    <label class="wizard-choice"><input type="radio" name="visual_style" value="2d-illustration" data-summary-label="Expressive 2D illustration" @checked(old('visual_style', $settings['visual_style']) === '2d-illustration')><span class="wizard-choice-icon">✎</span><span class="wizard-choice-title">Expressive 2D illustration</span><span class="wizard-choice-description">Drawn shapes, graphic compositions, and illustration textures.</span></label>
                    <label class="wizard-choice"><input type="radio" name="visual_style" value="cinematic-realism" data-summary-label="Cinematic live-action realism" @checked(old('visual_style', $settings['visual_style']) === 'cinematic-realism')><span class="wizard-choice-icon">◉</span><span class="wizard-choice-title">Cinematic realism</span><span class="wizard-choice-description">Natural materials, believable light, and a film-like camera.</span></label>
                    <label class="wizard-choice"><input type="radio" name="visual_style" value="stop-motion" data-summary-label="Tactile stop-motion" @checked(old('visual_style', $settings['visual_style']) === 'stop-motion')><span class="wizard-choice-icon">▧</span><span class="wizard-choice-title">Stop-motion</span><span class="wizard-choice-description">Handmade textures and a crafted, frame-by-frame feel.</span></label>
                    <label class="wizard-choice wizard-choice--custom"><input type="radio" name="visual_style" value="custom" data-summary-label="Custom visual style" @checked(old('visual_style', $settings['visual_style']) === 'custom')><span class="wizard-choice-icon">✳</span><span class="wizard-choice-title">I have my own style</span><span class="wizard-choice-description">Describe a look, palette, texture, or medium of your choice.</span></label>
                </div>
            </section>

            <section class="wizard-step" data-wizard-step data-wizard-group="LOOK &amp; CHARACTER" data-wizard-title="Describe your custom style" data-wizard-when="visual_style:custom">
                <div class="wizard-question"><span class="wizard-question-number" data-wizard-number>04</span><h2 tabindex="-1">Describe the visual style.</h2><p>Tell us what the custom look should feel like. A few details are enough.</p></div>
                <div class="wizard-answer wizard-answer--narrow" data-custom-style><label class="field-label" for="style_detail">Your style description</label><textarea class="text-input textarea-large" id="style_detail" name="style_detail" rows="4" maxlength="500" placeholder="For example, soft paper-cut textures, deep ocean blues, and warm storybook lighting">{{ old('style_detail', $settings['style_detail']) }}</textarea><span class="field-hint">This description will be reused across your project.</span></div>
            </section>

            <section class="wizard-step" data-wizard-step data-wizard-group="LOOK &amp; CHARACTER" data-wizard-title="Choose a main character">
                <div class="wizard-question"><span class="wizard-question-number" data-wizard-number>05</span><h2 tabindex="-1">Who should lead your stories?</h2><p>We’ll keep this character type consistent across new prompt kits.</p></div>
                <div class="wizard-choice-grid wizard-choice-grid--two">
                    <label class="wizard-choice"><input type="radio" name="character_type" value="ai-choice" data-character-type-select data-summary-label="Let the AI choose" @checked(old('character_type', $settings['character_type']) === 'ai-choice') required><span class="wizard-choice-icon">✳</span><span class="wizard-choice-title">Let the AI choose</span><span class="wizard-choice-description">It will invent a memorable character to fit each topic.</span></label>
                    <label class="wizard-choice"><input type="radio" name="character_type" value="human-male" data-character-type-select data-summary-label="Human male" @checked(old('character_type', $settings['character_type']) === 'human-male')><span class="wizard-choice-icon">◉</span><span class="wizard-choice-title">Human male</span><span class="wizard-choice-description">A boy or man as the recurring lead character.</span></label>
                    <label class="wizard-choice"><input type="radio" name="character_type" value="human-female" data-character-type-select data-summary-label="Human female" @checked(old('character_type', $settings['character_type']) === 'human-female')><span class="wizard-choice-icon">◉</span><span class="wizard-choice-title">Human female</span><span class="wizard-choice-description">A girl or woman as the recurring lead character.</span></label>
                    <label class="wizard-choice"><input type="radio" name="character_type" value="robot" data-character-type-select data-summary-label="Robot or animated machine" @checked(old('character_type', $settings['character_type']) === 'robot')><span class="wizard-choice-icon">▣</span><span class="wizard-choice-title">Robot or machine</span><span class="wizard-choice-description">An animated robot or machine as the familiar guide.</span></label>
                    <label class="wizard-choice"><input type="radio" name="character_type" value="fictional" data-character-type-select data-summary-label="Fictional character or creature" @checked(old('character_type', $settings['character_type']) === 'fictional')><span class="wizard-choice-icon">✧</span><span class="wizard-choice-title">Fictional character</span><span class="wizard-choice-description">A custom creature or character that you describe next.</span></label>
                </div>
            </section>

            <section class="wizard-step" data-wizard-step data-wizard-group="LOOK &amp; CHARACTER" data-wizard-title="Describe your character" data-wizard-when="character_type:fictional">
                <div class="wizard-question"><span class="wizard-question-number" data-wizard-number>06</span><h2 tabindex="-1">Tell us about your character.</h2><p>What do they look like, and what makes them memorable?</p></div>
                <div class="wizard-answer wizard-answer--narrow"><label class="field-label" for="character_details">Character description</label><textarea class="text-input textarea-large" id="character_details" name="character_details" rows="4" maxlength="500" @if(old('character_type', $settings['character_type']) === 'fictional') required @endif placeholder="For example, a shy moon fox with a silver tail and a curious, gentle personality">{{ old('character_details', $settings['character_details']) }}</textarea><span class="field-hint">This description becomes part of the project’s recurring character profile.</span></div>
            </section>

            <section class="wizard-step" data-wizard-step data-wizard-group="VIDEO FORMAT" data-wizard-title="Choose the number of clips">
                <div class="wizard-question"><span class="wizard-question-number" data-wizard-number>07</span><h2 tabindex="-1">How many clips should a new story have?</h2><p>Each clip becomes one connected scene in the prompt plan.</p></div>
                <div class="wizard-choice-grid wizard-choice-grid--four wizard-choice-grid--compact">
                    @for($count = 2; $count <= 8; $count++)
                        <label class="wizard-choice wizard-choice--number"><input type="radio" name="clip_count" value="{{ $count }}" data-summary-label="{{ $count }} clips" @checked((int) old('clip_count', $settings['clip_count']) === $count) @if($count === 2) required @endif><span class="wizard-choice-title">{{ $count }}</span><span class="wizard-choice-description">clips</span></label>
                    @endfor
                </div>
            </section>

            <section class="wizard-step" data-wizard-step data-wizard-group="VIDEO FORMAT" data-wizard-title="Choose story clip duration">
                <div class="wizard-question"><span class="wizard-question-number" data-wizard-number>08</span><h2 tabindex="-1">How long should each story clip be?</h2><p>This is the target duration for each scene in a new story.</p></div>
                <div class="wizard-choice-grid wizard-choice-grid--four wizard-choice-grid--compact">
                    @foreach([4,6,8,10,12,15,20] as $duration)
                        <label class="wizard-choice wizard-choice--number"><input type="radio" name="clip_duration" value="{{ $duration }}" data-summary-label="{{ $duration }} seconds per story clip" @checked((int) old('clip_duration', $settings['clip_duration']) === $duration) @if($duration === 4) required @endif><span class="wizard-choice-title">{{ $duration }}</span><span class="wizard-choice-description">seconds</span></label>
                    @endforeach
                </div>
            </section>

            <section class="wizard-step" data-wizard-step data-wizard-group="VIDEO FORMAT" data-wizard-title="Choose extension length">
                <div class="wizard-question"><span class="wizard-question-number" data-wizard-number>09</span><h2 tabindex="-1">How long should a video extension be?</h2><p>This can be different from the clips in a new story.</p></div>
                <div class="wizard-choice-grid wizard-choice-grid--four wizard-choice-grid--compact">
                    @foreach([4,6,8,10,12,15,20] as $duration)
                        <label class="wizard-choice wizard-choice--number"><input type="radio" name="extend_duration" value="{{ $duration }}" data-summary-label="{{ $duration }} seconds per extension" @checked((int) old('extend_duration', $settings['extend_duration']) === $duration) @if($duration === 4) required @endif><span class="wizard-choice-title">{{ $duration }}</span><span class="wizard-choice-description">seconds</span></label>
                    @endforeach
                </div>
            </section>

            <section class="wizard-step" data-wizard-step data-wizard-group="VIDEO FORMAT" data-wizard-title="Choose a frame shape">
                <div class="wizard-question"><span class="wizard-question-number" data-wizard-number>10</span><h2 tabindex="-1">Where will people watch your videos?</h2><p>Choose a frame shape that fits the screen or platform you have in mind.</p></div>
                <div class="aspect-options wizard-aspect-options" role="group" aria-label="Video aspect ratio">
                    @foreach(['16:9' => ['ratio-wide', 'Landscape', 'Wide frame for standard landscape video and desktop viewing.'], '9:16' => ['ratio-tall', 'Vertical', 'Tall frame for phone-first shorts and stories.'], '1:1' => ['ratio-square', 'Square', 'Balanced square frame for social feeds and flexible crops.'], '4:3' => ['ratio-classic', 'Classic', 'Classic television-style frame with a slightly taller image.']] as $ratio => [$icon, $label, $description])
                        <label class="aspect-choice @if(old('aspect_ratio', $settings['aspect_ratio']) === $ratio) is-selected @endif" data-tooltip="{{ $description }}" title="{{ $description }}"><input type="radio" name="aspect_ratio" value="{{ $ratio }}" data-summary-label="{{ $ratio }} · {{ $label }}" @checked(old('aspect_ratio', $settings['aspect_ratio']) === $ratio) @if($ratio === '16:9') required @endif><span class="ratio-icon {{ $icon }}"></span><span>{{ $ratio }}</span><span class="sr-only">{{ $label }}. {{ $description }}</span></label>
                    @endforeach
                </div>
            </section>

            <section class="wizard-step" data-wizard-step data-wizard-group="LEARNING STYLE" data-wizard-title="Choose learning depth">
                <div class="wizard-question"><span class="wizard-question-number" data-wizard-number>11</span><h2 tabindex="-1">How much detail should the lessons include?</h2><p>You can keep the explanation simple or add a little more depth.</p></div>
                <div class="wizard-choice-grid wizard-choice-grid--three">
                    <label class="wizard-choice"><input type="radio" name="educational_depth" value="clear" data-summary-label="Simple and accessible" @checked(old('educational_depth', $settings['educational_depth']) === 'clear') required><span class="wizard-choice-title">Simple &amp; accessible</span><span class="wizard-choice-description">An introductory explanation with familiar words and one clear learning point.</span></label>
                    <label class="wizard-choice"><input type="radio" name="educational_depth" value="balanced" data-summary-label="Balanced detail" @checked(old('educational_depth', $settings['educational_depth']) === 'balanced')><span class="wizard-choice-title">Balanced detail</span><span class="wizard-choice-description">Accessible language with useful context and a little more explanation.</span></label>
                    <label class="wizard-choice"><input type="radio" name="educational_depth" value="deep-dive" data-summary-label="A little more depth" @checked(old('educational_depth', $settings['educational_depth']) === 'deep-dive')><span class="wizard-choice-title">A little more depth</span><span class="wizard-choice-description">Richer details for curious viewers, while keeping the story understandable.</span></label>
                </div>
            </section>

            <section class="wizard-step" data-wizard-step data-wizard-group="SOUND &amp; VOICE" data-wizard-title="Choose narration">
                <div class="wizard-question"><span class="wizard-question-number" data-wizard-number>12</span><h2 tabindex="-1">Would you like a narrator?</h2><p>This tells the video prompt whether to include a voiceover. It does not add audio to a video.</p></div>
                <div class="wizard-choice-grid wizard-choice-grid--two">
                    <label class="wizard-choice"><input type="radio" name="narration" value="1" data-summary-label="Narration on" @checked(old('narration', $settings['narration']) === '1') required><span class="wizard-choice-icon">◖</span><span class="wizard-choice-title">Yes, include narration</span><span class="wizard-choice-description">Choose a language and voice style in the next few steps.</span></label>
                    <label class="wizard-choice"><input type="radio" name="narration" value="0" data-summary-label="Narration off" @checked(old('narration', $settings['narration']) === '0')><span class="wizard-choice-icon">◌</span><span class="wizard-choice-title">No narration</span><span class="wizard-choice-description">Keep the prompts focused on visuals and sound effects.</span></label>
                </div>
            </section>

            <div class="wizard-voice-steps voice-fields" data-voice-fields>
                <section class="wizard-step" data-wizard-step data-wizard-group="SOUND &amp; VOICE" data-wizard-title="Choose a language" data-wizard-when="narration:1">
                    <div class="wizard-question"><span class="wizard-question-number" data-wizard-number>13</span><h2 tabindex="-1">What language should the narrator speak?</h2><p>The preview uses a matching built-in device voice when one is available.</p></div>
                    <div class="wizard-choice-grid wizard-choice-grid--three">
                        @foreach(['English', 'Urdu', 'Spanish', 'French', 'Arabic', 'Hindi'] as $language)
                            <label class="wizard-choice wizard-choice--language"><input type="radio" name="language" value="{{ $language }}" data-summary-label="{{ $language }}" @checked(old('language', $settings['language']) === $language) @if($language === 'English') required @endif><span class="wizard-choice-icon">Aa</span><span class="wizard-choice-title">{{ $language }}</span><span class="wizard-choice-description">{{ $language === 'Urdu' ? 'Urdu voice sample' : $language.' voice sample' }}</span></label>
                        @endforeach
                    </div>
                </section>

                <section class="wizard-step" data-wizard-step data-wizard-group="SOUND &amp; VOICE" data-wizard-title="Choose a voice" data-wizard-when="narration:1">
                    <div class="wizard-question"><span class="wizard-question-number" data-wizard-number>14</span><h2 tabindex="-1">Which voice style do you prefer?</h2><p>The final voice depends on the video tool. This choice guides its narration prompt.</p></div>
                    <div class="wizard-choice-grid wizard-choice-grid--two">
                        <label class="wizard-choice"><input type="radio" name="narrator_voice" value="feminine" data-summary-label="Feminine voice" @checked(old('narrator_voice', $settings['narrator_voice']) === 'feminine') required><span class="wizard-choice-icon">◖</span><span class="wizard-choice-title">Feminine</span><span class="wizard-choice-description">A feminine-sounding narrator, matched approximately for the local preview.</span></label>
                        <label class="wizard-choice"><input type="radio" name="narrator_voice" value="masculine" data-summary-label="Masculine voice" @checked(old('narrator_voice', $settings['narrator_voice']) === 'masculine')><span class="wizard-choice-icon">◗</span><span class="wizard-choice-title">Masculine</span><span class="wizard-choice-description">A masculine-sounding narrator, matched approximately for the local preview.</span></label>
                    </div>
                </section>

                <section class="wizard-step" data-wizard-step data-wizard-group="SOUND &amp; VOICE" data-wizard-title="Choose an accent" data-wizard-when="narration:1">
                    <div class="wizard-question"><span class="wizard-question-number" data-wizard-number>15</span><h2 tabindex="-1">Which accent should the narrator use?</h2><p>If your device does not have the exact accent, the preview uses a close language match.</p></div>
                    <div class="wizard-choice-grid wizard-choice-grid--three">
                        @foreach(['American English', 'British English', 'Pakistani English', 'Australian English', 'Neutral'] as $accent)
                            <label class="wizard-choice wizard-choice--compact"><input type="radio" name="narrator_accent" value="{{ $accent }}" data-summary-label="{{ $accent }}" @checked(old('narrator_accent', $settings['narrator_accent']) === $accent) @if($accent === 'American English') required @endif><span class="wizard-choice-title">{{ $accent }}</span><span class="wizard-choice-description">{{ $accent === 'Pakistani English' ? 'Pakistani English; a fallback may be used for Urdu.' : $accent.' pronunciation' }}</span></label>
                        @endforeach
                    </div>
                </section>

                <section class="wizard-step" data-wizard-step data-wizard-group="SOUND &amp; VOICE" data-wizard-title="Choose a tone" data-wizard-when="narration:1">
                    <div class="wizard-question"><span class="wizard-question-number" data-wizard-number>16</span><h2 tabindex="-1">What should the narrator sound like?</h2><p>Tone shapes the delivery in the prompt and slightly changes the local preview.</p></div>
                    <div class="wizard-choice-grid wizard-choice-grid--two">
                        @foreach(['warm and friendly' => 'Gentle and welcoming, with a softer pace.', 'curious and lively' => 'Energetic and bright, with a quicker pace.', 'calm and thoughtful' => 'Slower and reflective for careful explanations.', 'confident and clear' => 'Steady and assured, with clear phrasing.'] as $tone => $description)
                            <label class="wizard-choice"><input type="radio" name="narrator_tone" value="{{ $tone }}" data-summary-label="{{ ucfirst($tone) }}" @checked(old('narrator_tone', $settings['narrator_tone']) === $tone) @if($tone === 'warm and friendly') required @endif><span class="wizard-choice-title">{{ ucfirst($tone) }}</span><span class="wizard-choice-description">{{ $description }}</span></label>
                        @endforeach
                    </div>
                    <div class="voice-preview-card wizard-voice-preview"><div><strong>Try a voice sample</strong><p>This sample uses your device’s built-in speech voice; it is not your final video voice.</p></div><button class="button-secondary voice-preview-button" type="button" data-voice-preview-button><span aria-hidden="true">▶</span> Preview voice</button><span class="voice-preview-status" data-voice-preview-status role="status" aria-live="polite"></span></div>
                </section>
            </div>

            <section class="wizard-step" data-wizard-step data-wizard-group="SOUND &amp; VOICE" data-wizard-title="Choose background music">
                <div class="wizard-question"><span class="wizard-question-number" data-wizard-number>17</span><h2 tabindex="-1">Should the videos include background music?</h2><p>This adds a music direction to your prompts. No audio is generated here.</p></div>
                <div class="wizard-choice-grid wizard-choice-grid--two">
                    <label class="wizard-choice"><input type="radio" name="music" value="1" data-summary-label="Include subtle background music" @checked((bool) old('music', $settings['music'])) required><span class="wizard-choice-icon">♫</span><span class="wizard-choice-title">Yes, add subtle music</span><span class="wizard-choice-description">Keep it soft and never let it compete with narration.</span></label>
                    <label class="wizard-choice"><input type="radio" name="music" value="0" data-summary-label="No background music" @checked(!(bool) old('music', $settings['music']))><span class="wizard-choice-icon">♪</span><span class="wizard-choice-title">No background music</span><span class="wizard-choice-description">Leave music out of the video prompt.</span></label>
                </div>
            </section>

            <section class="wizard-step" data-wizard-step data-wizard-group="SOUND &amp; VOICE" data-wizard-title="Choose sound effects">
                <div class="wizard-question"><span class="wizard-question-number" data-wizard-number>18</span><h2 tabindex="-1">Should the videos include sound effects?</h2><p>You can ask for small sounds that match the action, or leave them out.</p></div>
                <div class="wizard-choice-grid wizard-choice-grid--two">
                    <label class="wizard-choice"><input type="radio" name="sound_effects" value="1" data-summary-label="Include sound effects" @checked((bool) old('sound_effects', $settings['sound_effects'])) required><span class="wizard-choice-icon">⌁</span><span class="wizard-choice-title">Yes, use fitting effects</span><span class="wizard-choice-description">Use restrained sounds only when they match what is happening.</span></label>
                    <label class="wizard-choice"><input type="radio" name="sound_effects" value="0" data-summary-label="No sound effects" @checked(!(bool) old('sound_effects', $settings['sound_effects']))><span class="wizard-choice-icon">◌</span><span class="wizard-choice-title">No sound effects</span><span class="wizard-choice-description">Leave sound effects out of the video prompt.</span></label>
                </div>
            </section>

            <section class="wizard-step wizard-review-step" data-wizard-step data-wizard-group="READY TO CREATE" data-wizard-title="Review your project" data-wizard-review>
                <div class="wizard-question"><span class="wizard-question-number">✓</span><h2 tabindex="-1">Your project is ready to take shape.</h2><p>Here’s a quick look at the preferences you chose. You can edit these at any time.</p></div>
                <dl class="wizard-review-list">
                    @foreach(['name' => 'Project', 'audience' => 'Audience', 'visual_style' => 'Visual style', 'style_detail' => 'Custom style', 'character_type' => 'Main character', 'character_details' => 'Character description', 'clip_count' => 'Story clips', 'clip_duration' => 'Story clip duration', 'extend_duration' => 'Extension duration', 'aspect_ratio' => 'Frame shape', 'educational_depth' => 'Learning depth', 'narration' => 'Narration', 'language' => 'Language', 'narrator_voice' => 'Voice', 'narrator_accent' => 'Accent', 'narrator_tone' => 'Tone', 'music' => 'Background music', 'sound_effects' => 'Sound effects'] as $field => $label)
                        <div data-wizard-review-row="{{ $field }}"><dt>{{ $label }}</dt><dd data-wizard-summary-for="{{ $field }}">—</dd></div>
                    @endforeach
                </dl>
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
