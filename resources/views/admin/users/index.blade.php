@extends('layouts.app')

@section('title', 'User management — VeoPrompter')
@section('eyebrow', 'USER MANAGEMENT')

@section('content')
<div class="admin-users-page" data-admin-presence-url="{{ route('admin.users.presence', [], false) }}">
    <section class="admin-hero admin-users-hero">
        <div><span class="section-index">ADMINISTRATOR <i>/</i> ACCOUNTS &amp; ACTIVITY</span><h1>User management</h1><p>Review registered accounts, see recent activity, and open a customer’s generated prompt history.</p></div>
        <div class="admin-ready"><i></i>{{ number_format($totalUsers) }} REGISTERED {{ \Illuminate\Support\Str::plural('USER', $totalUsers) }}</div>
    </section>

    @if(session('test_account_credentials'))
        @php($credentials = session('test_account_credentials'))
        <section class="test-account-credentials" aria-labelledby="test-account-credentials-heading">
            <div><span class="section-index">ONE-TIME CREDENTIALS</span><h2 id="test-account-credentials-heading">{{ $credentials['role'] }} test account created</h2><p>Save these details now. The generated password is shown only on this page view; the account expires at {{ \Illuminate\Support\Carbon::parse($credentials['expires_at'])->format('M j, Y · g:i:s A T') }}.</p></div>
            <dl><div><dt>Email</dt><dd>{{ $credentials['email'] }}</dd></div><div><dt>Temporary password</dt><dd><code>{{ $credentials['password'] }}</code></dd></div><div><dt>Account type</dt><dd>{{ $credentials['role'] }}</dd></div></dl>
        </section>
    @endif

    <section class="admin-card test-account-card" aria-labelledby="test-account-heading">
        <header class="admin-card-header"><span class="admin-card-icon">⏱</span><div><h2 id="test-account-heading">Create a temporary test account</h2><p>Choose customer access or administrator access. Every test account becomes unusable exactly three hours after it is created.</p></div></header>
        <form class="test-account-form" action="{{ route('admin.users.test-accounts.store', [], false) }}" method="post">
            @csrf
            <div class="admin-form-grid">
                <div class="admin-field"><label class="field-label" for="test-name">Name</label><input class="text-input" id="test-name" name="name" type="text" maxlength="100" required value="{{ old('name') }}" placeholder="Test customer"><small class="field-hint">Shown in the account profile.</small>@error('name')<p class="admin-error">{{ $message }}</p>@enderror</div>
                <div class="admin-field"><label class="field-label" for="test-email">Email</label><input class="text-input" id="test-email" name="email" type="email" maxlength="255" required value="{{ old('email') }}" placeholder="tester@example.com">@error('email')<p class="admin-error">{{ $message }}</p>@enderror</div>
                <div class="admin-field"><label class="field-label" for="account_type">Access level</label><select class="text-input select-input" id="account_type" name="account_type" required><option value="customer" @selected(old('account_type', 'customer') === 'customer')>Customer account</option><option value="admin" @selected(old('account_type') === 'admin')>Administrator account</option></select>@error('account_type')<p class="admin-error">{{ $message }}</p>@enderror</div>
            </div>
            <div class="test-account-form-footer"><p>A strong password is generated securely and displayed once after creation. It is stored only as a hash.</p><button class="button-primary" type="submit"><span>Create test account</span><span class="button-icon" aria-hidden="true">↗</span></button></div>
        </form>
    </section>

    <section class="admin-card users-directory-card" aria-labelledby="users-directory-heading">
        <div class="users-directory-header"><div><span class="section-index">REGISTERED USERS</span><h2 id="users-directory-heading">Accounts &amp; prompt activity</h2></div><form class="history-search admin-user-search" action="{{ route('admin.users.index', [], false) }}" method="get" role="search"><svg viewBox="0 0 20 20" aria-hidden="true"><circle cx="8.8" cy="8.8" r="5.5"/><path d="m13 13 4 4"/></svg><input type="search" name="q" value="{{ $search }}" maxlength="100" placeholder="Search name or email" aria-label="Search users">@if($search)<a href="{{ route('admin.users.index', [], false) }}" class="search-clear" aria-label="Clear search">×</a>@endif<button type="submit">Search</button></form></div>

        @if($users->count())
            <div class="admin-users-table-wrap"><table class="admin-users-table"><thead><tr><th>Account</th><th>Presence</th><th>Projects / prompts</th><th>Account status</th><th></th></tr></thead><tbody>
                @foreach($users as $user)
                    <tr>
                        <td><a class="admin-user-name" href="{{ route('admin.users.show', $user, false) }}">{{ $user->name }}</a><span class="admin-user-email">{{ $user->email }}</span><small>Joined {{ $user->created_at->format('M j, Y') }}@if($user->is_admin) · Administrator @endif</small></td>
                        <td><span class="presence-indicator {{ $user->isOnline() ? 'is-online' : 'is-offline' }}" data-user-presence data-user-id="{{ $user->id }}"><i></i><span data-presence-label>{{ $user->isOnline() ? 'Online' : 'Offline' }}</span></span><small data-presence-time>{{ $user->last_seen_at ? 'Last seen '.$user->last_seen_at->diffForHumans() : 'No recent activity' }}</small></td>
                        <td><span class="user-counts">{{ $user->studio_projects_count }} {{ \Illuminate\Support\Str::plural('project', $user->studio_projects_count) }}</span><small>{{ $user->prompt_projects_count }} {{ \Illuminate\Support\Str::plural('prompt kit', $user->prompt_projects_count) }}</small></td>
                        <td>@if($user->isExpired())<span class="user-status-pill is-expired">Expired</span>@elseif($user->expires_at)<span class="user-status-pill is-temporary">Test account</span><small>Expires {{ $user->expires_at->format('M j · g:i A') }}</small>@else<span class="user-status-pill is-permanent">Active</span><small>Permanent account</small>@endif</td>
                        <td><a class="admin-user-view" href="{{ route('admin.users.show', $user, false) }}">View prompts <span aria-hidden="true">↗</span></a></td>
                    </tr>
                @endforeach
            </tbody></table></div>
            {{ $users->links('components.pagination') }}
        @else
            <section class="history-empty admin-users-empty"><h3>No accounts match that search.</h3><p>Try a different name or email address.</p></section>
        @endif
    </section>
</div>
@endsection
