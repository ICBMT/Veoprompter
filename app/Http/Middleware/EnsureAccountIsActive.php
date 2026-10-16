<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

class EnsureAccountIsActive
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if (! $user?->isExpired()) {
            return $next($request);
        }

        Auth::logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        if ($request->expectsJson()) {
            return response()->json([
                'message' => 'This temporary test account has expired. Ask an administrator for a new account.',
            ], 401);
        }

        return redirect()->guest(route('login', [], false))->withErrors([
            'email' => 'This temporary test account has expired. Ask an administrator for a new account.',
        ]);
    }
}
