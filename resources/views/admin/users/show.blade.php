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
