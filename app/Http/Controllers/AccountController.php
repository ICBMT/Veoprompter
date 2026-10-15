<?php

namespace App\Http\Controllers;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;
use Illuminate\View\View;

class AccountController extends Controller
{
    public function edit(Request $request): View
    {
        return view('account.edit', [
            'user' => $request->user(),
            'projectCount' => $request->user()->promptProjects()->count(),
        ]);
    }

    public function updateProfile(Request $request): RedirectResponse
    {
        $user = $request->user();
        $name = $request->input('name');
        $email = $request->input('email');
        $request->merge([
            'name' => is_string($name) ? trim($name) : $name,
            'email' => is_string($email) ? strtolower(trim($email)) : $email,
        ]);
        $validated = $request->validate([
            'name' => ['required', 'string', 'min:2', 'max:100'],
            'email' => ['required', 'email', 'max:255', Rule::unique('users', 'email')->ignore($user->id)],
        ]);

        if ($validated['email'] !== $user->email) {
            $user->email_verified_at = null;
        }

        $user->name = trim($validated['name']);
        $user->email = $validated['email'];
        $user->save();

        return redirect()->to(route('account.edit', [], false))->with('success', 'Your account details have been updated.');
    }

    public function updatePassword(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'current_password' => ['required', 'current_password'],
            'password' => ['required', 'confirmed', Password::min(12)],
        ]);

        $user = $request->user();
        $user->password = $validated['password'];
        $user->setRememberToken(Str::random(60));
        $user->save();

        return redirect()->to(route('account.edit', [], false))->with('success', 'Your password has been changed.');
    }
}
