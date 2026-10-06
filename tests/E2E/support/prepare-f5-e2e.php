<?php

declare(strict_types=1);

use App\Application\Businesses\CreateBusiness;
use App\Application\Governance\EstablishInitialFormationAuthority;
use App\Application\Governance\FormationAuthorityPolicyWorkflow;
use App\Application\Identity\ChangeAccountStatus;
use App\Application\Identity\ProvisionAccount;
use App\Application\Partnership\ContributionWorkflow;
use App\Application\Partnership\PartnerDirectory;
use App\Application\Partnership\PartnerDynamicsReference;
use App\Domain\Businesses\Enums\BusinessOriginType;
use App\Domain\Businesses\Enums\BusinessStage;
use App\Domain\Identity\Enums\AccountStatus;
use App\Domain\Identity\Enums\LanguageMode;
use App\Domain\Partnership\Enums\ContributionType;
use App\Domain\Partnership\ValueObjects\ContributionValue;
use App\Infrastructure\Persistence\Eloquent\Identity\User;
use App\Infrastructure\Persistence\Eloquent\Members\Membership;
use Carbon\CarbonImmutable;
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
        'F5 fixture may run only with APP_ENV=testing.',
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
];

if (! in_array($target, $allowedTargets, true)) {
    throw new RuntimeException(
        'F5 fixture refused non-Test/CI PostgreSQL target: '.$target,
    );
}

foreach (
    [
        'users',
        'businesses',
        'memberships',
        'partners',
        'partner_due_diligence_cases',
        'partner_dynamics_assessment_references',
        'contributions',
        'ownership_scenarios',
        'ownership_register_versions',
    ] as $table
) {
    $count = DB::table($table)->count();

    if ($count !== 0) {
        throw new RuntimeException(sprintf(
            'F5 fixture requires fresh Test/CI DB; %s has %d rows.',
            $table,
            $count,
        ));
    }
}

$email = 'f5-browser@example.com';
$password = getenv('F5_E2E_PASSWORD');

if (! is_string($password) || $password === '') {
    throw new RuntimeException(
        'F5_E2E_PASSWORD must be provided through environment.',
    );
}

$app->make(ProvisionAccount::class)->handle(
    email: $email,
    displayName: 'F5 Browser Tester',
    password: $password,
    languageMode: LanguageMode::English,
    timezone: 'UTC',
    actorLabel: 'F5 E2E Fixture',
    reason: 'Deterministic F5 browser account',
    source: 'e2e-fixture',
);

$app->make(ChangeAccountStatus::class)->handle(
    email: $email,
    targetStatus: AccountStatus::Active,
    actorLabel: 'F5 E2E Fixture',
    reason: 'Activate deterministic F5 account',
    source: 'e2e-fixture',
);

$user = User::query()
    ->where('email', $email)
    ->sole();

$business = $app
    ->make(CreateBusiness::class)
    ->handle(
        $user,
        'F5 Partnership Business',
        BusinessOriginType::StartedThroughPbr,
        BusinessStage::Planning,
        'USD',
    );

$partner = $app
    ->make(PartnerDirectory::class)
    ->create(
        $user,
        $business,
        'Prepared Partner',
        'Prepared Partner Legal Name',
        'prepared-partner@example.test',
        'Prepared for deterministic F5 browser journey.',
    );

if ($partner === null) {
    throw new RuntimeException('F5 Partner fixture creation failed.');
}

$partnerId = (string) $partner['id'];

$pd = $app
    ->make(PartnerDynamicsReference::class)
    ->record(
        $user,
        $business,
        $partnerId,
        'f5-e2e-assessment-001',
        null,
        'e2e-v1',
        'visionary',
        'builder',
        CarbonImmutable::now(),
    );

if ($pd === null) {
    throw new RuntimeException(
        'F5 PartnerDynamics reference fixture failed.',
    );
}

$ownerMembership = Membership::query()
    ->where('user_id', $user->getKey())
    ->where('business_id', $business->getKey())
    ->where('access_status', 'active')
    ->sole();

$formationAuthority = $app->make(
    FormationAuthorityPolicyWorkflow::class,
);

