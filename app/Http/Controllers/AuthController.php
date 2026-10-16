<?php

namespace App\Http\Controllers;

use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rules\Password;
use Illuminate\View\View;

class AuthController extends Controller
{
    public function showLogin(): View|RedirectResponse
    {
        if (auth()->check()) {
            return redirect()->to(route('studio', [], false));
        }

        return view('auth.login');
    }

    public function login(Request $request): RedirectResponse
    {
        $email = $request->input('email');
        if (is_string($email)) {
            $request->merge(['email' => strtolower(trim($email))]);
        }

        $credentials = $request->validate([
            'email' => ['required', 'email', 'max:255'],
            'password' => ['required', 'string', 'max:255'],
        ]);

        if (! Auth::attempt($credentials, $request->boolean('remember'))) {
            return back()->withErrors([
                'email' => 'Those sign-in details were not recognized.',
            ])->onlyInput('email');
        }

        if ($request->user()?->isExpired()) {
            Auth::logout();
            $request->session()->invalidate();
            $request->session()->regenerateToken();

            return redirect()->to(route('login', [], false))->withErrors([
                'email' => 'This temporary test account has expired. Ask an administrator for a new account.',
            ])->onlyInput('email');
        }

        $request->session()->regenerate();

        return redirect()->intended(route('studio', [], false));
    }

    public function showRegister(): View|RedirectResponse
    {
        if (auth()->check()) {
            return redirect()->to(route('studio', [], false));
        }

        return view('auth.register');
    }

    public function register(Request $request): RedirectResponse
    {
        $name = $request->input('name');
        $email = $request->input('email');
        $request->merge([
            'name' => is_string($name) ? trim($name) : $name,
            'email' => is_string($email) ? strtolower(trim($email)) : $email,
        ]);
        $validated = $request->validate([
            'name' => ['required', 'string', 'min:2', 'max:100'],
            'email' => ['required', 'email', 'max:255', 'unique:users,email'],
            'password' => ['required', 'confirmed', Password::min(12)],
        ]);

        $user = User::create([
            'name' => trim($validated['name']),
            'email' => $validated['email'],
            'password' => $validated['password'],
        ]);

        Auth::login($user);
        $request->session()->regenerate();

        return redirect()->to(route('studio', [], false))->with('success', 'Welcome to VeoPrompter. Your project workspace is ready.');
    }

    public function logout(Request $request): RedirectResponse
    {
        Auth::logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->to(route('studio', [], false));
    }
}
