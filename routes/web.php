<?php

use App\Http\Controllers\CreateBusinessController;
use App\Http\Controllers\SelectCurrentBusinessController;
use App\Http\Middleware\EnsureCurrentBusinessContext;
use App\Presentation\Http\Controllers\Access\WorkspaceAccessController;
use App\Presentation\Http\Controllers\Account\AccountSettingsController;
use App\Presentation\Http\Controllers\Auth\AuthenticatedSessionController;
use App\Presentation\Http\Controllers\Formation\FormationController;
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
                '/formation',
                [FormationController::class, 'index'],
            )->name('formation.index');

            Route::put(
                '/formation/new/idea',
                [FormationController::class, 'saveIdea'],
            )->name('formation.new.idea.update');

            Route::put(
                '/formation/bmc',
                [FormationController::class, 'saveBmc'],
            )->name('formation.bmc.update');

            Route::post(
                '/formation/new/assumptions',
                [FormationController::class, 'addAssumption'],
            )->name('formation.new.assumptions.store');

            Route::post(
                '/formation/new/validations',
                [FormationController::class, 'addValidation'],
            )->name('formation.new.validations.store');

            Route::post(
                '/formation/new/validations/{validationActivity}/evidence',
                [FormationController::class, 'linkValidationEvidence'],
            )->name('formation.new.validations.evidence.store');

            Route::post(
                '/formation/new/feasibility',
                [FormationController::class, 'addFeasibility'],
            )->name('formation.new.feasibility.store');

            Route::put(
                '/formation/new/partnership-fit',
                [FormationController::class, 'savePartnershipFit'],
            )->name('formation.new.partnership-fit.update');

            Route::post(
                '/formation/new/direction',
                [FormationController::class, 'recordDirection'],
            )->name('formation.new.direction.store');

            Route::put(
                '/formation/existing/profile',
                [FormationController::class, 'saveExistingProfile'],
            )->name('formation.existing.profile.update');

            Route::post(
                '/formation/existing/financial-snapshots',
                [FormationController::class, 'addFinancialSnapshot'],
            )->name('formation.existing.financial-snapshots.store');

            Route::post(
                '/formation/existing/assets',
                [FormationController::class, 'addAsset'],
            )->name('formation.existing.assets.store');

            Route::post(
                '/formation/existing/liabilities',
                [FormationController::class, 'addLiability'],
            )->name('formation.existing.liabilities.store');

            Route::post(
                '/formation/existing/owner-positions',
                [FormationController::class, 'addOwnerPosition'],
            )->name('formation.existing.owner-positions.store');

            Route::post(
                '/formation/existing/obligations',
                [FormationController::class, 'addObligation'],
            )->name('formation.existing.obligations.store');

            Route::post(
                '/formation/existing/risks',
                [FormationController::class, 'addRisk'],
            )->name('formation.existing.risks.store');

            Route::post(
                '/formation/existing/constraints',
                [FormationController::class, 'addConstraint'],
            )->name('formation.existing.constraints.store');

            Route::put(
                '/formation/existing/gap',
                [FormationController::class, 'saveGap'],
            )->name('formation.existing.gap.update');

            Route::put(
                '/formation/existing/conversion',
                [FormationController::class, 'saveConversion'],
            )->name('formation.existing.conversion.update');

            Route::post(
                '/formation/existing/valuations',
                [FormationController::class, 'addValuation'],
            )->name('formation.existing.valuations.store');

            Route::put(
                '/formation/capital/scenarios/{kind}',
                [FormationController::class, 'saveCapitalScenario'],
            )->name('formation.capital.scenarios.update');

            Route::post(
                '/formation/capital/scenarios/{kind}/promote',
                [FormationController::class, 'promoteCapitalScenario'],
            )->name('formation.capital.scenarios.promote');

            Route::post(
                '/formation/capital/promotions/{promotion}/content-review',
                [FormationController::class, 'advanceCapitalContentReview'],
            )->name('formation.capital.promotions.content-review');

            Route::get(
                '/formation/capital/scenarios/{kind}/export',
                [FormationController::class, 'exportCapitalScenario'],
            )->name('formation.capital.scenarios.export');

            Route::get(
                '/governance',
                [GovernanceWorkspaceController::class, 'index'],
            )->name('governance.index');

            Route::post(
                '/governance/proposal-versions/{proposalVersion}/reviews',
                [GovernanceWorkspaceController::class, 'createProposalReview'],
            )->name('governance.proposal-reviews.store');

            Route::post(
                '/governance/proposal-reviews/{proposalReview}/complete',
                [GovernanceWorkspaceController::class, 'completeProposalReview'],
            )->name('governance.proposal-reviews.complete');

            Route::post(
                '/governance/proposal-versions/{proposalVersion}/decisions',
                [GovernanceWorkspaceController::class, 'openDecision'],
            )->name('governance.proposal-versions.decisions.store');

            Route::post(
                '/governance/record-versions/{formalRecordVersion}/reviews',
                [GovernanceWorkspaceController::class, 'createReview'],
            )->name('governance.record-reviews.store');

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
