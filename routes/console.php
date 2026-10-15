<?php

use App\Models\User;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Hash;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Artisan::command('veoprompter:admin:create {name} {email}', function (string $name, string $email) {
    if (! filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $this->error('Please provide a valid email address.');

        return 1;
    }

    $password = $this->secret('Choose a password (at least 12 characters)');

    if (! is_string($password) || strlen($password) < 12) {
        $this->error('The password must contain at least 12 characters. No account was changed.');

        return 1;
    }

    $user = User::query()->firstOrNew(['email' => strtolower($email)]);
    $isNewUser = ! $user->exists;
    $user->name = $name;
    $user->is_admin = true;

    if ($isNewUser) {
        $user->password = Hash::make($password);
    }

    $user->save();

    $this->info(($isNewUser ? 'Administrator account created for ' : 'Administrator access enabled for ').$user->email.'.');
    $this->line('Sign in at '.rtrim((string) config('app.url'), '/').'/login');

    return 0;
})->purpose('Create or promote a VeoPrompter administrator account');
