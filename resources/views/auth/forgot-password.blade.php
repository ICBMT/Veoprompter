@extends('layouts.app')

@section('title', 'Reset your password — VeoPrompter')
@section('eyebrow', 'ACCOUNT RECOVERY')

@section('content')
<div class="auth-page auth-page--narrow">
    <div class="auth-layout auth-layout--single">
        <section class="auth-form-side">
            <span class="eyebrow-label"><span class="eyebrow-spark">✳</span> ACCOUNT RECOVERY</span>
            <h2>Forgot your password?</h2><p>Enter the email address for your account. If it matches, we’ll send you a secure reset link.</p>
            @if(session('status'))<div class="flash-message auth-flash" role="status">{{ session('status') }}</div>@endif
            <form action="{{ route('password.email', [], false) }}" method="post">
                @csrf
                <label class="field-label" for="email">Email address</label>
                <input class="text-input" id="email" name="email" type="email" value="{{ old('email') }}" autocomplete="email" required autofocus placeholder="you@example.com">
                @error('email')<p class="auth-error">{{ $message }}</p>@enderror
                <button class="button-primary" type="submit"><span>Send reset link</span><span class="button-icon" aria-hidden="true">↗</span></button>
            </form>
            <a class="auth-back" href="{{ route('login', [], false) }}"><span aria-hidden="true">←</span> Back to sign in</a>
        </section>
    </div>
</div>
@endsection
