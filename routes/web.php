<?php

use App\Presentation\Http\Controllers\Account\AccountSettingsController;
use App\Presentation\Http\Controllers\Auth\AuthenticatedSessionController;
use App\Presentation\Http\Middleware\EnsureActiveAccount;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Inertia\Inertia;

Route::middleware('guest')->group(function (): void {
    Route::get('/login', [AuthenticatedSessionController::class, 'show'])
        ->name('login');

    Route::post('/login', [AuthenticatedSessionController::class, 'store'])
        ->middleware('throttle:login')
        ->name('login.store');
});

Route::middleware(['auth', EnsureActiveAccount::class])->group(function (): void {
    Route::get('/', function (Request $request) {
        return Inertia::render('AccountHome', [
            'account' => [
                'email' => $request->user()->email,
            ],
        ]);
    })->name('home');

    Route::get('/account/settings', [AccountSettingsController::class, 'show'])
        ->name('account.settings.show');

    Route::patch('/account/settings', [AccountSettingsController::class, 'update'])
        ->name('account.settings.update');
});

Route::post('/logout', [AuthenticatedSessionController::class, 'destroy'])
    ->middleware('auth')
    ->name('logout');
