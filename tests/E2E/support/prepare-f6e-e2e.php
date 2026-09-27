<?php

declare(strict_types=1);

use App\Application\Businesses\CreateBusiness;
use App\Application\Conflict\ConflictCaseWorkflow;
use App\Application\Conflict\ConflictDirectDiscussionWorkflow;
use App\Application\Conflict\ConflictMediationWorkflow;
use App\Application\Conflict\ConflictSettlementWorkflow;
use App\Application\Conflict\CreateConflictAction;
use App\Application\Identity\ChangeAccountStatus;
use App\Application\Identity\ProvisionAccount;
use App\Domain\Access\Enums\StandardAccessProfile;
use App\Domain\Businesses\Enums\BusinessOriginType;
use App\Domain\Businesses\Enums\BusinessStage;
use App\Domain\Conflict\Enums\ConflictCaseStage;
use App\Domain\Conflict\Enums\DirectDiscussionOutcome;
use App\Domain\Conflict\Enums\MediationResponseOutcome;
use App\Domain\Identity\Enums\AccountStatus;
use App\Domain\Identity\Enums\LanguageMode;
use App\Infrastructure\Persistence\Eloquent\Conflict\ConflictCase;
use App\Infrastructure\Persistence\Eloquent\Identity\User;
use App\Infrastructure\Persistence\Eloquent\Members\Membership;
use App\Infrastructure\Persistence\Eloquent\Records\FormalRecordFamily;
use App\Infrastructure\Persistence\Eloquent\Records\FormalRecordVersion;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Foundation\Application;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

$basePath = dirname(__DIR__, 3);

require $basePath.'/vendor/autoload.php';

/** @var Application $app */
$app = require $basePath.'/bootstrap/app.php';

$app->make(Kernel::class)->bootstrap();

if (! $app->environment('testing')) {
    throw new RuntimeException(
        'F6E fixture may run only with APP_ENV=testing.',
    );
}

$config = $app->make('config');

$target = implode('|', [
    (string) $config->get('database.default'),
    (string) $config->get('database.connections.pgsql.host'),
    (string) $config->get('database.connections.pgsql.port'),
    (string) $config->get('database.connections.pgsql.database'),
    (string) $config->get('database.connections.pgsql.username'),
]);

$allowedTargets = [
    'pgsql|127.0.0.1|5434|thepbr_os_v3_test|thepbr_os_v3_test_app',
    'pgsql|127.0.0.1|5432|pbr_ci|pbr_ci',
    'pgsql|/tmp/pbr-f6e-focused-pg/socket|55438|pbr_f6e_test|postgres',
];

if (! in_array($target, $allowedTargets, true)) {
    throw new RuntimeException(
        'F6E fixture refused non-Test/CI PostgreSQL target: '.$target,
    );
}

foreach ([
    'users',
    'businesses',
    'memberships',
    'governance_charter_versions',
    'operations_register_versions',
    'conflict_policy_versions',
    'conflict_cases',
    'conflict_settlement_versions',
] as $table) {
    $count = DB::table($table)->count();

    if ($count !== 0) {
        throw new RuntimeException(sprintf(
            'F6E fixture requires fresh Test/CI DB; %s has %d rows.',
            $table,
            $count,
        ));
    }
}

$password = getenv('F6E_E2E_PASSWORD');

if (! is_string($password) || $password === '') {
    throw new RuntimeException(
        'F6E_E2E_PASSWORD must be provided through environment.',
    );
}

$ownerEmail = 'f6e-owner@example.com';
$viewerEmail = 'f6e-viewer@example.com';
$businessName = 'F6E Conflict Business';

