@extends('layouts.app')

@section('title', 'Control room — VeoPrompter')
@section('eyebrow', 'CONTROL ROOM')

@section('content')
@php($selectedProvider = old('provider', $settings->provider))
<div class="admin-page">
    <section class="admin-hero">
        <div><span class="section-index">ADMINISTRATOR <i>/</i> CONNECTION SETTINGS</span><h1>Control room</h1><p>Choose the AI that powers prompt generation. Provider credentials are encrypted in the database and only used by the server.</p></div>
        <div class="admin-ready {{ $aiReady ? '' : 'is-offline' }}"><i></i>{{ $aiReady ? 'GENERATION ENABLED' : 'CONNECTION NOT CONFIGURED' }}</div>
    </section>

    <section class="admin-card" aria-labelledby="connection-heading">
        <header class="admin-card-header"><span class="admin-card-icon"><svg viewBox="0 0 20 20" aria-hidden="true"><path d="M10 2.8 3.5 5.5v4.3c0 3.8 2.8 6.2 6.5 7.4 3.7-1.2 6.5-3.6 6.5-7.4V5.5L10 2.8Z"/><path d="m7.4 10 1.8 1.8 3.6-3.7"/></svg></span><div><h2 id="connection-heading">AI provider connection</h2><p>Keys stay server-side. The prompt studio never receives your credential.</p></div></header>

        <form class="admin-form" action="{{ route('admin.settings.update', [], false) }}" method="post">
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
                <div class="admin-field">
                    <label class="field-label" for="model">Model ID</label>
                    <input class="text-input" id="model" name="model" type="text" maxlength="150" required value="{{ old('model', $settings->model) }}" data-model-input data-gemini-default="gemini-2.5-flash" data-openai-default="gpt-4.1-mini" placeholder="gemini-2.5-flash">
                    <span class="field-hint" data-model-hint>Use a model available to this API key. Example: <code>gemini-2.5-flash</code>.</span>
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
                    <span class="field-hint">{{ $settings->apiKeyConfigured() ? 'A key is already saved. Leave this blank to keep it unchanged.' : 'No key is saved yet. Add one to enable prompt generation.' }} It is encrypted at rest using your Laravel APP_KEY.</span>
                    @error('api_key')<p class="admin-error">{{ $message }}</p>@enderror
                </div>
                @if($settings->apiKeyConfigured())
                    <div class="admin-field admin-field--wide">
                        <label class="switch-row"><span><strong>Remove the saved API key</strong><small>Generation will stop until a key is added again.</small></span><input type="checkbox" name="clear_key" value="1"><i class="switch-track"></i></label>
                    </div>
                @endif
            </div>

            <div class="admin-help"><span aria-hidden="true">ⓘ</span><div><strong>Keep this key private.</strong> The browser never sees it, prompt responses do not include it, and logs do not record its value. Use HTTPS in production and keep <code>APP_KEY</code> backed up; changing it makes existing encrypted credentials unreadable.</div></div>
            <div class="admin-form-footer"><p>Only administrators can view or update provider configuration.</p><button class="button-primary" type="submit"><span>Save connection</span><span class="button-icon" aria-hidden="true">↗</span></button></div>
        </form>
    </section>

    <div class="admin-divider-label">YOUR PROMPTING STANDARD</div>
    <section class="admin-method-card">
        <span class="method-icon" aria-hidden="true">✳</span><div><strong>Master specification is loaded on every request</strong><p>The complete continuity method from <code>prompt.txt</code> is sent as server-side system instructions: fixed character and world bibles, exact clip-to-clip handoffs, a verbatim continuation rule, educational accuracy checks, and copy-ready Flow prompts. It is not editable by ordinary users.</p></div>
    </section>
</div>
@endsection
