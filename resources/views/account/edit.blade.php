@extends('layouts.app')

@section('title', 'Your account — VeoPrompter')
@section('eyebrow', 'YOUR ACCOUNT')

@section('content')
<div class="account-page">
    <section class="admin-hero account-hero">
        <div><span class="section-index">PROFILE <i>/</i> PERSONAL SETTINGS</span><h1>Your account</h1><p>Manage your sign-in details and keep your prompt library in one place.</p></div>
        <div class="account-summary"><span class="account-summary-avatar">{{ mb_substr($user->name, 0, 1) }}</span><span><strong>{{ $user->name }}</strong><small>{{ $projectCount }} saved {{ \Illuminate\Support\Str::plural('prompt kit', $projectCount) }}</small></span></div>
    </section>

    <div class="account-grid">
        <section class="admin-card account-card">
            <header class="admin-card-header"><span class="admin-card-icon"><svg viewBox="0 0 20 20" aria-hidden="true"><circle cx="10" cy="6.2" r="3.2"/><path d="M3.5 17c.7-3.5 2.8-5.2 6.5-5.2s5.8 1.7 6.5 5.2"/></svg></span><div><h2>Profile details</h2><p>Update the name and email attached to your account.</p></div></header>
            <form class="account-form" action="{{ route('account.profile.update', [], false) }}" method="post">
                @csrf @method('PUT')
                <label class="field-label" for="name">Name</label>
                <input class="text-input" id="name" name="name" type="text" value="{{ old('name', $user->name) }}" maxlength="100" required>
                @error('name')<p class="admin-error">{{ $message }}</p>@enderror
                <label class="field-label account-field-spaced" for="email">Email address</label>
                <input class="text-input" id="email" name="email" type="email" value="{{ old('email', $user->email) }}" maxlength="255" autocomplete="email" required>
                @error('email')<p class="admin-error">{{ $message }}</p>@enderror
                <div class="account-form-footer"><p>Joined {{ $user->created_at->format('F Y') }}</p><button class="button-primary" type="submit"><span>Save profile</span><span class="button-icon" aria-hidden="true">↗</span></button></div>
            </form>
        </section>

        <section class="admin-card account-card">
            <header class="admin-card-header"><span class="admin-card-icon account-password-icon"><svg viewBox="0 0 20 20" aria-hidden="true"><path d="M5.5 8V5.7a4.5 4.5 0 0 1 9 0V8M4 8h12v9H4z"/><path d="M10 11v3"/></svg></span><div><h2>Change password</h2><p>Choose a strong password you haven’t used elsewhere.</p></div></header>
            <form class="account-form" action="{{ route('account.password.update', [], false) }}" method="post">
                @csrf @method('PUT')
                <label class="field-label" for="current_password">Current password</label>
                <input class="text-input" id="current_password" name="current_password" type="password" autocomplete="current-password" required>
                @error('current_password')<p class="admin-error">{{ $message }}</p>@enderror
                <label class="field-label account-field-spaced" for="password">New password</label>
                <input class="text-input" id="password" name="password" type="password" autocomplete="new-password" minlength="12" required placeholder="At least 12 characters">
                @error('password')<p class="admin-error">{{ $message }}</p>@enderror
                <label class="field-label account-field-spaced" for="password_confirmation">Confirm new password</label>
                <input class="text-input" id="password_confirmation" name="password_confirmation" type="password" autocomplete="new-password" minlength="12" required>
                <div class="account-form-footer"><p>This invalidates existing remember-me tokens.</p><button class="button-primary" type="submit"><span>Update password</span><span class="button-icon" aria-hidden="true">↗</span></button></div>
            </form>
        </section>
    </div>

    <a class="account-library-link" href="{{ route('history.index', [], false) }}"><span class="library-link-icon">▤</span><span><strong>Open your prompt library</strong><small>Revisit saved stories, continue copying, or clean up old drafts.</small></span><span class="library-link-arrow">↗</span></a>
</div>
@endsection