foreach ([
    [$ownerEmail, 'F6E Conflict Owner'],
    [$viewerEmail, 'F6E Restricted Viewer'],
] as [$email, $displayName]) {
    $app->make(ProvisionAccount::class)->handle(
        email: $email,
        displayName: $displayName,
        password: $password,
        languageMode: LanguageMode::English,
        timezone: 'UTC',
        actorLabel: 'F6E E2E Fixture',
        reason: 'Deterministic F6E browser account',
        source: 'e2e-fixture',
    );

    $app->make(ChangeAccountStatus::class)->handle(
        email: $email,
        targetStatus: AccountStatus::Active,
        actorLabel: 'F6E E2E Fixture',
        reason: 'Activate deterministic F6E account',
        source: 'e2e-fixture',
    );
}

$ownerUser = User::query()->where('email', $ownerEmail)->sole();
$viewerUser = User::query()->where('email', $viewerEmail)->sole();

$business = $app->make(CreateBusiness::class)->handle(
    $ownerUser,
    $businessName,
    BusinessOriginType::StartedThroughPbr,
    BusinessStage::Operating,
    'USD',
);

$ownerMembership = Membership::query()
    ->where('business_id', $business->getKey())
    ->where('user_id', $ownerUser->getKey())
    ->sole();

$viewerMembership = Membership::query()->create([
    'user_id' => $viewerUser->getKey(),
    'business_id' => $business->getKey(),
    'access_status' => 'active',
]);

$partnerProfileId = DB::table('permission_profiles')
    ->where('business_id', $business->getKey())
    ->where('name', StandardAccessProfile::Partner->value)
    ->value('id');

if ($partnerProfileId === null) {
    throw new RuntimeException('F6E Partner access profile was not provisioned.');
}

DB::table('membership_permission_profiles')->insert([
    'business_id' => $business->getKey(),
    'membership_id' => $viewerMembership->getKey(),
    'permission_profile_id' => $partnerProfileId,
    'created_at' => now(),
    'updated_at' => now(),
]);

$recordVersion = static function (
    string $recordType,
    string $hash,
) use ($business, $ownerUser): array {
    $family = FormalRecordFamily::query()->create([
        'business_id' => $business->getKey(),
        'record_type' => $recordType,
        'subject_type' => 'business',
        'subject_id' => (string) $business->getKey(),
    ]);

    $version = FormalRecordVersion::query()->create([
        'business_id' => $business->getKey(),
        'formal_record_family_id' => $family->getKey(),
        'version_number' => 1,
        'predecessor_version_id' => null,
        'revision' => 1,
        'change_summary' => 'F6E deterministic E2E fixture',
        'created_by_user_id' => $ownerUser->getKey(),
        'last_changed_by_user_id' => $ownerUser->getKey(),
        'effective_from' => now()->subMinute(),
        'effective_until' => null,
        'review_due_at' => now()->addMonths(3),
        'content_hash' => $hash,
        'frozen_at' => null,
    ]);

    return [$family, $version];
};

$makeEffective = static function (
    FormalRecordFamily $family,
    FormalRecordVersion $version,
) use ($business, $ownerUser): void {
    foreach ([
        [null, 'draft'],
        ['draft', 'ready_for_review'],
        ['ready_for_review', 'under_review'],
        ['under_review', 'approved'],
        ['approved', 'ready_for_effect'],
        ['ready_for_effect', 'effective'],
    ] as $index => [$from, $to]) {
        DB::table('record_version_state_transitions')->insert([
            'id' => (string) Str::uuid7(),
            'business_id' => $business->getKey(),
            'formal_record_version_id' => $version->getKey(),
            'sequence' => $index + 1,
            'from_state' => $from,
            'to_state' => $to,
            'transitioned_by_user_id' => $ownerUser->getKey(),
            'occurred_at' => now(),
            'created_at' => now(),
        ]);
    }

    DB::table('record_family_effective_heads')->insert([
        'id' => (string) Str::uuid7(),
        'business_id' => $business->getKey(),
        'formal_record_family_id' => $family->getKey(),
        'formal_record_version_id' => $version->getKey(),
        'activated_at' => now(),
        'created_at' => now(),
        'updated_at' => now(),
    ]);
};

[$governanceFamily, $governanceVersion] = $recordVersion(
    'governance_charter',
    str_repeat('a', 64),
);

