<?php

use App\Http\Controllers\CreateBusinessController;
use App\Http\Controllers\SelectCurrentBusinessController;
use App\Http\Middleware\EnsureCurrentBusinessContext;
use App\Presentation\Http\Controllers\Access\BusinessAccessInvitationRedemptionController;
use App\Presentation\Http\Controllers\Access\WorkspaceAccessController;
use App\Presentation\Http\Controllers\Access\WorkspaceAccessInvitationController;
use App\Presentation\Http\Controllers\Account\AccountExperienceController;
use App\Presentation\Http\Controllers\Account\AccountSettingsController;
use App\Presentation\Http\Controllers\AI\PbrAiController;
use App\Presentation\Http\Controllers\Auth\AuthenticatedSessionController;
use App\Presentation\Http\Controllers\Closure\ClosureWorkspaceController;
use App\Presentation\Http\Controllers\Conflict\ConflictWorkspaceController;
use App\Presentation\Http\Controllers\Continuity\ContinuityWorkspaceController;
use App\Presentation\Http\Controllers\Dashboard\BusinessControlCenterController;
use App\Presentation\Http\Controllers\Exit\ExitWorkspaceController;
use App\Presentation\Http\Controllers\Finance\FinanceWorkspaceController;
use App\Presentation\Http\Controllers\Formation\FormationController;
use App\Presentation\Http\Controllers\Governance\GovernanceMeetingController;
use App\Presentation\Http\Controllers\Governance\GovernanceRulesController;
use App\Presentation\Http\Controllers\Governance\GovernanceWorkspaceController;
use App\Presentation\Http\Controllers\Health\HealthController;
use App\Presentation\Http\Controllers\Import\ImportController;
use App\Presentation\Http\Controllers\Legal\LegalArchitectureController;
use App\Presentation\Http\Controllers\Operations\OperationsWorkspaceController;
use App\Presentation\Http\Controllers\PartnerChanges\PartnerChangesWorkspaceController;
use App\Presentation\Http\Controllers\Partnership\PartnershipWorkflowController;
use App\Presentation\Http\Controllers\Partnership\PartnershipWorkspaceController;
use App\Presentation\Http\Controllers\Portability\PortabilityController;
use App\Presentation\Http\Controllers\Records\ActivityController;
use App\Presentation\Http\Controllers\Records\DocumentVaultController;
use App\Presentation\Http\Controllers\Records\EvidenceController;
use App\Presentation\Http\Controllers\Reporting\ReportsController;
use App\Presentation\Http\Controllers\Rewards\RewardsWorkspaceController;
use App\Presentation\Http\Controllers\Risk\RiskWorkspaceController;
use App\Presentation\Http\Controllers\Search\SearchController;
use App\Presentation\Http\Middleware\EnsureActiveAccount;
use Illuminate\Support\Facades\Route;

Route::middleware('guest')->group(function (): void {
    Route::get('/login', [AuthenticatedSessionController::class, 'show'])
        ->name('login');
    Route::post('/login', [AuthenticatedSessionController::class, 'store'])
        ->middleware('throttle:login')
        ->name('login.store');
});

Route::get(
    '/access/invitation',
    [BusinessAccessInvitationRedemptionController::class, 'show'],
)->name('access.invitations.redeem.show');

Route::post(
    '/access/invitation/redeem',
    [BusinessAccessInvitationRedemptionController::class, 'store'],
)
    ->middleware('throttle:login')
    ->name('access.invitations.redeem.store');

