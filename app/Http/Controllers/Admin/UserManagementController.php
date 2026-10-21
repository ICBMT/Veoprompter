<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Support\ProjectSettings;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class UserManagementController extends Controller
{
    public function index(Request $request): Response
    {
        $validated = $request->validate(['q' => ['nullable', 'string', 'max:100']]);
        $search = trim((string) ($validated['q'] ?? ''));

        $users = User::query()
            ->withCount(['promptProjects', 'studioProjects'])
            ->when($search !== '', function ($query) use ($search): void {
                $query->where(function ($query) use ($search): void {
                    $query->where('name', 'like', '%'.$search.'%')
                        ->orWhere('email', 'like', '%'.$search.'%');
                });
            })
            ->orderByDesc('created_at')
            ->paginate(20)
            ->withQueryString();

        return response()->view('admin.users.index', [
            'users' => $users,
            'search' => $search,
            'totalUsers' => User::query()->count(),
        ])->header('Cache-Control', 'no-store, private');
    }

    public function presence(): JsonResponse
    {
        $users = User::query()->select(['id', 'last_seen_at', 'expires_at'])->get();

        return response()->json([
            'users' => $users->mapWithKeys(fn (User $user): array => [
                $user->id => [
                    'online' => $user->isOnline(),
                    'last_seen_at' => $user->last_seen_at?->toIso8601String(),
                ],
            ]),
        ])->header('Cache-Control', 'no-store, private');
    }

    public function createTestAccount(Request $request): RedirectResponse
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
            'account_type' => ['required', Rule::in(['customer', 'admin'])],
        ]);

        $password = Str::password(24);
        $createdAt = now();
        $expiresAt = $createdAt->copy()->addHours(3);
        $user = new User;
        $user->name = $validated['name'];
        $user->email = $validated['email'];
        $user->password = $password;
        $user->is_admin = $validated['account_type'] === 'admin';
        $user->created_at = $createdAt;
        $user->expires_at = $expiresAt;
        $user->save();

        return redirect()->to(route('admin.users.index', [], false))
            ->with('success', 'Temporary test account created. It expires three hours after creation.')
            ->with('test_account_credentials', [
                'name' => $user->name,
                'email' => $user->email,
                'password' => $password,
                'role' => $user->is_admin ? 'Administrator' : 'Customer',
                'expires_at' => $expiresAt->toIso8601String(),
            ]);
    }

    public function updateDurationOptions(Request $request, User $user): RedirectResponse
    {
        $validated = $request->validate([
            'can_choose_clip_duration' => ['required', 'boolean'],
            'can_choose_extension_duration' => ['required', 'boolean'],
        ]);

        $user->can_choose_clip_duration = (bool) $validated['can_choose_clip_duration'];
        $user->can_choose_extension_duration = (bool) $validated['can_choose_extension_duration'];
        $user->save();

        return redirect()->to(route('admin.users.show', $user, false))
            ->with('success', 'This account’s duration choices have been updated. Disabled choices let AI match each beat to its complexity, up to 10 seconds without padding.');
    }

    public function show(User $user): Response
    {
        $runs = $user->promptProjects()->with('studioProject')->latest()->paginate(10);
        $globalDurationOptions = ProjectSettings::globalDurationOptions();

        return response()->view('admin.users.show', compact('user', 'runs', 'globalDurationOptions'))
            ->header('Cache-Control', 'no-store, private');
    }
}
