<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\Response;

class TrackUserPresence
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user && (! $user->last_seen_at || $user->last_seen_at->lessThan(now()->subSeconds(50)))) {
            $now = now();
            DB::table('users')->where('id', $user->getKey())->update(['last_seen_at' => $now]);
            $user->setAttribute('last_seen_at', $now);
        }

        return $next($request);
    }
}
