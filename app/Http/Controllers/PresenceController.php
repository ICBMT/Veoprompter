<?php

namespace App\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class PresenceController extends Controller
{
    public function ping(Request $request): JsonResponse
    {
        $user = $request->user();

        if (! $user) {
            return response()->json(['message' => 'Sign in to continue.'], 401);
        }

        $now = now();
        DB::table('users')->where('id', $user->getKey())->update(['last_seen_at' => $now]);
        $user->setAttribute('last_seen_at', $now);

        return response()->json(['last_seen_at' => $now->toIso8601String()]);
    }
}
