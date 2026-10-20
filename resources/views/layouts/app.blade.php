<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <meta name="voice-sample-url" content="{{ route('voice-samples.store', [], false) }}">
    @auth
        <meta name="presence-url" content="{{ route('presence.ping', [], false) }}">
        @if(auth()->user()->expires_at)<meta name="account-expires-at" content="{{ auth()->user()->expires_at->toIso8601String() }}">@endif
    @endauth
    <meta name="theme-color" content="#0a1118">
    <title>@yield('title', 'VeoPrompter — Prompt studio')</title>
    <link rel="preload" href="/fonts/inter-latin-wght-normal.woff2" as="font" type="font/woff2" crossorigin>
    <link rel="stylesheet" href="/css/veoprompter.css">
    <script src="/js/vendor/gsap.min.js" defer></script>
    <script src="/js/vendor/ScrollTrigger.min.js" defer></script>
    <script src="/js/extension-handoff.js" defer></script>
    <script src="/js/veoprompter.js" defer></script>
    <script src="/js/site-motion.js" defer></script>
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
            <a href="{{ $currentUser ? route('projects.index', [], false) : route('login', [], false) }}" class="nav-link {{ request()->routeIs('studio') || request()->routeIs('projects.*') ? 'is-active' : '' }}">
                <svg viewBox="0 0 20 20" aria-hidden="true"><path d="M3 5.4A2.4 2.4 0 0 1 5.4 3h9.2A2.4 2.4 0 0 1 17 5.4v9.2a2.4 2.4 0 0 1-2.4 2.4H5.4A2.4 2.4 0 0 1 3 14.6V5.4Z"/><path d="m8 6.6 5.1 3.4L8 13.4V6.6Z"/></svg>
                <span>{{ $currentUser ? 'My projects' : 'Sign in to start' }}</span><span class="nav-pulse" aria-hidden="true"></span>
            </a>
            @if($currentUser)
                <a href="{{ route('history.index', [], false) }}" class="nav-link {{ request()->routeIs('history.*') ? 'is-active' : '' }}">
                    <svg viewBox="0 0 20 20" aria-hidden="true"><path d="M4 5.2A2.2 2.2 0 0 1 6.2 3h8.6A2.2 2.2 0 0 1 17 5.2v9.6a2.2 2.2 0 0 1-2.2 2.2H6.2A2.2 2.2 0 0 1 4 14.8V5.2Z"/><path d="M7 6.5h7M7 9.5h7M7 12.5h4M2.5 6v9"/></svg>
                    <span>Prompt library</span>
                </a>
                <a href="{{ route('account.edit', [], false) }}" class="nav-link {{ request()->routeIs('account.*') ? 'is-active' : '' }}">
                    <svg viewBox="0 0 20 20" aria-hidden="true"><circle cx="10" cy="6.2" r="3.2"/><path d="M3.5 17c.7-3.5 2.8-5.2 6.5-5.2s5.8 1.7 6.5 5.2"/></svg>
                    <span>Account</span>
                </a>
                @if($isAdmin)
                    <a href="{{ route('admin.users.index', [], false) }}" class="nav-link admin-users-nav {{ request()->routeIs('admin.users.*') ? 'is-active' : '' }}"><svg viewBox="0 0 20 20" aria-hidden="true"><circle cx="7" cy="7" r="3"/><circle cx="14.5" cy="8" r="2.3"/><path d="M1.7 16.7c.5-3 2.2-4.5 5.3-4.5s4.8 1.5 5.3 4.5m.2-4c2.8-.1 4.3 1.2 4.8 3.5"/></svg><span>User management</span></a>
                @endif
            @else
                <a href="{{ route('login', [], false) }}" class="nav-link {{ request()->routeIs('login') || request()->routeIs('register') ? 'is-active' : '' }}">
                    <svg viewBox="0 0 20 20" aria-hidden="true"><path d="M10 2.7 3.5 5.5v4.2c0 3.7 2.8 6.1 6.5 7.5 3.7-1.4 6.5-3.8 6.5-7.5V5.5L10 2.7Z"/><path d="M10 7v5m-2.5-2.5h5"/></svg>
                    <span>Sign in / Create account</span>
                </a>
            @endif
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
            @if($currentUser)
                <span class="account-avatar {{ $isAdmin ? '' : 'account-avatar--guest' }}">{{ mb_substr($currentUser->name, 0, 1) }}</span>
                <div class="account-copy"><strong>{{ $currentUser->name }}</strong><small>{{ $isAdmin ? 'Administrator' : 'Prompt maker' }}</small></div>
                <a class="account-action" href="{{ $isAdmin ? route('admin.settings', [], false) : route('account.edit', [], false) }}" aria-label="{{ $isAdmin ? 'Open Control room' : 'Open account settings' }}" title="{{ $isAdmin ? 'Control room' : 'Account settings' }}">{{ $isAdmin ? '⚙' : '↗' }}</a>
            @else
                <span class="account-avatar account-avatar--guest">V</span>
                <div class="account-copy"><strong>Your creative space</strong><small>Sign in to start a project</small></div>
                <a class="account-action" href="{{ route('register', [], false) }}" aria-label="Create an account" title="Create an account">↗</a>
            @endif
        </div>
    </aside>

    <div class="app-main">
        <header class="topbar">
            <div class="breadcrumbs"><span>WORKSPACE</span><i>/</i><strong>@yield('eyebrow', 'PROMPT STUDIO')</strong></div>
            <div class="topbar-actions">
                <span class="topbar-status"><span class="status-dot {{ $aiReady ? 'dot-ready' : '' }}"></span>{{ $aiReady ? 'SYSTEM READY' : 'SETUP REQUIRED' }}</span>
                @if($isAdmin)
                    <a class="topbar-control" href="{{ route('admin.users.index', [], false) }}"><span aria-hidden="true">◎</span> Users</a>
                    <a class="topbar-control" href="{{ route('admin.settings', [], false) }}"><span aria-hidden="true">⚙</span> Control room</a>
                @endif
                @if($currentUser)
                    <a class="topbar-library" href="{{ route('projects.index', [], false) }}">My projects</a>
                    <a class="topbar-library" href="{{ route('history.index', [], false) }}">Prompt library</a>
                    <form action="{{ route('logout', [], false) }}" method="post" class="logout-form">@csrf<button type="submit" class="topbar-logout">Sign out</button></form>
                @else
                    <a class="topbar-quiet" href="{{ route('login', [], false) }}">Sign in</a>
                    <a class="topbar-control topbar-join" href="{{ route('register', [], false) }}">Create account <span aria-hidden="true">↗</span></a>
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