DB::table('governance_charter_versions')->insert([
    'id' => (string) Str::uuid7(),
    'business_id' => $business->getKey(),
    'formal_record_version_id' => $governanceVersion->getKey(),
    'governance_owner_membership_id' => $ownerMembership->getKey(),
    'voting_basis' => 'one_member_one_vote',
    'default_approval_rule' => 'One authorized approval',
    'meeting_frequency' => 'As required',
    'default_quorum_count' => 1,
    'minutes_owner_membership_id' => $ownerMembership->getKey(),
    'conflict_of_interest_rule' => 'Affected actor must recuse.',
    'deadlock_rule' => 'Use the Current Effective Conflict Procedure.',
    'remote_voting_allowed' => true,
    'written_resolution_allowed' => true,
    'created_at' => now(),
]);

foreach ([
    'conflict_formal_decision',
    'conflict_deadlock_decision',
    'conflict_misconduct_decision',
    'conflict_urgent_risk_decision',
    'conflict_settlement_approval',
] as $index => $decisionType) {
    $ruleId = (string) Str::uuid7();

    DB::table('governance_charter_rules')->insert([
        'id' => $ruleId,
        'business_id' => $business->getKey(),
        'formal_record_version_id' => $governanceVersion->getKey(),
        'sequence' => $index + 1,
        'decision_type' => $decisionType,
        'category' => 'major_business',
        'decision_method' => 'approval',
        'required_approvals' => 1,
        'required_votes' => 0,
        'quorum_count' => 1,
        'signature_required' => $decisionType === 'conflict_settlement_approval',
        'reserved_matter' => false,
        'meeting_required' => false,
        'record_required' => true,
        'amount_min' => null,
        'amount_max' => null,
        'created_at' => now(),
    ]);

    DB::table('governance_charter_rule_actors')->insert([
        'id' => (string) Str::uuid7(),
        'business_id' => $business->getKey(),
        'governance_charter_rule_id' => $ruleId,
        'membership_id' => $ownerMembership->getKey(),
        'capacity' => 'Conflict Governance Approver',
        'is_decision_owner' => true,
        'is_consulted' => false,
        'can_approve' => true,
        'can_vote' => false,
        'can_sign' => true,
        'created_at' => now(),
    ]);
}

$governanceVersion->frozen_at = now();
$governanceVersion->save();
$makeEffective($governanceFamily, $governanceVersion);

[$operationsFamily, $operationsVersion] = $recordVersion(
    'operations_register',
    str_repeat('b', 64),
);

DB::table('operations_register_versions')->insert([
    'id' => (string) Str::uuid7(),
    'business_id' => $business->getKey(),
    'formal_record_version_id' => $operationsVersion->getKey(),
    'organization_name' => 'F6E Conflict Operations',
    'notes' => null,
    'created_at' => now(),
]);

$roleId = (string) Str::uuid7();

DB::table('operations_roles')->insert([
    'id' => $roleId,
    'business_id' => $business->getKey(),
    'formal_record_version_id' => $operationsVersion->getKey(),
    'role_key' => 'conflict_owner',
    'name' => 'Conflict Operations Owner',
    'function_name' => 'Partner Operations',
    'purpose' => 'Deliver approved Conflict follow-up work.',
    'responsibilities' => 'Coordinate resolution follow-up.',
    'operational_authority' => null,
    'reports_to_role_key' => null,
    'report_type' => null,
    'reporting_frequency' => 'Weekly',
    'meeting_frequency' => null,
    'review_frequency' => 'Quarterly',
    'status' => 'active',
    'created_at' => now(),
]);

DB::table('operations_role_assignments')->insert([
    'id' => (string) Str::uuid7(),
    'business_id' => $business->getKey(),
    'formal_record_version_id' => $operationsVersion->getKey(),
    'operations_role_id' => $roleId,
    'membership_id' => $ownerMembership->getKey(),
    'assignment_type' => 'primary',
    'created_at' => now(),
]);

