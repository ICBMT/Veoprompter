@extends('layouts.app')

@section('title', 'Control room — VeoPrompter')
@section('eyebrow', 'CONTROL ROOM')

@section('content')
@php($selectedProvider = old('provider', $settings->provider))
<div class="admin-page">
    <section class="admin-hero">
        <div><span class="section-index">ADMINISTRATOR <i>/</i> CONNECTION SETTINGS</span><h1>Control room</h1><p>Choose the AI that powers prompt generation. Load models for your key, test a model with a tiny text request, then save when it works.</p></div>
        <div class="admin-hero-actions"><div class="admin-ready {{ $aiReady ? '' : 'is-offline' }}"><i></i>{{ $aiReady ? 'GENERATION ENABLED' : 'CONNECTION NOT CONFIGURED' }}</div><a class="button-secondary" href="{{ route('admin.users.index', [], false) }}">Manage users <span aria-hidden="true">↗</span></a></div>
    </section>

    <section class="admin-card" aria-labelledby="connection-heading">
        <header class="admin-card-header"><span class="admin-card-icon"><svg viewBox="0 0 20 20" aria-hidden="true"><path d="M10 2.8 3.5 5.5v4.3c0 3.8 2.8 6.2 6.5 7.4 3.7-1.2 6.5-3.6 6.5-7.4V5.5L10 2.8Z"/><path d="m7.4 10 1.8 1.8 3.6-3.7"/></svg></span><div><h2 id="connection-heading">AI provider connection</h2><p>Keys stay server-side. The prompt studio never receives your credential.</p></div></header>

        <form class="admin-form" action="{{ route('admin.settings.update', [], false) }}" method="post" data-provider-settings-form data-models-url="{{ route('admin.models', [], false) }}" data-test-url="{{ route('admin.connection.test', [], false) }}">
            @csrf
            @method('PUT')
            <div class="admin-form-grid">
                <div class="admin-field">
                    <label class="field-label" for="provider">Provider</label>
                    <select class="text-input select-input" id="provider" name="provider" data-provider-select required>
                        <option value="gemini" @selected(old('provider', $settings->provider) === 'gemini')>Google Gemini</option>
                        <option value="openai" @selected(old('provider', $settings->provider) === 'openai')>OpenAI-compatible API</option>
                    </select>
                    @error('provider')<p class="admin-error">{{ $message }}</p>@enderror
                </div>
                <div class="admin-field admin-model-field">
                    @php($currentModel = old('model', $settings->model ?: ($selectedProvider === 'openai' ? 'gpt-4.1-mini' : 'gemini-2.5-flash')))
                    <label class="field-label" for="model">Available model</label>
                    <select class="text-input select-input" id="model" name="model" required data-model-input data-gemini-default="gemini-2.5-flash" data-openai-default="gpt-4.1-mini">
                        <option value="{{ $currentModel }}" selected>{{ $currentModel }} — current selection</option>
                    </select>
                    <div class="model-picker-actions">
                        <button class="button-secondary" type="button" data-load-models>Load available models</button>
                        <button class="button-secondary" type="button" data-toggle-manual-model>Enter model ID manually</button>
                        <button class="button-secondary button-model-test" type="button" data-test-connection>Test selected model</button>
                    </div>
                    <div class="manual-model-wrap" data-manual-model-wrap hidden>
                        <label class="field-label" for="manual_model">Model ID</label>
                        <input class="text-input" id="manual_model" type="text" maxlength="150" value="{{ $currentModel }}" autocomplete="off" disabled>
                    </div>
                    <p class="model-feedback" data-model-feedback role="status" aria-live="polite">Enter a key to load its models automatically, or click “Load available models” to use a saved key.</p>
                    <span class="field-hint" data-model-hint>Model catalogues vary by provider. Test a selection to confirm it can generate text; the test does not save settings or create a prompt.</span>
                    @error('model')<p class="admin-error">{{ $message }}</p>@enderror
                </div>
                <div class="admin-field admin-field--wide" data-base-url-field @if($selectedProvider !== 'openai') hidden @endif>
                    <label class="field-label" for="base_url">API base URL <span class="optional">HTTPS REQUIRED</span></label>
                    <input class="text-input" id="base_url" name="base_url" type="url" maxlength="255" value="{{ old('base_url', $settings->base_url ?? 'https://api.openai.com/v1') }}" placeholder="https://api.openai.com/v1" @if($selectedProvider !== 'openai') disabled @endif>
                    <span class="field-hint">Enter the base URL only, such as <code>https://api.openai.com/v1</code>. VeoPrompter appends <code>/chat/completions</code>. Use a trusted public HTTPS endpoint.</span>
                    @error('base_url')<p class="admin-error">{{ $message }}</p>@enderror
                </div>
                <div class="admin-field admin-field--wide">
                    <label class="field-label" for="api_key">API key <span class="optional">NEVER DISPLAYED AFTER SAVING</span></label>
                    <input class="text-input" id="api_key" name="api_key" type="password" maxlength="4096" autocomplete="new-password" placeholder="{{ $settings->apiKeyConfigured() ? 'Saved key is hidden — paste a new key to rotate it' : 'Paste your provider API key' }}">
                    <span class="field-hint">{{ $settings->apiKeyConfigured() ? 'A saved key is hidden. Leave this blank to reuse it with the same provider and endpoint.' : 'Add a key to load available models and enable prompt generation.' }} The key is sent only to this server, encrypted at rest, and never sent from your browser directly to the provider.</span>
                    @error('api_key')<p class="admin-error">{{ $message }}</p>@enderror
                </div>
                @if($settings->apiKeyConfigured())
                    <div class="admin-field admin-field--wide">
                        <label class="switch-row"><span><strong>Remove the saved API key</strong><small>Generation will stop until a key is added again.</small></span><input type="checkbox" name="clear_key" value="1"><i class="switch-track"></i></label>
                    </div>
                @endif
            </div>

            <div class="admin-help"><span aria-hidden="true">ⓘ</span><div><strong>Keep this key private.</strong> It is sent only to this server; provider requests run server-side. The saved key is never re-rendered, prompt responses do not include it, and logs do not record its value. Use HTTPS in production and keep <code>APP_KEY</code> backed up; changing it makes existing encrypted credentials unreadable.</div></div>
            <div class="admin-form-footer"><p>Only administrators can view or update provider configuration.</p><button class="button-primary" type="submit"><span>Save connection</span><span class="button-icon" aria-hidden="true">↗</span></button></div>
        </form>
    </section>

    <section class="admin-card duration-options-card" aria-labelledby="duration-options-heading">
        <header class="admin-card-header"><span class="admin-card-icon" aria-hidden="true">◷</span><div><h2 id="duration-options-heading">Project duration choices</h2><p>Control whether users see the clip-length questions in project setup.</p></div></header>
        <form class="admin-form" action="{{ route('admin.duration-options.update', [], false) }}" method="post">
            @csrf
            @method('PUT')
            <div class="admin-form-grid">
                <div class="admin-field admin-field--wide">
                    <input type="hidden" name="allow_clip_duration_choice" value="0">
                    <label class="switch-row"><span><strong>Show the video clip-duration question</strong><small>When off, AI chooses a whole-second duration from 4 to 10 seconds for each clip.</small></span><input type="checkbox" name="allow_clip_duration_choice" value="1" @checked($settings->allow_clip_duration_choice)><i class="switch-track"></i></label>
                </div>
                <div class="admin-field admin-field--wide">
                    <input type="hidden" name="allow_extension_duration_choice" value="0">
                    <label class="switch-row"><span><strong>Show the video-extension duration question</strong><small>When off, AI chooses a whole-second duration from 4 to 10 seconds for the continuation.</small></span><input type="checkbox" name="allow_extension_duration_choice" value="1" @checked($settings->allow_extension_duration_choice)><i class="switch-track"></i></label>
                </div>
            </div>
            <div class="admin-help"><span aria-hidden="true">ⓘ</span><div><strong>These are global controls, off by default.</strong> Turning a choice off hides that setup question and lets AI choose a suitable duration between 4 and 10 seconds. Turn it on to let eligible accounts choose an AI-selected or fixed duration. Account-level switches in User management can further restrict access; they cannot bypass a global off switch.</div></div>
            <div class="admin-form-footer"><p>Both controls start off. Enable them here and for an account to show that duration question.</p><button class="button-primary" type="submit"><span>Save duration options</span><span class="button-icon" aria-hidden="true">↗</span></button></div>
        </form>
    </section>

    <section class="admin-card voice-admin-card" aria-labelledby="voice-settings-heading">
        <header class="admin-card-header"><span class="admin-card-icon" aria-hidden="true">♫</span><div><h2 id="voice-settings-heading">Voice sample generation</h2><p>Gemini TTS creates consistent preview audio for the selected language, voice, accent, and tone.</p></div><div class="admin-ready {{ $ttsReady ? '' : 'is-offline' }}"><i></i>{{ $ttsReady ? 'VOICE PREVIEWS ENABLED' : 'TTS NOT CONFIGURED' }}</div></header>

        <form class="admin-form" action="{{ route('admin.voice-settings.update', [], false) }}" method="post">
            @csrf
            @method('PUT')
            <div class="admin-form-grid">
                <div class="admin-field admin-field--wide">
                    <label class="field-label" for="tts_model">Gemini speech model</label>
                    <input class="text-input" id="tts_model" name="tts_model" type="text" maxlength="150" required value="{{ old('tts_model', $settings->tts_model ?: 'gemini-2.5-flash-preview-tts') }}" autocomplete="off" placeholder="gemini-2.5-flash-preview-tts">
                    <span class="field-hint">Use a Gemini model enabled for text-to-speech. The model can be changed without changing the separate prompt-generation model.</span>
                    @error('tts_model')<p class="admin-error">{{ $message }}</p>@enderror
                </div>
                <div class="admin-field admin-field--wide">
                    <label class="field-label" for="tts_api_key">Google TTS API key <span class="optional">NEVER DISPLAYED AFTER SAVING</span></label>
                    <input class="text-input" id="tts_api_key" name="tts_api_key" type="password" maxlength="4096" autocomplete="new-password" placeholder="{{ $settings->ttsApiKeyConfigured() ? 'Saved TTS key is hidden — paste a new key to rotate it' : 'Paste a Google API key with Gemini TTS access' }}">
                    <span class="field-hint">{{ $settings->ttsApiKeyConfigured() ? 'A saved speech key is hidden. Leave blank to keep it.' : 'A separate speech key is required to generate reliable previews.' }} The key is encrypted at rest and is sent only from this server to Google; it never reaches browser code.</span>
                    @error('tts_api_key')<p class="admin-error">{{ $message }}</p>@enderror
                </div>
                @if($settings->ttsApiKeyConfigured())
                    <div class="admin-field admin-field--wide">
                        <label class="switch-row"><span><strong>Remove the saved TTS API key</strong><small>Cached samples remain available; new combinations will require a key.</small></span><input type="checkbox" name="clear_tts_key" value="1"><i class="switch-track"></i></label>
                    </div>
                @endif
            </div>

            <div class="admin-help"><span aria-hidden="true">ⓘ</span><div><strong>Samples are generated once, then cached privately.</strong> The first preview of a unique language × voice × accent × tone combination may take several seconds and uses one Gemini TTS request. Later previews reuse the saved audio. Up to 240 preference combinations are supported; no samples are generated in bulk without someone previewing them.</div></div>
            <div class="admin-form-footer"><p>Only administrators can change the Gemini TTS model or speech key.</p><button class="button-primary" type="submit"><span>Save voice settings</span><span class="button-icon" aria-hidden="true">↗</span></button></div>
        </form>
    </section>

    <div class="admin-divider-label">YOUR PROMPTING STANDARD</div>
    <section class="admin-method-card">
        <span class="method-icon" aria-hidden="true">✳</span><div><strong>Master specification is loaded on every request</strong><p>The complete continuity method from <code>prompt.txt</code> is sent as server-side system instructions: stable subject and world details, exact clip-to-clip handoffs, a verbatim continuation rule, mode-appropriate factual checks, and copy-ready Flow prompts. It is not editable by ordinary users.</p></div>
    </section>
</div>
@endsection
