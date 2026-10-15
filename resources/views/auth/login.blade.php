@extends('layouts.app')

@section('title', 'Sign in — VeoPrompter')
@section('eyebrow', 'WELCOME BACK')

@section('content')
<div class="auth-page">
    <div class="auth-layout">
        <section class="auth-copy">
            <div><span class="eyebrow-label"><span class="eyebrow-spark">✳</span> YOUR IDEAS, IN SEQUENCE</span><h1>Pick up where your next story <em>begins.</em></h1><p>Sign in to keep every prompt kit in one place. Your library stays attached to your account, ready whenever inspiration returns.</p></div>
            <div class="auth-feature"><span>✓</span> Every prompt kit saved to your private library</div>
        </section>
        <section class="auth-form-side">
            <h2>Welcome back</h2><p>Sign in to continue to your prompt studio.</p>
            @if(session('status'))<div class="flash-message auth-flash" role="status">{{ session('status') }}</div>@endif
            <form action="{{ route('login.store', [], false) }}" method="post">
                @csrf
                <label class="field-label" for="email">Email address</label>
                <input class="text-input" id="email" name="email" type="email" value="{{ old('email') }}" autocomplete="username" required autofocus placeholder="you@example.com">
                @error('email')<p class="auth-error">{{ $message }}</p>@enderror
                <div class="auth-password-label"><label class="field-label" for="password">Password</label><a href="{{ route('password.request', [], false) }}">Forgot password?</a></div>
                <input class="text-input" id="password" name="password" type="password" autocomplete="current-password" required placeholder="Your password">
                @error('password')<p class="auth-error">{{ $message }}</p>@enderror
                <label class="switch-row auth-remember"><span><strong>Remember this device</strong><small>Stay signed in on this browser.</small></span><input type="checkbox" name="remember" value="1"><i class="switch-track"></i></label>
                <button class="button-primary" type="submit"><span>Sign in</span><span class="button-icon" aria-hidden="true">↗</span></button>
            </form>
            <div class="auth-signup-prompt">New to VeoPrompter? <a href="{{ route('register', [], false) }}">Create your free account <span aria-hidden="true">↗</span></a></div>
            <a class="auth-back" href="{{ route('studio', [], false) }}"><span aria-hidden="true">←</span> Back to the prompt studio</a>
        </section>
    </div>
</div>
@endsection