$operationsVersion->frozen_at = now();
$operationsVersion->save();
$makeEffective($operationsFamily, $operationsVersion);

[$policyFamily, $policyVersion] = $recordVersion(
    'conflict_resolution_policy',
    str_repeat('c', 64),
);

DB::table('conflict_policy_versions')->insert([
    'id' => (string) Str::uuid7(),
    'business_id' => $business->getKey(),
    'formal_record_version_id' => $policyVersion->getKey(),
    'governance_formal_record_version_id' => $governanceVersion->getKey(),
    'operations_formal_record_version_id' => $operationsVersion->getKey(),
    'conflict_owner_membership_id' => $ownerMembership->getKey(),
    'formal_decision_type' => 'conflict_formal_decision',
    'deadlock_decision_type' => 'conflict_deadlock_decision',
    'misconduct_decision_type' => 'conflict_misconduct_decision',
    'urgent_risk_decision_type' => 'conflict_urgent_risk_decision',
    'settlement_decision_type' => 'conflict_settlement_approval',
    'review_frequency' => 'Quarterly',
    'notes' => 'Deterministic F6E E2E Conflict Procedure.',
    'created_at' => now(),
]);

DB::table('conflict_escalation_rules')->insert([
    'id' => (string) Str::uuid7(),
    'business_id' => $business->getKey(),
    'formal_record_version_id' => $policyVersion->getKey(),
    'sequence' => 1,
    'step_key' => 'formal_review',
    'entry_condition' => 'Direct resolution failed.',
    'operations_role_id' => $roleId,
    'max_days' => 7,
    'required_evidence' => 'Authorized case evidence.',
    'decision_type' => 'conflict_formal_decision',
    'resolution_exit_condition' => 'Resolved or escalated.',
    'next_step_key' => null,
    'status' => 'active',
    'created_at' => now(),
]);

foreach ([
    ['deadlock', 'conflict_deadlock_decision'],
    ['misconduct', 'conflict_misconduct_decision'],
    ['urgent_risk', 'conflict_urgent_risk_decision'],
] as [$pathType, $decisionType]) {
    DB::table('conflict_special_path_rules')->insert([
        'id' => (string) Str::uuid7(),
        'business_id' => $business->getKey(),
        'formal_record_version_id' => $policyVersion->getKey(),
        'path_type' => $pathType,
        'entry_condition' => 'Case classified as '.$pathType.'.',
        'procedure_summary' => 'Use the governed '.$pathType.' procedure.',
        'operations_role_id' => $roleId,
        'review_deadline_days' => $pathType === 'urgent_risk' ? 1 : 3,
        'decision_type' => $decisionType,
        'external_handoff_rule' => 'Refer when unresolved.',
        'created_at' => now(),
    ]);
}

$policyVersion->frozen_at = now();
$policyVersion->save();
$makeEffective($policyFamily, $policyVersion);

$caseWorkflow = $app->make(ConflictCaseWorkflow::class);

$opened = $caseWorkflow->open(
    $ownerUser,
    $business,
    [
        'conflict_type' => 'relationship_breakdown',
        'description' => 'Prepared restricted conflict for deterministic browser verification.',
        'business_impact' => 'Partner coordination requires a governed resolution path.',
        'urgency' => 'normal',
        'related_rule_reference' => 'Fixture agreement clause 12',
        'review_due_at' => now()->addWeek(),
        'participants' => [[
            'membership_id' => (string) $ownerMembership->getKey(),
            'external_reference' => null,
            'participant_role' => 'party',
        ]],
        'view_membership_ids' => [],
        'manage_membership_ids' => [],
    ],
);

if ($opened === null) {
    throw new RuntimeException('F6E fixture could not open prepared Conflict Case.');
}

$case = $caseWorkflow->transition(
    $ownerUser,
    $business,
    $opened['id'],
    1,
    ConflictCaseStage::DirectDiscussion,
    'fixture_direct_discussion',
);

