<?php

use App\Http\Controllers\CreateBusinessController;
use App\Http\Controllers\SelectCurrentBusinessController;
use App\Http\Middleware\EnsureCurrentBusinessContext;
use App\Presentation\Http\Controllers\Access\WorkspaceAccessController;
use App\Presentation\Http\Controllers\Account\AccountSettingsController;
use App\Presentation\Http\Controllers\Auth\AuthenticatedSessionController;
use App\Presentation\Http\Controllers\Governance\GovernanceWorkspaceController;
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
                '/workspace/access',
                WorkspaceAccessController::class,
            )->name('workspace.access.index');

            Route::get(
                '/governance',
                [GovernanceWorkspaceController::class, 'index'],
            )->name('governance.index');

            Route::post(
                '/governance/decisions/{decision}/approvals',
                [GovernanceWorkspaceController::class, 'approval'],
            )->name('governance.decisions.approvals.store');

            Route::post(
                '/governance/decisions/{decision}/votes',
                [GovernanceWorkspaceController::class, 'vote'],
            )->name('governance.decisions.votes.store');

            Route::post(
                '/governance/decisions/{decision}/recusal',
                [GovernanceWorkspaceController::class, 'recuse'],
            )->name('governance.decisions.recusal.store');

            Route::post(
                '/governance/decisions/{decision}/resolve',
                [GovernanceWorkspaceController::class, 'resolveDecision'],
            )->name('governance.decisions.resolve');

            Route::post(
                '/governance/decisions/{decision}/signature-requests',
                [GovernanceWorkspaceController::class, 'createSignatureRequest'],
            )->name('governance.decisions.signature-requests.store');

            Route::post(
                '/governance/signature-requests/{signatureRequest}/send',
                [GovernanceWorkspaceController::class, 'sendSignatureRequest'],
            )->name('governance.signature-requests.send');

            Route::post(
                '/governance/signature-requests/{signatureRequest}/sign',
                [GovernanceWorkspaceController::class, 'sign'],
            )->name('governance.signature-requests.sign');

            Route::post(
                '/governance/signature-requests/{signatureRequest}/decline',
                [GovernanceWorkspaceController::class, 'declineSignature'],
            )->name('governance.signature-requests.decline');

            Route::post(
                '/governance/signature-requests/{signatureRequest}/complete',
                [GovernanceWorkspaceController::class, 'completeSignatureRequest'],
            )->name('governance.signature-requests.complete');

            Route::post(
                '/governance/decisions/{decision}/prepare-effect',
                [GovernanceWorkspaceController::class, 'prepareEffect'],
            )->name('governance.decisions.prepare-effect');

            Route::post(
                '/governance/decisions/{decision}/make-effective',
                [GovernanceWorkspaceController::class, 'makeEffective'],
            )->name('governance.decisions.make-effective');

            Route::post(
                '/governance/decisions/{decision}/actions',
                [GovernanceWorkspaceController::class, 'createAction'],
            )->name('governance.decisions.actions.store');

            Route::post(
                '/governance/actions/{action}/status',
                [GovernanceWorkspaceController::class, 'updateAction'],
            )->name('governance.actions.status.update');

            Route::post(
                '/governance/reviews/{review}/complete',
                [GovernanceWorkspaceController::class, 'completeReview'],
            )->name('governance.reviews.complete');

            Route::post(
                '/governance/record-versions/{formalRecordVersion}/amendments',
                [GovernanceWorkspaceController::class, 'createAmendment'],
            )->name('governance.amendments.store');

            Route::post(
                '/governance/amendments/{amendment}/resolve',
                [GovernanceWorkspaceController::class, 'resolveAmendment'],
            )->name('governance.amendments.resolve');

            Route::post(
                '/governance/notifications/{notification}/read',
                [GovernanceWorkspaceController::class, 'markNotificationRead'],
            )->name('governance.notifications.read');

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
