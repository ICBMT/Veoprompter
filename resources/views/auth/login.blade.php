@extends('layouts.app')

@section('title', 'Administrator sign in — VeoPrompter')
@section('eyebrow', 'ADMINISTRATOR SIGN IN')

@section('content')
<div class="auth-page">
    <div class="auth-layout">
        <section class="auth-copy">
            <div><span class="eyebrow-label"><span class="eyebrow-spark">✳</span> PRIVATE ADMIN AREA</span><h1>Keep the creative engine <em>in good hands.</em></h1><p>Sign in to configure the AI provider behind the prompt studio. Provider credentials are kept out of the browser and encrypted at rest.</p></div>
            <div class="auth-feature"><span>✓</span> Configuration is reserved for administrators</div>
        </section>
        <section class="auth-form-side">
            <h2>Welcome back</h2><p>Use the administrator account provisioned on your server.</p>
            <form action="{{ route('login.store', [], false) }}" method="post">
                @csrf
                <label class="field-label" for="email">Email address</label>
                <input class="text-input" id="email" name="email" type="email" value="{{ old('email') }}" autocomplete="username" required autofocus placeholder="admin@example.com">
                @error('email')<p class="auth-error">{{ $message }}</p>@enderror
                <label class="field-label" for="password">Password</label>
                <input class="text-input" id="password" name="password" type="password" autocomplete="current-password" required placeholder="Your administrator password">
                @error('password')<p class="auth-error">{{ $message }}</p>@enderror
                <label class="switch-row auth-remember"><span><strong>Remember this device</strong><small>Keep this administrator session signed in.</small></span><input type="checkbox" name="remember" value="1"><i class="switch-track"></i></label>
                <button class="button-primary" type="submit"><span>Sign in to Control room</span><span class="button-icon" aria-hidden="true">↗</span></button>
            </form>
            <a class="auth-back" href="{{ route('studio', [], false) }}"><span aria-hidden="true">←</span> Back to the prompt studio</a>
        </section>
    </div>
</div>
@endsection