$authorityDraft = $formationAuthority->createDraft(
    $user,
    $business,
    [
        [
            'decision_type' => 'contribution_approval',
            'decision_method' => 'approval',
            'required_approvals' => 1,
            'required_votes' => 0,
            'quorum_count' => 1,
            'signature_required' => false,
            'reserved_matter' => false,
            'amount_min' => null,
            'amount_max' => null,
            'actors' => [[
                'membership_id' => (string) $ownerMembership->getKey(),
                'capacity' => 'Contribution Approver',
                'can_approve' => true,
                'can_vote' => false,
                'can_sign' => false,
            ]],
        ],
        [
            'decision_type' => 'contribution_acceptance',
            'decision_method' => 'approval',
            'required_approvals' => 1,
            'required_votes' => 0,
            'quorum_count' => 1,
            'signature_required' => false,
            'reserved_matter' => false,
            'amount_min' => null,
            'amount_max' => null,
            'actors' => [[
                'membership_id' => (string) $ownerMembership->getKey(),
                'capacity' => 'Contribution Acceptor',
                'can_approve' => true,
                'can_vote' => false,
                'can_sign' => false,
            ]],
        ],
    ],
    now()->subMinute(),
);

if ($authorityDraft === null) {
    throw new RuntimeException(
        'F5 Contribution authority draft fixture failed.',
    );
}

$authorityFrozen = $formationAuthority->freezeForBootstrap(
    $user,
    $business,
    $authorityDraft['formal_record_version_id'],
    $authorityDraft['revision'],
);

if ($authorityFrozen === null) {
    throw new RuntimeException(
        'F5 Contribution authority freeze fixture failed.',
    );
}

$authorityEstablished = $app
    ->make(EstablishInitialFormationAuthority::class)
    ->execute(
        $user,
        $business,
        $authorityDraft['formal_record_version_id'],
    );

if ($authorityEstablished === null) {
    throw new RuntimeException(
        'F5 Contribution authority establishment fixture failed.',
    );
}

$contribution = $app
    ->make(ContributionWorkflow::class)
    ->create(
        $user,
        $business,
        $partnerId,
        ContributionType::Cash,
        'USD',
        'Prepared cash contribution',
        new ContributionValue('5000.00'),
        null,
        '2026-09-26',
        '2026-10-31',
        [
            'amount_committed' => '5000.00',
            'amount_received' => '0.00',
            'payment_date' => null,
        ],
    );

if ($contribution === null) {
    throw new RuntimeException(
        'F5 Contribution fixture creation failed.',
    );
}

$membershipId = (string) $ownerMembership->getKey();

$documentId = (string) Str::uuid7();
$versionId = (string) Str::uuid7();
$evidenceId = (string) Str::uuid7();
$now = now();

DB::table('documents')->insert([
    'id' => $documentId,
    'business_id' => $business->getKey(),
    'title' => 'F5 Contribution Evidence',
    'category' => 'partners_ownership',
    'created_by_membership_id' => $membershipId,
    'created_at' => $now,
    'updated_at' => $now,
]);

DB::table('document_versions')->insert([
    'id' => $versionId,
    'business_id' => $business->getKey(),
    'document_id' => $documentId,
    'version_number' => 1,
    'original_filename' => 'f5-contribution-evidence.pdf',
    'storage_key' => 'e2e/f5-contribution-evidence.pdf',
    'size_bytes' => 64,
    'mime_type' => 'application/pdf',
    'content_sha256' => hash('sha256', 'f5-e2e-evidence'),
    'uploaded_by_membership_id' => $membershipId,
    'effective_from' => null,
    'supersedes_document_version_id' => null,
    'created_at' => $now,
]);

foreach (['view', 'manage'] as $right) {
    DB::table('document_access_grants')->insert([
        'id' => (string) Str::uuid7(),
        'business_id' => $business->getKey(),
        'membership_id' => $membershipId,
        'document_id' => $documentId,
        'right' => $right,
        'effect' => 'allow',
        'created_at' => $now,
    ]);
}

DB::table('evidence')->insert([
    'id' => $evidenceId,
    'business_id' => $business->getKey(),
    'document_version_id' => $versionId,
    'confidentiality' => 'standard',
    'source_date' => '2026-09-29',
    'submitted_by_membership_id' => $membershipId,
    'verified_at' => null,
    'verified_by_membership_id' => null,
    'verification_method' => null,
    'verification_note' => null,
    'created_at' => $now,
    'updated_at' => $now,
]);

echo 'F5_E2E_FIXTURE=PASS', PHP_EOL;
echo 'F5_E2E_EMAIL=', $email, PHP_EOL;
echo 'F5_E2E_BUSINESS=', $business->name, PHP_EOL;
echo 'F5_E2E_PARTNER=Prepared Partner', PHP_EOL;
echo 'F5_E2E_PASSWORD_OUTPUT=SUPPRESSED', PHP_EOL;
