@extends('layouts.app')

@section('title', 'Create your account — VeoPrompter')
@section('eyebrow', 'CREATE AN ACCOUNT')

@section('content')
<div class="auth-page">
    <div class="auth-layout">
        <section class="auth-copy">
            <div><span class="eyebrow-label"><span class="eyebrow-spark">✳</span> A HOME FOR YOUR IDEAS</span><h1>Keep your best prompts <em>close at hand.</em></h1><p>Your account gives every generated story a private place in your history. Come back to copy, revisit, or keep building from your previous work.</p></div>
            <div class="auth-feature"><span>✓</span> Private prompt history, saved automatically</div>
        </section>
        <section class="auth-form-side">
            <h2>Create your account</h2><p>It takes a moment. Your prompt studio stays free to use.</p>
            <form action="{{ route('register.store', [], false) }}" method="post">
                @csrf
                <label class="field-label" for="name">Your name</label>
                <input class="text-input" id="name" name="name" type="text" value="{{ old('name') }}" autocomplete="name" minlength="2" maxlength="100" required autofocus placeholder="Alex Morgan">
                @error('name')<p class="auth-error">{{ $message }}</p>@enderror
                <label class="field-label" for="email">Email address</label>
                <input class="text-input" id="email" name="email" type="email" value="{{ old('email') }}" autocomplete="email" required placeholder="you@example.com">
                @error('email')<p class="auth-error">{{ $message }}</p>@enderror
                <label class="field-label" for="password">Password</label>
                <input class="text-input" id="password" name="password" type="password" autocomplete="new-password" minlength="12" required placeholder="At least 12 characters">
                <span class="field-hint">Use at least 12 characters. Your password is never stored as plain text.</span>
                @error('password')<p class="auth-error">{{ $message }}</p>@enderror
                <label class="field-label" for="password_confirmation">Confirm password</label>
                <input class="text-input" id="password_confirmation" name="password_confirmation" type="password" autocomplete="new-password" minlength="12" required placeholder="Type it once more">
                <button class="button-primary" type="submit"><span>Create my account</span><span class="button-icon" aria-hidden="true">↗</span></button>
            </form>
            <div class="auth-signup-prompt">Already have an account? <a href="{{ route('login', [], false) }}">Sign in <span aria-hidden="true">↗</span></a></div>
            <a class="auth-back" href="{{ route('guide', [], false) }}"><span aria-hidden="true">←</span> Read the continuity guide</a>
        </section>
    </div>
</div>
@endsection
