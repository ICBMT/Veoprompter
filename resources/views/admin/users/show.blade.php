@extends('layouts.app')

@section('title', 'User activity — '.$user->name.' — VeoPrompter')
@section('eyebrow', 'USER ACTIVITY')

@section('content')
<div class="admin-user-detail-page">
    <a class="history-back" href="{{ route('admin.users.index', [], false) }}"><span aria-hidden="true">←</span> Back to user management</a>
    <section class="admin-user-detail-hero"><div><span class="section-index">ACCOUNT <i>/</i> GENERATED PROMPT HISTORY</span><h1>{{ $user->name }}</h1><p>{{ $user->email }}</p></div><div class="presence-indicator {{ $user->isOnline() ? 'is-online' : 'is-offline' }}"><i></i>{{ $user->isOnline() ? 'Online now' : 'Offline' }}</div></section>

    <div class="admin-user-facts">
        <div><span>Account type</span><strong>{{ $user->is_admin ? 'Administrator' : 'Customer' }}</strong></div>
        <div><span>Joined</span><strong>{{ $user->created_at->format('M j, Y · g:i A') }}</strong></div>
        <div><span>Last activity</span><strong>{{ $user->last_seen_at?->diffForHumans() ?? 'No recorded activity' }}</strong></div>
        <div><span>Account access</span><strong>@if($user->isExpired())Expired @elseif($user->expires_at)Test account · expires {{ $user->expires_at->format('M j, Y · g:i A') }} @else Permanent account @endif</strong></div>
        <div><span>Saved projects</span><strong>{{ number_format($user->studioProjects()->count()) }}</strong></div>
        <div><span>Generated prompt kits</span><strong>{{ number_format($runs->total()) }}</strong></div>
    </div>

    <section class="admin-card user-duration-control-card" aria-labelledby="user-duration-control-heading">
        <header class="admin-card-header"><span class="admin-card-icon" aria-hidden="true">◷</span><div><h2 id="user-duration-control-heading">Duration access for this account</h2><p>These account-specific switches can further restrict the global options in the Control room.</p></div></header>
        <form class="admin-form" action="{{ route('admin.users.duration-options.update', $user, false) }}" method="post">
            @csrf
            @method('PUT')
            <div class="admin-form-grid">
                <div class="admin-field admin-field--wide">
                    <input type="hidden" name="can_choose_clip_duration" value="0">
                    <label class="switch-row"><span><strong>Allow this account to choose video clip length</strong><small>When off, AI selects each clip’s duration from 4 to 10 seconds.</small></span><input type="checkbox" name="can_choose_clip_duration" value="1" @checked($user->can_choose_clip_duration)><i class="switch-track"></i></label>
                    @unless($globalDurationOptions['clip_duration'])<p class="field-hint duration-global-note">The global Control room switch is off, so AI currently chooses this account’s clip lengths.</p>@endunless
                </div>
                <div class="admin-field admin-field--wide">
                    <input type="hidden" name="can_choose_extension_duration" value="0">
                    <label class="switch-row"><span><strong>Allow this account to choose extension length</strong><small>When off, AI selects the continuation duration from 4 to 10 seconds.</small></span><input type="checkbox" name="can_choose_extension_duration" value="1" @checked($user->can_choose_extension_duration)><i class="switch-track"></i></label>
                    @unless($globalDurationOptions['extend_duration'])<p class="field-hint duration-global-note">The global Control room switch is off, so AI currently chooses this account’s extension length.</p>@endunless
                </div>
            </div>
            <div class="admin-help"><span aria-hidden="true">ⓘ</span><div>A duration question is available only when both its global Control room switch and this account’s switch are on. If either is off, the AI selects a whole-second duration between 4 and 10 seconds.</div></div>
            <div class="admin-form-footer"><p>Changes apply to new projects and future prompt generations for this account.</p><button class="button-primary" type="submit"><span>Save account options</span><span class="button-icon" aria-hidden="true">↗</span></button></div>
        </form>
    </section>

    <section class="admin-user-prompts" aria-labelledby="admin-user-prompts-heading">
        <div class="results-header"><div><span class="section-index">CUSTOMER HISTORY</span><h2 id="admin-user-prompts-heading">Generated prompt kits</h2><p>Administrators can review each saved brief and the complete generated output.</p></div></div>
        @if($runs->count())
            <div class="admin-prompt-list">
                @foreach($runs as $run)
                    @php($brief = $run->brief ?? [])
                    <article class="admin-prompt-card">
                        <div class="admin-prompt-card-header"><div><span class="history-mode {{ $run->mode === 'extend' ? 'mode-extend' : '' }}">{{ $run->mode === 'extend' ? '↗ EXTENSION' : '▶ NEW VIDEO' }}</span><span class="history-date">{{ $run->created_at->format('M j, Y · g:i A') }}</span></div><span class="admin-prompt-model">{{ $run->provider === 'gemini' ? 'GEMINI' : 'OPENAI-COMPATIBLE' }} · {{ $run->model }}</span></div>
                        <h3>{{ $run->title }}</h3>
                        <p class="admin-prompt-subject">{{ $run->mode === 'extend' ? ($brief['next_action'] ?? 'Video extension') : ($brief['topic'] ?? 'Video prompt') }}</p>
                        @if($run->studioProject)<span class="admin-prompt-project-link">Project: {{ $run->studioProject->name }}</span>@endif
                        <details class="admin-prompt-output"><summary>View full saved prompt output</summary><pre>{{ $run->content }}</pre></details>
                    </article>
                @endforeach
            </div>
            {{ $runs->links('components.pagination') }}
        @else
            <section class="history-empty"><h3>No prompts have been saved yet.</h3><p>Generated prompt kits will be listed here.</p></section>
        @endif
    </section>
</div>
@endsection
