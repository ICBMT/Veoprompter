<?php

use App\Http\Controllers\Admin\AiSettingsController;
use App\Http\Controllers\AuthController;
use App\Http\Controllers\StudioController;
use Illuminate\Support\Facades\Route;

Route::get('/', [StudioController::class, 'index'])->name('studio');
Route::post('/generate', [StudioController::class, 'generate'])
    ->middleware('throttle:8,1')
    ->name('studio.generate');

Route::view('/guide', 'pages.guide')->name('guide');

Route::get('/login', [AuthController::class, 'showLogin'])->name('login');
Route::post('/login', [AuthController::class, 'login'])->middleware('throttle:6,1')->name('login.store');
Route::post('/logout', [AuthController::class, 'logout'])->name('logout');

Route::prefix('admin')
    ->name('admin.')
    ->middleware('admin')
    ->group(function (): void {
        Route::get('/', [AiSettingsController::class, 'edit'])->name('settings');
        Route::put('/settings', [AiSettingsController::class, 'update'])->name('settings.update');
    });
