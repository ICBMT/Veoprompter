<?php

use App\Http\Controllers\AccountController;
use App\Http\Controllers\Admin\AiSettingsController;
use App\Http\Controllers\Admin\UserManagementController;
use App\Http\Controllers\AuthController;
use App\Http\Controllers\PasswordResetController;
use App\Http\Controllers\PresenceController;
use App\Http\Controllers\PromptHistoryController;
use App\Http\Controllers\StudioController;
use App\Http\Controllers\StudioProjectController;
use App\Http\Controllers\VoiceSampleController;
use Illuminate\Support\Facades\Route;

Route::view('/guide', 'pages.guide')->name('guide');

Route::middleware('guest')->group(function (): void {
    Route::get('/login', [AuthController::class, 'showLogin'])->name('login');
    Route::post('/login', [AuthController::class, 'login'])->middleware('throttle:8,1')->name('login.store');
    Route::get('/register', [AuthController::class, 'showRegister'])->name('register');
    Route::post('/register', [AuthController::class, 'register'])->middleware('throttle:5,1')->name('register.store');

    Route::get('/forgot-password', [PasswordResetController::class, 'requestForm'])->name('password.request');
    Route::post('/forgot-password', [PasswordResetController::class, 'sendLink'])->middleware('throttle:3,1')->name('password.email');
    Route::get('/reset-password/{token}', [PasswordResetController::class, 'resetForm'])->name('password.reset');
    Route::post('/reset-password', [PasswordResetController::class, 'reset'])->middleware('throttle:5,1')->name('password.update');
});

Route::middleware('user.auth')->group(function (): void {
    Route::get('/', [StudioProjectController::class, 'index'])->name('studio');
    Route::post('/logout', [AuthController::class, 'logout'])->name('logout');
    Route::post('/presence', [PresenceController::class, 'ping'])->middleware('throttle:2,1')->name('presence.ping');
    Route::post('/voice-samples', [VoiceSampleController::class, 'store'])->middleware('throttle:12,1')->name('voice-samples.store');

    Route::get('/projects', [StudioProjectController::class, 'index'])->name('projects.index');
    Route::get('/projects/create', [StudioProjectController::class, 'create'])->name('projects.create');
    Route::post('/projects', [StudioProjectController::class, 'store'])->name('projects.store');
    Route::get('/projects/{studioProject}/settings', [StudioProjectController::class, 'edit'])->name('projects.settings.edit');
    Route::put('/projects/{studioProject}/settings', [StudioProjectController::class, 'update'])->name('projects.settings.update');
    Route::post('/projects/{studioProject}/generate', [StudioController::class, 'generate'])
        ->middleware('throttle:8,1')
        ->name('studio.generate');
    Route::get('/projects/{studioProject}', [StudioProjectController::class, 'show'])->name('projects.show');

    Route::get('/history', [PromptHistoryController::class, 'index'])->name('history.index');
    Route::get('/history/{project}', [PromptHistoryController::class, 'show'])->name('history.show');
    Route::delete('/history/{project}', [PromptHistoryController::class, 'destroy'])->name('history.destroy');

    Route::get('/account', [AccountController::class, 'edit'])->name('account.edit');
    Route::put('/account/profile', [AccountController::class, 'updateProfile'])->name('account.profile.update');
    Route::put('/account/password', [AccountController::class, 'updatePassword'])->name('account.password.update');
});

Route::prefix('admin')
    ->name('admin.')
    ->middleware('admin')
    ->group(function (): void {
        Route::get('/', [AiSettingsController::class, 'edit'])->name('settings');
        Route::post('/models', [AiSettingsController::class, 'models'])->middleware('throttle:8,1')->name('models');
        Route::post('/test-connection', [AiSettingsController::class, 'testConnection'])->middleware('throttle:5,1')->name('connection.test');
        Route::put('/settings', [AiSettingsController::class, 'update'])->name('settings.update');
        Route::put('/voice-settings', [AiSettingsController::class, 'updateVoice'])->name('voice-settings.update');

        Route::get('/users', [UserManagementController::class, 'index'])->name('users.index');
        Route::get('/users/presence', [UserManagementController::class, 'presence'])->middleware('throttle:10,1')->name('users.presence');
        Route::post('/users/test-accounts', [UserManagementController::class, 'createTestAccount'])->name('users.test-accounts.store');
        Route::get('/users/{user}', [UserManagementController::class, 'show'])->name('users.show');
    });
