<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <meta name="theme-color" content="#0b0d10">
    <title>@yield('title', 'VeoPrompter — Prompt studio')</title>
    <link rel="stylesheet" href="/css/veoprompter.css">
    <script src="/js/veoprompter.js" defer></script>
</head>
<body>
@php
    $currentUser = auth()->user();
    $isAdmin = (bool) ($currentUser?->is_admin);
    $aiReady = $aiReady ?? (\App\Models\AiSetting::query()->find(1)?->isReady() ?? false);
@endphp
<div class="app-shell">
    <aside class="sidebar" aria-label="Main navigation">
        <a class="brand" href="{{ route('studio', [], false) }}" aria-label="VeoPrompter home">
            <span class="brand-mark" aria-hidden="true">
                <svg viewBox="0 0 40 40" fill="none"><path d="M7 11.5 19 33l3.9-6.9L11.4 7.5A4.1 4.1 0 0 0 7 11.5Z" fill="currentColor"/><path d="m18.7 7.4 12.9.1a4.2 4.2 0 0 1 3.6 6.3L22.9 34l-3.8-7 8.5-13.9H18l-3.8-6.3a4.2 4.2 0 0 1 4.5.6Z" fill="currentColor" opacity=".68"/><circle cx="30.8" cy="29" r="4.2" fill="#0b0d10" stroke="currentColor" stroke-width="2"/></svg>
            </span>
            <span class="brand-name">veo<span>prompter</span></span>
        </a>

        <div class="sidebar-caption">YOUR CREATIVE SPACE</div>
        <nav class="sidebar-nav">
            <a href="{{ route('studio', [], false) }}" class="nav-link {{ request()->routeIs('studio') ? 'is-active' : '' }}">
                <svg viewBox="0 0 20 20" aria-hidden="true"><path d="M3 5.4A2.4 2.4 0 0 1 5.4 3h9.2A2.4 2.4 0 0 1 17 5.4v9.2a2.4 2.4 0 0 1-2.4 2.4H5.4A2.4 2.4 0 0 1 3 14.6V5.4Z"/><path d="m8 6.6 5.1 3.4L8 13.4V6.6Z"/></svg>
                <span>Prompt studio</span><span class="nav-pulse" aria-hidden="true"></span>
            </a>
            <a href="{{ route('guide', [], false) }}" class="nav-link {{ request()->routeIs('guide') ? 'is-active' : '' }}">
                <svg viewBox="0 0 20 20" aria-hidden="true"><path d="M4 3.5h12v13H4z"/><path d="M7 7h6M7 10h6M7 13h3"/></svg>
                <span>Continuity guide</span>
            </a>
        </nav>

        <div class="sidebar-divider"></div>
        <div class="sidebar-caption">THE PROMPT METHOD</div>
        <div class="sidebar-note">
            <span class="sidebar-note-icon" aria-hidden="true">✳</span>
            <p>Built around <strong>character lock</strong>, scene handoffs, and one clear action per clip.</p>
            <a href="{{ route('guide', [], false) }}">See how it works <span aria-hidden="true">↗</span></a>
        </div>

        <div class="sidebar-spacer"></div>
        <div class="sidebar-status {{ $aiReady ? 'is-ready' : 'is-waiting' }}">
            <span class="status-orb" aria-hidden="true"></span>
            <div><strong>{{ $aiReady ? 'AI connection ready' : 'AI connection needed' }}</strong><small>{{ $aiReady ? 'Prompts can be generated' : 'Setup is admin-only' }}</small></div>
            <span class="status-chevron" aria-hidden="true">{{ $aiReady ? '↗' : '· · ·' }}</span>
        </div>
        <div class="sidebar-account">
            @if($isAdmin)
                <span class="account-avatar">{{ mb_substr($currentUser->name, 0, 1) }}</span>
                <div class="account-copy"><strong>{{ $currentUser->name }}</strong><small>Administrator</small></div>
                <a class="account-action" href="{{ route('admin.settings', [], false) }}" aria-label="Open Control room" title="Control room">⚙</a>
            @else
                <span class="account-avatar account-avatar--guest">V</span>
                <div class="account-copy"><strong>VeoPrompter</strong><small>Consistency, by design</small></div>
                <a class="account-action" href="{{ route('login', [], false) }}" aria-label="Administrator sign in" title="Administrator sign in">↗</a>
            @endif
        </div>
    </aside>

    <div class="app-main">
        <header class="topbar">
            <div class="breadcrumbs"><span>WORKSPACE</span><i>/</i><strong>@yield('eyebrow', 'PROMPT STUDIO')</strong></div>
            <div class="topbar-actions">
                <span class="topbar-status"><span class="status-dot {{ $aiReady ? 'dot-ready' : '' }}"></span>{{ $aiReady ? 'SYSTEM READY' : 'SETUP REQUIRED' }}</span>
                @if($isAdmin)
                    <a class="topbar-control" href="{{ route('admin.settings', [], false) }}"><span aria-hidden="true">⚙</span> Control room</a>
                    <form action="{{ route('logout', [], false) }}" method="post" class="logout-form">@csrf<button type="submit" class="topbar-logout">Sign out</button></form>
                @elseif(!auth()->check())
                    <a class="topbar-control" href="{{ route('login', [], false) }}">Admin sign in <span aria-hidden="true">↗</span></a>
                @endif
            </div>
        </header>

        <main class="page-shell">
            @if(session('success'))
                <div class="flash-message" role="status"><span aria-hidden="true">✓</span>{{ session('success') }}</div>
            @endif
            @yield('content')
        </main>
        <footer class="app-footer"><span>VEO PROMPTER <i>·</i> BUILT FOR BETTER CONTINUITY</span><a href="{{ route('guide', [], false) }}">How prompting works <span aria-hidden="true">↗</span></a></footer>
    </div>
</div>
</body>
</html>
