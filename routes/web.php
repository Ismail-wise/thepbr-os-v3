<?php

use App\Http\Controllers\CreateBusinessController;
use App\Http\Controllers\SelectCurrentBusinessController;
use App\Http\Middleware\EnsureCurrentBusinessContext;
use App\Presentation\Http\Controllers\Account\AccountSettingsController;
use App\Presentation\Http\Controllers\Auth\AuthenticatedSessionController;
use App\Presentation\Http\Controllers\Records\ActivityController;
use App\Presentation\Http\Controllers\Records\DocumentVaultController;
use App\Presentation\Http\Controllers\Records\EvidenceController;
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
    Route::get('/businesses/create', [CreateBusinessController::class, 'create'])
        ->name('businesses.create');
    Route::post('/businesses', [CreateBusinessController::class, 'store'])
        ->name('businesses.store');
    Route::post('/current-business', SelectCurrentBusinessController::class)
        ->name('business-context.select');
    Route::get('/', function (Request $request) {
        return Inertia::render('AccountHome', [
            'account' => [
                'email' => $request->user()->email,
            ],
        ]);
    })->name('home');
    Route::get('/records/activity', ActivityController::class)
        ->middleware(EnsureCurrentBusinessContext::class)
        ->name('records.activity');

    Route::middleware(EnsureCurrentBusinessContext::class)
        ->group(function (): void {
            Route::get(
                '/records/documents',
                [DocumentVaultController::class, 'index'],
            )->name('records.documents.index');

            Route::post(
                '/records/documents',
                [DocumentVaultController::class, 'store'],
            )->name('records.documents.store');

            Route::get(
                '/records/documents/{document}',
                [DocumentVaultController::class, 'show'],
            )->name('records.documents.show');

            Route::post(
                '/records/documents/{document}/versions',
                [DocumentVaultController::class, 'storeVersion'],
            )->name('records.documents.versions.store');

            Route::get(
                '/records/documents/{document}/versions/{documentVersion}/download',
                [DocumentVaultController::class, 'downloadVersion'],
            )->name('records.documents.versions.download');

            Route::post(
                '/records/documents/{document}/versions/{documentVersion}/evidence',
                [EvidenceController::class, 'store'],
            )->name('records.documents.versions.evidence.store');

            Route::post(
                '/records/evidence/{evidence}/links',
                [EvidenceController::class, 'link'],
            )->name('records.evidence.links.store');

            Route::post(
                '/records/evidence/{evidence}/verify',
                [EvidenceController::class, 'verify'],
            )->name('records.evidence.verify');
        });
    Route::get('/account/settings', [AccountSettingsController::class, 'show'])
        ->name('account.settings.show');
    Route::patch('/account/settings', [AccountSettingsController::class, 'update'])
        ->name('account.settings.update');
});
Route::post('/logout', [AuthenticatedSessionController::class, 'destroy'])
    ->middleware('auth')
    ->name('logout');