Route::middleware(['auth', EnsureActiveAccount::class])->group(function (): void {
    Route::get('/businesses/create', [CreateBusinessController::class, 'create'])
        ->name('businesses.create');
    Route::post('/businesses', [CreateBusinessController::class, 'store'])
        ->name('businesses.store');
    Route::post('/current-business', SelectCurrentBusinessController::class)
        ->name('business-context.select');
    Route::get(
        '/',
        [AccountExperienceController::class, 'home'],
    )->name('home');
    Route::get(
        '/account/businesses',
        [AccountExperienceController::class, 'businesses'],
    )->name('account.businesses');
    Route::get(
        '/account/work',
        [AccountExperienceController::class, 'work'],
    )->name('account.work');
    Route::get(
        '/account/notifications',
        [AccountExperienceController::class, 'notifications'],
    )->name('account.notifications');
    Route::get(
        '/account/approvals',
        [AccountExperienceController::class, 'approvals'],
    )->name('account.approvals');
    Route::get(
        '/account/signatures',
        [AccountExperienceController::class, 'signatures'],
    )->name('account.signatures');
    Route::get('/records/activity', ActivityController::class)
        ->middleware(EnsureCurrentBusinessContext::class)
        ->name('records.activity');

    Route::middleware(EnsureCurrentBusinessContext::class)
        ->group(function (): void {
            Route::get('/overview', BusinessControlCenterController::class)
                ->name('business.control-center');

            Route::get('/search', SearchController::class)
                ->name('search.index');

            Route::get('/ai', [PbrAiController::class, 'index'])
                ->name('ai.index');

            Route::post('/ai/ask', [PbrAiController::class, 'ask'])
                ->name('ai.ask');

            Route::get('/health', HealthController::class)
                ->name('health.index');

            Route::get('/reports', [ReportsController::class, 'index'])
                ->name('reports.index');

            Route::post(
                '/reports/business-packs',
                [ReportsController::class, 'create'],
            )->name('reports.business-packs.create');

            Route::post(
                '/reports/business-packs/{export}/generate',
                [ReportsController::class, 'generate'],
            )->name('reports.business-packs.generate');

            Route::get(
                '/reports/business-packs/{export}/download',
                [ReportsController::class, 'download'],
            )->name('reports.business-packs.download');

            Route::get('/import', [ImportController::class, 'index'])
                ->name('import.index');

            Route::post('/import/batches', [ImportController::class, 'create'])
                ->name('import.batches.create');

            Route::post(
                '/import/batches/{batch}/parse',
                [ImportController::class, 'parse'],
            )->name('import.batches.parse');

            Route::post(
                '/import/batches/{batch}/validate',
                [ImportController::class, 'validateBatch'],
            )->name('import.batches.validate');

            Route::post(
                '/import/batches/{batch}/confirm',
                [ImportController::class, 'confirm'],
            )->name('import.batches.confirm');

            Route::get(
                '/records/portability',
                [PortabilityController::class, 'index'],
            )->name('portability.index');

            Route::post(
                '/records/portability/archive',
                [PortabilityController::class, 'archive'],
            )->name('portability.archive');

            Route::post(
                '/records/portability/unarchive',
                [PortabilityController::class, 'unarchive'],
            )->name('portability.unarchive');

            Route::post(
                '/records/portability/exports',
                [PortabilityController::class, 'createExport'],
            )->name('portability.exports.create');

            Route::post(
                '/records/portability/exports/{export}/generate',
                [PortabilityController::class, 'generateExport'],
            )->name('portability.exports.generate');

            Route::get(
                '/records/portability/exports/{export}/download',
                [PortabilityController::class, 'downloadExport'],
            )->name('portability.exports.download');

            Route::get(
                '/workspace/access',
                WorkspaceAccessController::class,
            )->name('workspace.access.index');

            Route::post(
                '/workspace/access/invitations',
                [WorkspaceAccessInvitationController::class, 'store'],
            )->name('workspace.access.invitations.store');

            Route::post(
                '/workspace/access/invitations/{invitation}/revoke',
                [WorkspaceAccessInvitationController::class, 'revoke'],
            )->name('workspace.access.invitations.revoke');

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
                '/business/legal-structure',
                [LegalArchitectureController::class, 'index'],
            )->name('legal.index');

            Route::post(
                '/business/legal-structure',
                [LegalArchitectureController::class, 'create'],
            )->name('legal.store');

            Route::post(
                '/business/legal-structure/{formalRecordVersion}/submit',
                [LegalArchitectureController::class, 'submit'],
            )->name('legal.submit');

            Route::post(
                '/business/legal-structure/{formalRecordVersion}/content-review',
                [LegalArchitectureController::class, 'review'],
            )->name('legal.content-review');

            Route::post(
                '/business/legal-structure/{formalRecordVersion}/sync-decision',
                [LegalArchitectureController::class, 'syncDecision'],
            )->name('legal.sync-decision');

            Route::get(
                '/partnership',
                [PartnershipWorkspaceController::class, 'index'],
            )->name('partnership.index');

            Route::post(
                '/partnership/partners',
                [PartnershipWorkspaceController::class, 'createPartner'],
            )->name('partnership.partners.store');

            Route::post(
                '/partnership/partners/{partner}/invite',
                [PartnershipWorkspaceController::class, 'invitePartner'],
            )->name('partnership.partners.invite');

            Route::put(
                '/partnership/partners/{partner}/due-diligence',
                [PartnershipWorkspaceController::class, 'saveDueDiligence'],
            )->name('partnership.partners.due-diligence.update');

            Route::post(
                '/partnership/partners/{partner}/partner-dynamics',
                [PartnershipWorkspaceController::class, 'recordPartnerDynamics'],
            )->name('partnership.partners.partner-dynamics.store');

            Route::post(
                '/partnership/contributions',
                [PartnershipWorkflowController::class, 'createContribution'],
            )->name('partnership.contributions.store');

            Route::put(
                '/partnership/contributions/{contribution}/review',
                [PartnershipWorkflowController::class, 'reviewContribution'],
            )->name('partnership.contributions.review');

            Route::post(
                '/partnership/contributions/{contribution}/governance',
                [PartnershipWorkflowController::class, 'submitContributionGovernance'],
            )->name('partnership.contributions.governance.store');

            Route::post(
                '/partnership/contribution-submissions/{submission}/content-review',
                [PartnershipWorkflowController::class, 'advanceContributionReview'],
            )->name('partnership.contribution-submissions.review');

            Route::post(
                '/partnership/contribution-submissions/{submission}/sync-decision',
                [PartnershipWorkflowController::class, 'syncContributionDecision'],
            )->name('partnership.contribution-submissions.sync');

            Route::post(
                '/partnership/contributions/{contribution}/delivery',
                [PartnershipWorkflowController::class, 'recordDelivery'],
            )->name('partnership.contributions.delivery');

            Route::post(
                '/partnership/ownership/scenarios',
                [PartnershipWorkflowController::class, 'createOwnershipScenario'],
            )->name('partnership.ownership.scenarios.store');

            Route::put(
                '/partnership/ownership/scenarios/{scenario}/share-classes/{shareClass}',
                [PartnershipWorkflowController::class, 'setShareClassRights'],
            )->name('partnership.ownership.share-classes.update');

            Route::put(
                '/partnership/ownership/scenarios/{scenario}/positions/{position}/vesting',
                [PartnershipWorkflowController::class, 'setVesting'],
            )->name('partnership.ownership.vesting.update');

            Route::post(
                '/partnership/ownership/scenarios/{scenario}/freeze',
                [PartnershipWorkflowController::class, 'freezeOwnershipScenario'],
            )->name('partnership.ownership.scenarios.freeze');

            Route::post(
                '/partnership/ownership/scenarios/{scenario}/governance',
                [PartnershipWorkflowController::class, 'submitOwnershipGovernance'],
            )->name('partnership.ownership.governance.store');

            Route::post(
                '/partnership/ownership-submissions/{submission}/content-review',
                [PartnershipWorkflowController::class, 'advanceOwnershipReview'],
            )->name('partnership.ownership-submissions.review');

            Route::post(
                '/partnership/ownership-submissions/{submission}/effect',
                [PartnershipWorkflowController::class, 'effectOwnership'],
            )->name('partnership.ownership-submissions.effect');

            Route::get(
                '/changes/partner-changes',
                [PartnerChangesWorkspaceController::class, 'index'],
            )->name('partner-changes.index');

            Route::post(
                '/changes/partner-changes',
                [PartnerChangesWorkspaceController::class, 'createCase'],
            )->name('partner-changes.store');

            Route::patch(
                '/changes/partner-changes/{case}/draft',
                [PartnerChangesWorkspaceController::class, 'updateDraft'],
            )->name('partner-changes.draft.update');

            Route::post(
                '/changes/partner-changes/{case}/transition',
                [PartnerChangesWorkspaceController::class, 'transitionCase'],
            )->name('partner-changes.transition');

            Route::post(
                '/changes/partner-changes/{case}/eligibility',
                [PartnerChangesWorkspaceController::class, 'recordEligibility'],
            )->name('partner-changes.eligibility.store');

            Route::post(
                '/changes/partner-changes/{case}/requirements',
                [PartnerChangesWorkspaceController::class, 'recordRequirement'],
            )->name('partner-changes.requirements.store');

            Route::post(
                '/changes/partner-changes/{case}/rofr',
                [PartnerChangesWorkspaceController::class, 'openRofr'],
            )->name('partner-changes.rofr.store');

            Route::post(
                '/changes/partner-changes/{case}/rofr/{round}/responses',
                [PartnerChangesWorkspaceController::class, 'respondRofr'],
            )->name('partner-changes.rofr.responses.store');

            Route::post(
                '/changes/partner-changes/{case}/rofr/{round}/complete',
                [PartnerChangesWorkspaceController::class, 'completeRofr'],
            )->name('partner-changes.rofr.complete');

            Route::post(
                '/changes/partner-changes/{case}/governance',
                [PartnerChangesWorkspaceController::class, 'submitGovernance'],
            )->name('partner-changes.governance.store');

            Route::post(
                '/changes/partner-changes/{case}/records/{formalRecordVersion}/content-review',
                [PartnerChangesWorkspaceController::class, 'review'],
            )->name('partner-changes.content-review');

            Route::post(
                '/changes/partner-changes/{case}/sync-decision',
                [PartnerChangesWorkspaceController::class, 'syncDecision'],
            )->name('partner-changes.sync-decision');

            Route::post(
                '/changes/partner-changes/{case}/prepare-effect',
                [PartnerChangesWorkspaceController::class, 'prepareEffect'],
            )->name('partner-changes.prepare-effect');

            Route::post(
                '/changes/partner-changes/{case}/effect',
                [PartnerChangesWorkspaceController::class, 'effect'],
            )->name('partner-changes.effect');

            Route::get(
                '/changes/exit',
                [ExitWorkspaceController::class, 'index'],
            )->name('exit.index');

            Route::post(
                '/changes/exit',
                [ExitWorkspaceController::class, 'createCase'],
            )->name('exit.store');

            Route::post(
                '/changes/exit/{case}/notice',
                [ExitWorkspaceController::class, 'recordNotice'],
            )->name('exit.notice.store');

            Route::post(
                '/changes/exit/{case}/share-treatment',
                [ExitWorkspaceController::class, 'recordShareTreatment'],
            )->name('exit.share-treatment.store');

            Route::post(
                '/changes/exit/{case}/payment-terms',
                [ExitWorkspaceController::class, 'recordPaymentTerms'],
            )->name('exit.payment-terms.store');

            Route::post(
                '/changes/exit/{case}/transition',
                [ExitWorkspaceController::class, 'transitionCase'],
            )->name('exit.transition');

            Route::post(
                '/changes/exit/{case}/requirements',
                [ExitWorkspaceController::class, 'recordRequirement'],
            )->name('exit.requirements.store');

            Route::post(
                '/changes/exit/{case}/finance-links',
                [ExitWorkspaceController::class, 'linkFinancePayment'],
            )->name('exit.finance-links.store');

            Route::post(
                '/changes/exit/{case}/governance',
                [ExitWorkspaceController::class, 'submitGovernance'],
            )->name('exit.governance.store');

            Route::post(
                '/changes/exit/{case}/records/{formalRecordVersion}/content-review',
                [ExitWorkspaceController::class, 'review'],
            )->name('exit.content-review');

            Route::post(
                '/changes/exit/{case}/sync-decision',
                [ExitWorkspaceController::class, 'syncDecision'],
            )->name('exit.sync-decision');

            Route::post(
                '/changes/exit/{case}/prepare-effect',
                [ExitWorkspaceController::class, 'prepareEffect'],
            )->name('exit.prepare-effect');

            Route::post(
                '/changes/exit/{case}/effect',
                [ExitWorkspaceController::class, 'effect'],
            )->name('exit.effect');

            Route::post(
                '/changes/exit/{case}/access',
                [ExitWorkspaceController::class, 'transitionAccess'],
            )->name('exit.access.transition');

            Route::post(
                '/changes/exit/{case}/settlement/refresh',
                [ExitWorkspaceController::class, 'refreshSettlement'],
            )->name('exit.settlement.refresh');

            Route::post(
                '/changes/exit/{case}/complete',
                [ExitWorkspaceController::class, 'complete'],
            )->name('exit.complete');

            Route::get(
                '/changes/closure',
                [ClosureWorkspaceController::class, 'index'],
            )->name('closure.index');

            Route::post(
                '/changes/closure',
                [ClosureWorkspaceController::class, 'createCase'],
            )->name('closure.store');

            Route::post(
                '/changes/closure/{case}/requirements',
                [ClosureWorkspaceController::class, 'recordRequirement'],
            )->name('closure.requirements.store');

            Route::post(
                '/changes/closure/{case}/claims',
                [ClosureWorkspaceController::class, 'createClaim'],
            )->name('closure.claims.store');

            Route::post(
                '/changes/closure/{case}/claims/{claim}/transition',
                [ClosureWorkspaceController::class, 'transitionClaim'],
            )->name('closure.claims.transition');

            Route::post(
                '/changes/closure/{case}/finance-links',
                [ClosureWorkspaceController::class, 'linkFinancePayment'],
            )->name('closure.finance-links.store');

            Route::post(
                '/changes/closure/{case}/governance',
                [ClosureWorkspaceController::class, 'submitGovernance'],
            )->name('closure.governance.store');

            Route::post(
                '/changes/closure/{case}/records/{formalRecordVersion}/content-review',
                [ClosureWorkspaceController::class, 'review'],
            )->name('closure.content-review');

            Route::post(
                '/changes/closure/{case}/sync-decision',
                [ClosureWorkspaceController::class, 'syncDecision'],
            )->name('closure.sync-decision');

            Route::post(
                '/changes/closure/{case}/activate-wind-down',
                [ClosureWorkspaceController::class, 'activateWindDown'],
            )->name('closure.activate-wind-down');

            Route::post(
                '/changes/closure/{case}/prepare-residual',
                [ClosureWorkspaceController::class, 'prepareResidual'],
            )->name('closure.prepare-residual');

            Route::post(
                '/changes/closure/{case}/residual',
                [ClosureWorkspaceController::class, 'recordResidual'],
            )->name('closure.residual.store');

            Route::post(
                '/changes/closure/{case}/prepare-legal-closure',
                [ClosureWorkspaceController::class, 'prepareLegalClosure'],
            )->name('closure.prepare-legal-closure');

            Route::post(
                '/changes/closure/{case}/effect-legal-closure',
                [ClosureWorkspaceController::class, 'effectLegalClosure'],
            )->name('closure.effect-legal-closure');

            Route::post(
                '/changes/closure/{case}/close-workspace',
                [ClosureWorkspaceController::class, 'closeWorkspace'],
            )->name('closure.close-workspace');

            Route::post(
                '/changes/closure/{case}/transition',
                [ClosureWorkspaceController::class, 'transitionCase'],
            )->name('closure.transition');

            Route::get(
                '/governance',
                [GovernanceWorkspaceController::class, 'index'],
            )->name('governance.index');

            Route::get(
                '/governance/rules',
                [GovernanceRulesController::class, 'index'],
            )->name('governance.rules.index');

            Route::post(
                '/governance/rules/formation-authority',
                [GovernanceRulesController::class, 'createFormationAuthorityPolicy'],
            )->name('governance.rules.formation-authority.store');

            Route::post(
                '/governance/rules/formation-authority/{formalRecordVersion}/freeze',
                [GovernanceRulesController::class, 'freezeFormationAuthorityPolicy'],
            )->name('governance.rules.formation-authority.freeze');

            Route::post(
                '/governance/rules/formation-authority/{formalRecordVersion}/establish',
                [GovernanceRulesController::class, 'establishFormationAuthority'],
            )->name('governance.rules.formation-authority.establish');

            Route::post(
                '/governance/rules/charter',
                [GovernanceRulesController::class, 'createDraft'],
            )->name('governance.rules.charter.store');

            Route::post(
                '/governance/rules/charter/{formalRecordVersion}/submit',
                [GovernanceRulesController::class, 'submit'],
            )->name('governance.rules.charter.submit');

            Route::post(
                '/governance/rules/charter/{formalRecordVersion}/content-review',
                [GovernanceRulesController::class, 'contentReview'],
            )->name('governance.rules.charter.content-review');

            Route::post(
                '/governance/rules/delegations',
                [GovernanceRulesController::class, 'proposeDelegation'],
            )->name('governance.rules.delegations.store');

            Route::post(
                '/governance/rules/emergency-authority',
                [GovernanceRulesController::class, 'proposeEmergency'],
            )->name('governance.rules.emergency-authority.store');

            Route::post(
                '/governance/rules/authority-revocations',
                [GovernanceRulesController::class, 'proposeRevocation'],
            )->name('governance.rules.authority-revocations.store');

            Route::post(
                '/governance/rules/authority-changes/{submission}/authorize',
                [GovernanceRulesController::class, 'authorizeChange'],
            )->name('governance.rules.authority-changes.authorize');

            Route::get(
                '/governance/meetings',
                [GovernanceMeetingController::class, 'index'],
            )->name('governance.meetings.index');

            Route::post(
                '/governance/meetings',
                [GovernanceMeetingController::class, 'store'],
            )->name('governance.meetings.store');

            Route::post(
                '/governance/meetings/{meeting}/hold',
                [GovernanceMeetingController::class, 'hold'],
            )->name('governance.meetings.hold');

            Route::post(
                '/governance/meetings/{meeting}/cancel',
                [GovernanceMeetingController::class, 'cancel'],
            )->name('governance.meetings.cancel');

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
                '/operations',
                [OperationsWorkspaceController::class, 'index'],
            )->name('operations.index');

            Route::post(
                '/operations/register',
                [OperationsWorkspaceController::class, 'createDraft'],
            )->name('operations.register.store');

            Route::post(
                '/operations/register/{formalRecordVersion}/submit',
                [OperationsWorkspaceController::class, 'submit'],
            )->name('operations.register.submit');

            Route::post(
                '/operations/register/{formalRecordVersion}/content-review',
                [OperationsWorkspaceController::class, 'contentReview'],
            )->name('operations.register.content-review');

            Route::post(
                '/operations/actions',
                [OperationsWorkspaceController::class, 'createAction'],
            )->name('operations.actions.store');

            Route::post(
                '/operations/actions/{action}/status',
                [OperationsWorkspaceController::class, 'updateAction'],
            )->name('operations.actions.status.update');

            Route::get(
                '/finance',
                [FinanceWorkspaceController::class, 'index'],
            )->name('finance.index');

            Route::post(
                '/finance/policy',
                [FinanceWorkspaceController::class, 'createPolicy'],
            )->name('finance.policy.store');

            Route::post(
                '/finance/policy/{formalRecordVersion}/submit',
                [FinanceWorkspaceController::class, 'submitPolicy'],
            )->name('finance.policy.submit');

            Route::post(
                '/finance/policy/{formalRecordVersion}/content-review',
                [FinanceWorkspaceController::class, 'reviewPolicy'],
            )->name('finance.policy.content-review');

            Route::post(
                '/finance/reconciliations',
                [FinanceWorkspaceController::class, 'createReconciliation'],
            )->name('finance.reconciliations.store');

            Route::post(
                '/finance/reconciliations/{reconciliation}/complete',
                [FinanceWorkspaceController::class, 'completeReconciliation'],
            )->name('finance.reconciliations.complete');

            Route::post(
                '/finance/payments',
                [FinanceWorkspaceController::class, 'createPayment'],
            )->name('finance.payments.store');

            Route::post(
                '/finance/payments/{payment}/evidence',
                [FinanceWorkspaceController::class, 'attachPaymentEvidence'],
            )->name('finance.payments.evidence.store');

            Route::post(
                '/finance/payments/{payment}/verify',
                [FinanceWorkspaceController::class, 'verifyPayment'],
            )->name('finance.payments.verify');

            Route::post(
                '/finance/payments/{payment}/sync-decision',
                [FinanceWorkspaceController::class, 'syncPaymentDecision'],
            )->name('finance.payments.sync-decision');

            Route::post(
                '/finance/payments/{payment}/pay',
                [FinanceWorkspaceController::class, 'recordPayment'],
            )->name('finance.payments.pay');

            Route::post(
                '/finance/payments/{payment}/complete',
                [FinanceWorkspaceController::class, 'completePayment'],
            )->name('finance.payments.complete');

            Route::post(
                '/finance/exceptions',
                [FinanceWorkspaceController::class, 'openException'],
            )->name('finance.exceptions.store');

            Route::post(
                '/finance/exceptions/{exception}/review',
                [FinanceWorkspaceController::class, 'reviewException'],
            )->name('finance.exceptions.review');

            Route::get(
                '/rewards',
                [RewardsWorkspaceController::class, 'index'],
            )->name('rewards.index');

            Route::post(
                '/rewards/policy',
                [RewardsWorkspaceController::class, 'createPolicy'],
            )->name('rewards.policy.store');

            Route::post(
                '/rewards/policy/{formalRecordVersion}/submit',
                [RewardsWorkspaceController::class, 'submitPolicy'],
            )->name('rewards.policy.submit');

            Route::post(
                '/rewards/policy/{formalRecordVersion}/content-review',
                [RewardsWorkspaceController::class, 'reviewPolicy'],
            )->name('rewards.policy.content-review');

            Route::post(
                '/rewards/payments',
                [RewardsWorkspaceController::class, 'createRewardPayment'],
            )->name('rewards.payments.store');

            Route::post(
                '/rewards/distribution/simulate',
                [RewardsWorkspaceController::class, 'simulateDistribution'],
            )->name('rewards.distribution.simulate');

            Route::post(
                '/rewards/distributions',
                [RewardsWorkspaceController::class, 'createDistribution'],
            )->name('rewards.distributions.store');

            Route::post(
                '/rewards/distributions/{distribution}/evidence',
                [RewardsWorkspaceController::class, 'attachDistributionEvidence'],
            )->name('rewards.distributions.evidence.store');

            Route::post(
                '/rewards/distributions/{distribution}/lines/{line}/adjust',
                [RewardsWorkspaceController::class, 'adjustDistributionLine'],
            )->name('rewards.distributions.lines.adjust');

            Route::post(
                '/rewards/distributions/{distribution}/verify',
                [RewardsWorkspaceController::class, 'verifyDistribution'],
            )->name('rewards.distributions.verify');

            Route::post(
                '/rewards/distributions/{distribution}/sync-decision',
                [RewardsWorkspaceController::class, 'syncDistributionDecision'],
            )->name('rewards.distributions.sync-decision');

            Route::post(
                '/rewards/distributions/{distribution}/schedule-payments',
                [RewardsWorkspaceController::class, 'scheduleDistributionPayments'],
            )->name('rewards.distributions.schedule-payments');

            Route::post(
                '/rewards/distributions/{distribution}/complete',
                [RewardsWorkspaceController::class, 'completeDistribution'],
            )->name('rewards.distributions.complete');

            Route::get('/risk', [RiskWorkspaceController::class, 'index'])
                ->name('risk.index');
            Route::post('/risk/register', [RiskWorkspaceController::class, 'createRegister'])
                ->name('risk.register.store');
            Route::post('/risk/register/{formalRecordVersion}/submit', [RiskWorkspaceController::class, 'submitRegister'])
                ->name('risk.register.submit');
            Route::post('/risk/register/{formalRecordVersion}/content-review', [RiskWorkspaceController::class, 'reviewRegister'])
                ->name('risk.register.content-review');
            Route::post('/risk/register/{formalRecordVersion}/sync-decision', [RiskWorkspaceController::class, 'syncRegisterDecision'])
                ->name('risk.register.sync-decision');
            Route::post('/risk/incidents', [RiskWorkspaceController::class, 'openIncident'])
                ->name('risk.incidents.store');
            Route::post('/risk/incidents/{incident}/transition', [RiskWorkspaceController::class, 'transitionIncident'])
                ->name('risk.incidents.transition');
            Route::post('/risk/control-tests', [RiskWorkspaceController::class, 'createControlTest'])
                ->name('risk.control-tests.store');
            Route::post('/risk/control-tests/{test}/result', [RiskWorkspaceController::class, 'recordControlTest'])
                ->name('risk.control-tests.result');
            Route::post('/risk/actions', [RiskWorkspaceController::class, 'createAction'])
                ->name('risk.actions.store');

            Route::get('/continuity', [ContinuityWorkspaceController::class, 'index'])
                ->name('continuity.index');
            Route::post('/continuity/plan', [ContinuityWorkspaceController::class, 'createPlan'])
                ->name('continuity.plan.store');
            Route::post('/continuity/plan/{formalRecordVersion}/submit', [ContinuityWorkspaceController::class, 'submitPlan'])
                ->name('continuity.plan.submit');
            Route::post('/continuity/plan/{formalRecordVersion}/content-review', [ContinuityWorkspaceController::class, 'reviewPlan'])
                ->name('continuity.plan.content-review');
            Route::post('/continuity/plan/{formalRecordVersion}/sync-decision', [ContinuityWorkspaceController::class, 'syncPlanDecision'])
                ->name('continuity.plan.sync-decision');
            Route::post('/continuity/tests', [ContinuityWorkspaceController::class, 'createTest'])
                ->name('continuity.tests.store');
            Route::post('/continuity/tests/{test}/result', [ContinuityWorkspaceController::class, 'recordTest'])
                ->name('continuity.tests.result');
            Route::post('/continuity/emergency-access', [ContinuityWorkspaceController::class, 'requestEmergencyAccess'])
                ->name('continuity.emergency-access.store');
            Route::post('/continuity/emergency-access/{activation}/activate', [ContinuityWorkspaceController::class, 'activateEmergencyAccess'])
                ->name('continuity.emergency-access.activate');
            Route::post('/continuity/emergency-access/{activation}/end', [ContinuityWorkspaceController::class, 'endEmergencyAccess'])
                ->name('continuity.emergency-access.end');
            Route::post('/continuity/actions', [ContinuityWorkspaceController::class, 'createAction'])
                ->name('continuity.actions.store');

            Route::get('/conflict', [ConflictWorkspaceController::class, 'index'])
                ->name('conflict.index');
            Route::post('/conflict/policy', [ConflictWorkspaceController::class, 'createPolicy'])
                ->name('conflict.policy.store');
            Route::post('/conflict/policy/{formalRecordVersion}/submit', [ConflictWorkspaceController::class, 'submitPolicy'])
                ->name('conflict.policy.submit');
            Route::post('/conflict/policy/{formalRecordVersion}/content-review', [ConflictWorkspaceController::class, 'reviewPolicy'])
                ->name('conflict.policy.content-review');
            Route::post('/conflict/policy/{formalRecordVersion}/sync-decision', [ConflictWorkspaceController::class, 'syncPolicyDecision'])
                ->name('conflict.policy.sync-decision');
            Route::post('/conflict/cases', [ConflictWorkspaceController::class, 'openCase'])
                ->name('conflict.cases.store');
            Route::post('/conflict/cases/{case}/transition', [ConflictWorkspaceController::class, 'transitionCase'])
                ->name('conflict.cases.transition');
            Route::post('/conflict/cases/{case}/direct-discussions', [ConflictWorkspaceController::class, 'recordDirectDiscussion'])
                ->name('conflict.discussions.store');
            Route::post('/conflict/cases/{case}/mediations', [ConflictWorkspaceController::class, 'scheduleMediation'])
                ->name('conflict.mediations.store');
            Route::post('/conflict/cases/{case}/mediations/{mediation}/responses', [ConflictWorkspaceController::class, 'recordMediationResponse'])
                ->name('conflict.mediations.responses.store');
            Route::post('/conflict/cases/{case}/mediations/{mediation}/complete', [ConflictWorkspaceController::class, 'completeMediation'])
                ->name('conflict.mediations.complete');
            Route::post('/conflict/cases/{case}/decisions', [ConflictWorkspaceController::class, 'submitDecision'])
                ->name('conflict.decisions.store');
            Route::post('/conflict/cases/{case}/decisions/{submission}/open', [ConflictWorkspaceController::class, 'openDecision'])
                ->name('conflict.decisions.open');
            Route::post('/conflict/cases/{case}/decisions/{submission}/apply', [ConflictWorkspaceController::class, 'applyDecision'])
                ->name('conflict.decisions.apply');
            Route::post('/conflict/cases/{case}/escalations', [ConflictWorkspaceController::class, 'enterEscalation'])
                ->name('conflict.escalations.store');
            Route::post('/conflict/cases/{case}/deadlock', [ConflictWorkspaceController::class, 'enterDeadlock'])
                ->name('conflict.deadlock.store');
            Route::post('/conflict/cases/{case}/deadlock/{deadlock}/decision', [ConflictWorkspaceController::class, 'bindDeadlockDecision'])
                ->name('conflict.deadlock.decision');
            Route::post('/conflict/cases/{case}/deadlock/{deadlock}/resolve', [ConflictWorkspaceController::class, 'resolveDeadlock'])
                ->name('conflict.deadlock.resolve');
            Route::post('/conflict/cases/{case}/investigations', [ConflictWorkspaceController::class, 'openInvestigation'])
                ->name('conflict.investigations.store');
            Route::post('/conflict/cases/{case}/investigations/{investigation}/complete', [ConflictWorkspaceController::class, 'completeInvestigation'])
                ->name('conflict.investigations.complete');
            Route::post('/conflict/cases/{case}/urgent-risk', [ConflictWorkspaceController::class, 'openUrgentRisk'])
                ->name('conflict.urgent-risk.store');
            Route::post('/conflict/cases/{case}/urgent-risk/{urgentRisk}/decision', [ConflictWorkspaceController::class, 'bindUrgentDecision'])
                ->name('conflict.urgent-risk.decision');
            Route::post('/conflict/cases/{case}/settlements', [ConflictWorkspaceController::class, 'createSettlement'])
                ->name('conflict.settlements.store');
            Route::post('/conflict/cases/{case}/settlements/{formalRecordVersion}/submit', [ConflictWorkspaceController::class, 'submitSettlement'])
                ->name('conflict.settlements.submit');
            Route::post('/conflict/cases/{case}/settlements/{formalRecordVersion}/content-review', [ConflictWorkspaceController::class, 'reviewSettlement'])
                ->name('conflict.settlements.content-review');
            Route::post('/conflict/cases/{case}/settlements/{formalRecordVersion}/document', [ConflictWorkspaceController::class, 'bindSettlementDocument'])
                ->name('conflict.settlements.document');
            Route::post('/conflict/cases/{case}/settlements/{formalRecordVersion}/signature', [ConflictWorkspaceController::class, 'requestSettlementSignature'])
                ->name('conflict.settlements.signature');
            Route::post('/conflict/cases/{case}/settlements/{formalRecordVersion}/effect', [ConflictWorkspaceController::class, 'effectSettlement'])
                ->name('conflict.settlements.effect');
            Route::post('/conflict/cases/{case}/referrals', [ConflictWorkspaceController::class, 'refer'])
                ->name('conflict.referrals.store');
            Route::post('/conflict/cases/{case}/reviews', [ConflictWorkspaceController::class, 'createReview'])
                ->name('conflict.reviews.store');
            Route::post('/conflict/cases/{case}/reviews/{review}/complete', [ConflictWorkspaceController::class, 'completeReview'])
                ->name('conflict.reviews.complete');
            Route::post('/conflict/cases/{case}/actions', [ConflictWorkspaceController::class, 'createAction'])
                ->name('conflict.actions.store');

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
    Route::patch('/account/language', [AccountSettingsController::class, 'updateLanguage'])
        ->name('account.language.update');
});
Route::post('/logout', [AuthenticatedSessionController::class, 'destroy'])
    ->middleware('auth')
    ->name('logout');
