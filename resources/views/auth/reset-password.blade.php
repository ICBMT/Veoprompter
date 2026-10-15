@extends('layouts.app')

@section('title', 'Choose a new password — VeoPrompter')
@section('eyebrow', 'ACCOUNT RECOVERY')

@section('content')
<div class="auth-page auth-page--narrow">
    <div class="auth-layout auth-layout--single">
        <section class="auth-form-side">
            <span class="eyebrow-label"><span class="eyebrow-spark">✳</span> SECURE PASSWORD RESET</span>
            <h2>Choose a new password</h2><p>Use at least 12 characters, then sign in with your updated password.</p>
            <form action="{{ route('password.update', [], false) }}" method="post">
                @csrf
                <input type="hidden" name="token" value="{{ $token }}">
                <label class="field-label" for="email">Email address</label>
                <input class="text-input" id="email" name="email" type="email" value="{{ old('email', $email) }}" autocomplete="email" required autofocus>
                @error('email')<p class="auth-error">{{ $message }}</p>@enderror
                <label class="field-label" for="password">New password</label>
                <input class="text-input" id="password" name="password" type="password" autocomplete="new-password" minlength="12" required placeholder="At least 12 characters">
                @error('password')<p class="auth-error">{{ $message }}</p>@enderror
                <label class="field-label" for="password_confirmation">Confirm new password</label>
                <input class="text-input" id="password_confirmation" name="password_confirmation" type="password" autocomplete="new-password" minlength="12" required>
                <button class="button-primary" type="submit"><span>Update password</span><span class="button-icon" aria-hidden="true">↗</span></button>
            </form>
        </section>
    </div>
</div>
@endsection
