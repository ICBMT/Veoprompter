<div class="form-grid project-settings-grid" data-project-settings-form>
    <section class="form-panel brief-panel">
        <div class="panel-heading"><span class="panel-step">A</span><div><h2>Visual identity</h2><p>Set the creative defaults every prompt in this project will reuse.</p></div></div>

        <div class="setting-field-block">
            <div class="field-label-row"><label class="field-label" for="audience">Who is it for?</label><button class="field-info" type="button" data-tooltip="Audience guides vocabulary, pacing, and how much background knowledge each prompt assumes." aria-label="Help: audience">i</button></div>
            <input class="text-input" id="audience" name="audience" type="text" maxlength="160" required value="{{ old('audience', $settings['audience']) }}" placeholder="Curious kids, new learners, or science fans">
            <span class="field-hint">Keep it specific to get the right vocabulary and pace.</span>
        </div>

        <div class="setting-field-block">
            <div class="field-label-row"><label class="field-label" for="video_type">Default video type</label><button class="field-info" type="button" data-tooltip="This is the project’s starting format. You can choose another video type for any individual prompt." aria-label="Help: default video type">i</button></div>
            <select class="text-input select-input" id="video_type" name="video_type" required>
                @foreach($videoTypes as $type => $label)
                    <option value="{{ $type }}" @selected(old('video_type', $settings['video_type'] ?? 'educational') === $type)>{{ $label }}</option>
                @endforeach
            </select>
            <span class="field-hint">Choose explainers, short films, documentaries, product videos, music videos, travel reels, or a custom direction.</span>
            @error('video_type')<p class="admin-error">{{ $message }}</p>@enderror
        </div>

        <div class="setting-field-block">
            <div class="field-label-row"><label class="field-label" for="visual_style">Visual style</label><button class="field-info" type="button" data-tooltip="This visual language is reused across the project, helping characters, lighting, and materials feel like one world." aria-label="Help: visual style">i</button></div>
            <select class="text-input select-input" id="visual_style" name="visual_style" required>
                <option value="3d-family-animation" title="Rounded, dimensional animation with polished lighting and family-friendly appeal." @selected(old('visual_style', $settings['visual_style']) === '3d-family-animation')>Polished 3D family animation</option>
                <option value="2d-illustration" title="Expressive drawn shapes, illustration textures, and flatter graphic compositions." @selected(old('visual_style', $settings['visual_style']) === '2d-illustration')>Expressive 2D illustration</option>
                <option value="cinematic-realism" title="Natural materials, believable lighting, and a live-action cinematic camera language." @selected(old('visual_style', $settings['visual_style']) === 'cinematic-realism')>Cinematic live-action realism</option>
                <option value="stop-motion" title="Handmade textures, tactile materials, and a crafted frame-by-frame feel." @selected(old('visual_style', $settings['visual_style']) === 'stop-motion')>Tactile stop-motion</option>
                <option value="custom" title="Describe your own look, texture, palette, or medium in the field below." @selected(old('visual_style', $settings['visual_style']) === 'custom')>I have a style in mind…</option>
            </select>
            <div data-custom-style @if(old('visual_style', $settings['visual_style']) !== 'custom') hidden @endif>
                <label class="sr-only" for="style_detail">Describe your visual style</label>
                <input class="text-input input-secondary" id="style_detail" name="style_detail" type="text" maxlength="500" value="{{ old('style_detail', $settings['style_detail']) }}" @if(old('visual_style', $settings['visual_style']) !== 'custom') disabled @endif placeholder="Describe the look, texture, and feel">
            </div>
            @error('visual_style')<p class="admin-error">{{ $message }}</p>@enderror
            @error('style_detail')<p class="admin-error">{{ $message }}</p>@enderror
        </div>

        <div class="setting-field-block">
            <div class="field-label-row"><label class="field-label" for="character_type">Main character type</label><button class="field-info" type="button" data-tooltip="When a video uses a recurring character, the project keeps its type consistent. Otherwise the AI can focus on the product, place, or primary visual subject." aria-label="Help: main character type">i</button></div>
            <select class="text-input select-input" id="character_type" name="character_type" required data-character-type-select>
                <option value="ai-choice" title="Let the model invent a topic-appropriate recurring character." @selected(old('character_type', $settings['character_type']) === 'ai-choice')>Let the AI choose</option>
                <option value="human-male" title="Keep the lead character a human man or boy; add age or appearance in the description field." @selected(old('character_type', $settings['character_type']) === 'human-male')>Human male</option>
                <option value="human-female" title="Keep the lead character a human woman or girl; add age or appearance in the description field." @selected(old('character_type', $settings['character_type']) === 'human-female')>Human female</option>
                <option value="robot" title="Use a robot or animated machine as the consistent lead character." @selected(old('character_type', $settings['character_type']) === 'robot')>Robot or animated machine</option>
                <option value="fictional" title="Create a custom fictional character or creature; a description is required below." @selected(old('character_type', $settings['character_type']) === 'fictional')>Fictional character or creature</option>
            </select>
            <label class="sr-only" for="character_details">Character description</label>
            <textarea class="text-input textarea-small" id="character_details" name="character_details" rows="3" maxlength="500" @if(old('character_type', $settings['character_type']) === 'fictional') required @endif placeholder="Describe appearance, personality, or a custom fictional character">{{ old('character_details', $settings['character_details']) }}</textarea>
            <span class="field-hint">Required for a fictional character; optional for the other choices.</span>
            @error('character_type')<p class="admin-error">{{ $message }}</p>@enderror
            @error('character_details')<p class="admin-error">{{ $message }}</p>@enderror
        </div>

        <div class="setting-pair project-setting-pair">
            <div class="field-group"><div class="field-label-row"><label class="field-label" for="clip_count">Number of clips</label><button class="field-info" type="button" data-tooltip="New video prompt kits in this project will be planned as this many connected clips." aria-label="Help: number of clips">i</button></div><div class="select-wrap"><select class="text-input select-input" id="clip_count" name="clip_count" required>@for($count = 2; $count <= 8; $count++)<option value="{{ $count }}" title="Plan a connected video sequence across {{ $count }} clips." @selected((int) old('clip_count', $settings['clip_count']) === $count)>{{ $count }}</option>@endfor</select><span class="select-suffix">SCENES</span></div></div>
            <div class="field-group"><div class="field-label-row"><label class="field-label" for="clip_duration">Seconds per clip</label><button class="field-info" type="button" data-tooltip="Sets the target duration for each clip. Extension prompts use this saved duration too." aria-label="Help: clip duration">i</button></div><div class="select-wrap"><select class="text-input select-input" id="clip_duration" name="clip_duration" required>@foreach([4,6,8,10,12,15,20] as $duration)<option value="{{ $duration }}" title="Each video clip targets about {{ $duration }} seconds." @selected((int) old('clip_duration', $settings['clip_duration']) === $duration)>{{ $duration }} seconds</option>@endforeach</select><span class="select-suffix">EACH</span></div></div>
        </div>

        <div class="field-group project-extension-duration"><div class="field-label-row"><label class="field-label" for="extend_duration">Extension length</label><button class="field-info" type="button" data-tooltip="When you continue a video, this saved length is used for the next clip. It can differ from the clip length for a new video." aria-label="Help: extension length">i</button></div><div class="select-wrap"><select class="text-input select-input" id="extend_duration" name="extend_duration" required>@foreach([4,6,8,10,12,15,20] as $duration)<option value="{{ $duration }}" title="The next extension clip will target about {{ $duration }} seconds." @selected((int) old('extend_duration', $settings['extend_duration']) === $duration)>{{ $duration }} seconds</option>@endforeach</select><span class="select-suffix">NEXT CLIP</span></div></div>

        <div class="setting-field-block">
            <div class="field-label-row"><span class="field-label">Frame shape</span><button class="field-info" type="button" data-tooltip="Choose a canvas for the platform where you plan to publish: landscape, vertical, square, or classic video." aria-label="Help: frame shape">i</button></div>
            <div class="aspect-options" role="group" aria-label="Video aspect ratio">
                @foreach(['16:9' => ['ratio-wide', 'Landscape', 'Wide frame for standard landscape video and desktop viewing.'], '9:16' => ['ratio-tall', 'Vertical', 'Tall frame for phone-first shorts and stories.'], '1:1' => ['ratio-square', 'Square', 'Balanced square frame for social feeds and flexible crops.'], '4:3' => ['ratio-classic', 'Classic', 'Classic television-style frame with a slightly taller image.']] as $ratio => [$icon, $label, $description])
                    <label class="aspect-choice @if(old('aspect_ratio', $settings['aspect_ratio']) === $ratio) is-selected @endif" data-tooltip="{{ $description }}" title="{{ $description }}"><input type="radio" name="aspect_ratio" value="{{ $ratio }}" @checked(old('aspect_ratio', $settings['aspect_ratio']) === $ratio)><span class="ratio-icon {{ $icon }}"></span><span>{{ $ratio }}</span><span class="sr-only">{{ $label }}. {{ $description }}</span></label>
                @endforeach
            </div>
        </div>

        <div class="field-group depth-group"><div class="field-label-row"><label class="field-label" for="educational_depth">Learning depth · explainers</label><button class="field-info" type="button" data-tooltip="This setting applies to educational explainers only: simple keeps them introductory, balanced adds context, and a little more depth includes richer details." aria-label="Help: learning depth for explainers">i</button></div><select class="text-input select-input" id="educational_depth" name="educational_depth" required><option value="clear" title="An introductory explanation with familiar words and one clear learning point." @selected(old('educational_depth', $settings['educational_depth']) === 'clear')>Simple &amp; accessible</option><option value="balanced" title="Accessible language with a little more explanation and useful context." @selected(old('educational_depth', $settings['educational_depth']) === 'balanced')>Balanced detail</option><option value="deep-dive" title="Richer details for curious viewers, while keeping the story understandable." @selected(old('educational_depth', $settings['educational_depth']) === 'deep-dive')>A little more depth</option></select></div>
    </section>

    <section class="form-panel recipe-panel">
        <div class="panel-heading"><span class="panel-step">B</span><div><h2>Sound &amp; voice</h2><p>Keep the narrator and sound direction consistent.</p></div></div>

        <div class="narration-choice"><div class="field-label-row"><span class="field-label">Narration</span><button class="field-info" type="button" data-tooltip="This setting guides the generated video prompts. It does not synthesize or add narration to a video." aria-label="Help: narration">i</button></div><div class="toggle-options" role="group" aria-label="Narration preference">
            <label class="toggle-choice @if(old('narration', $settings['narration']) === '1') is-active @endif"><input type="radio" name="narration" value="1" @checked(old('narration', $settings['narration']) === '1')><span>On</span></label>
            <label class="toggle-choice @if(old('narration', $settings['narration']) === '0') is-active @endif"><input type="radio" name="narration" value="0" @checked(old('narration', $settings['narration']) === '0')><span>Off</span></label>
        </div></div>

        <div class="voice-fields" data-voice-fields>
            <div class="setting-pair"><div class="field-group"><div class="field-label-row"><label class="field-label" for="language">Language</label><button class="field-info" type="button" data-tooltip="Selects the narrator language for the prompt and the generated Google TTS sample." aria-label="Help: language">i</button></div><select class="text-input select-input" id="language" name="language"><option title="English narration; accent below is applied to the generated preview." @selected(old('language', $settings['language']) === 'English')>English</option><option title="Urdu narration with a generated Gemini TTS preview." @selected(old('language', $settings['language']) === 'Urdu')>Urdu</option><option title="Spanish narration with a generated Gemini TTS preview." @selected(old('language', $settings['language']) === 'Spanish')>Spanish</option><option title="French narration with a generated Gemini TTS preview." @selected(old('language', $settings['language']) === 'French')>French</option><option title="Arabic narration with a generated Gemini TTS preview." @selected(old('language', $settings['language']) === 'Arabic')>Arabic</option><option title="Hindi narration with a generated Gemini TTS preview." @selected(old('language', $settings['language']) === 'Hindi')>Hindi</option></select></div>
                <div class="field-group"><div class="field-label-row"><label class="field-label" for="narrator_voice">Voice</label><button class="field-info" type="button" data-tooltip="Feminine or masculine guides the final prompt and selects the closest matching Gemini TTS preview voice." aria-label="Help: voice">i</button></div><select class="text-input select-input" id="narrator_voice" name="narrator_voice"><option value="feminine" title="Prompt for a feminine voice; Gemini TTS uses its selected feminine-presenting preview voice." @selected(old('narrator_voice', $settings['narrator_voice']) === 'feminine')>Feminine</option><option value="masculine" title="Prompt for a masculine voice; Gemini TTS uses its selected masculine-presenting preview voice." @selected(old('narrator_voice', $settings['narrator_voice']) === 'masculine')>Masculine</option></select></div></div>
            <div class="setting-pair"><div class="field-group"><div class="field-label-row"><label class="field-label" for="narrator_accent">Accent</label><button class="field-info" type="button" data-tooltip="The accent guides prompt pronunciation and the generated sample; non-English previews keep their language’s natural pronunciation." aria-label="Help: accent">i</button></div><select class="text-input select-input" id="narrator_accent" name="narrator_accent"><option title="Prefer a General American English accent for the prompt and generated sample." @selected(old('narrator_accent', $settings['narrator_accent']) === 'American English')>American English</option><option title="Prefer a contemporary British English accent for the prompt and generated sample." @selected(old('narrator_accent', $settings['narrator_accent']) === 'British English')>British English</option><option title="Prefer Pakistani English pronunciation for the prompt and generated sample." @selected(old('narrator_accent', $settings['narrator_accent']) === 'Pakistani English')>Pakistani English</option><option title="Prefer Australian English pronunciation for the prompt and generated sample." @selected(old('narrator_accent', $settings['narrator_accent']) === 'Australian English')>Australian English</option><option title="Use a broadly neutral pronunciation in the generated sample." @selected(old('narrator_accent', $settings['narrator_accent']) === 'Neutral')>Neutral</option></select></div>
                <div class="field-group"><div class="field-label-row"><label class="field-label" for="narrator_tone">Tone</label><button class="field-info" type="button" data-tooltip="Tone guides the narrator’s delivery in the prompt and the generated Gemini TTS sample." aria-label="Help: tone">i</button></div><select class="text-input select-input" id="narrator_tone" name="narrator_tone"><option title="A gentle, welcoming delivery in the generated sample." @selected(old('narrator_tone', $settings['narrator_tone']) === 'warm and friendly')>warm and friendly</option><option title="An energetic delivery with lively emphasis in the generated sample." @selected(old('narrator_tone', $settings['narrator_tone']) === 'curious and lively')>curious and lively</option><option title="A slower, reflective delivery for careful explanations." @selected(old('narrator_tone', $settings['narrator_tone']) === 'calm and thoughtful')>calm and thoughtful</option><option title="A steady, assured delivery with clear phrasing." @selected(old('narrator_tone', $settings['narrator_tone']) === 'confident and clear')>confident and clear</option></select></div></div>
        </div>

        <div class="voice-preview-card">
            <div><strong>Hear a sample</strong><p>Generated by Gemini TTS from your selected language, voice, accent, and tone. First preview may take a few seconds.</p></div>
            <button class="button-secondary voice-preview-button" type="button" data-voice-preview-button><span aria-hidden="true">▶</span> Preview voice</button>
            <span class="voice-preview-status" data-voice-preview-status role="status" aria-live="polite"></span>
        </div>

        <div class="sound-toggles">
            <div class="field-label-row"><span class="field-label">Sound direction</span><button class="field-info" type="button" data-tooltip="Music and sound-effects choices are included as instructions in the prompt. No sound is created or attached by this studio." aria-label="Help: sound direction">i</button></div>
            <label class="switch-row"><span><strong>Background music</strong><small>Soft, never competing with narration</small></span><input type="hidden" name="music" value="0"><input type="checkbox" name="music" value="1" @checked((bool) old('music', $settings['music']))><i class="switch-track"></i></label>
            <label class="switch-row"><span><strong>Sound effects</strong><small>Only when they match the action</small></span><input type="hidden" name="sound_effects" value="0"><input type="checkbox" name="sound_effects" value="1" @checked((bool) old('sound_effects', $settings['sound_effects']))><i class="switch-track"></i></label>
        </div>
    </section>
</div>