if ($case === null) {
    throw new RuntimeException('F6E fixture could not enter Direct Discussion.');
}

$discussionId = $app->make(ConflictDirectDiscussionWorkflow::class)->record(
    $ownerUser,
    $business,
    $opened['id'],
    (int) $case->revision,
    'Prepared parties discussed the restricted conflict.',
    'The authorized party recorded its position.',
    'Proceed to neutral mediation.',
    DirectDiscussionOutcome::ContinueMediation,
    [(string) $ownerMembership->getKey()],
);

if ($discussionId === null) {
    throw new RuntimeException('F6E fixture could not record Direct Discussion.');
}

$case = ConflictCase::query()->findOrFail($opened['id']);

$mediation = $app->make(ConflictMediationWorkflow::class);
$mediationId = $mediation->schedule(
    $ownerUser,
    $business,
    $opened['id'],
    (int) $case->revision,
    'external',
    'Prepared neutral mediator has no identified conflict.',
    now()->addHour(),
    null,
    'E2E Independent Mediator',
    now()->addDay(),
);

if ($mediationId === null) {
    throw new RuntimeException('F6E fixture could not schedule Mediation.');
}

if (! $mediation->recordResponse(
    $ownerUser,
    $business,
    $opened['id'],
    $mediationId,
    MediationResponseOutcome::Accepted,
    'Prepared party accepts the settlement basis.',
)) {
    throw new RuntimeException('F6E fixture could not record Mediation response.');
}

$case = ConflictCase::query()->findOrFail($opened['id']);

$completedMediation = $mediation->complete(
    $ownerUser,
    $business,
    $opened['id'],
    $mediationId,
    (int) $case->revision,
    1,
    'Prepared Mediation completed.',
    'Proceed to a versioned Settlement Agreement.',
);

if ($completedMediation === null) {
    throw new RuntimeException('F6E fixture could not complete Mediation.');
}

$case = ConflictCase::query()->findOrFail($opened['id']);

$settlement = $app->make(ConflictSettlementWorkflow::class)->createDraft(
    $ownerUser,
    $business,
    (string) $case->getKey(),
    [
        'source_mediation_id' => $mediationId,
        'source_decision_id' => null,
        'settlement_terms' => 'Prepared versioned settlement terms for browser verification.',
        'required_actions_summary' => 'Conflict Operations Owner completes the agreed follow-up.',
        'responsible_owner_membership_id' => (string) $ownerMembership->getKey(),
        'due_at' => now()->addWeek(),
        'financial_settlement_minor_units' => null,
        'currency' => null,
        'confidentiality_terms' => 'Restricted to explicitly authorized case and document access.',
        'future_conduct_terms' => 'Use the agreed partner communication protocol.',
        'review_date' => now()->addMonth()->toDateString(),
    ],
    now()->subMinute(),
);

if ($settlement === null) {
    throw new RuntimeException('F6E fixture could not create Settlement draft.');
}

$action = $app->make(CreateConflictAction::class)->execute(
    $ownerUser,
    $business,
    (string) $case->getKey(),
    $roleId,
    (string) $ownerMembership->getKey(),
    'Prepared Conflict follow-up',
    'conflict_case',
    (string) $case->getKey(),
    'Follow-up remains an Operations Action.',
    now()->addWeek(),
);

if ($action === null) {
    throw new RuntimeException('F6E fixture could not create follow-up Action.');
}

echo 'F6E_E2E_FIXTURE=PASS', PHP_EOL;
echo 'F6E_E2E_OWNER_EMAIL=', $ownerEmail, PHP_EOL;
echo 'F6E_E2E_VIEWER_EMAIL=', $viewerEmail, PHP_EOL;
echo 'F6E_E2E_BUSINESS=', $businessName, PHP_EOL;
echo 'F6E_E2E_PREPARED_CASE=', $opened['case_number'], PHP_EOL;
echo 'F6E_E2E_PASSWORD_OUTPUT=SUPPRESSED', PHP_EOL;
