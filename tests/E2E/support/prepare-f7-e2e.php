<?php

declare(strict_types=1);

use App\Application\Businesses\CreateBusiness;
use App\Application\Identity\ChangeAccountStatus;
use App\Application\Identity\ProvisionAccount;
use App\Application\Partnership\DueDiligenceWorkflow;
use App\Application\Partnership\PartnerDirectory;
use App\Domain\Businesses\Enums\BusinessOriginType;
use App\Domain\Businesses\Enums\BusinessStage;
use App\Domain\Identity\Enums\AccountStatus;
use App\Domain\Identity\Enums\LanguageMode;
use App\Domain\Partnership\Enums\DueDiligenceStatus;
use App\Infrastructure\Persistence\Eloquent\Identity\User;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Foundation\Application;
use Illuminate\Support\Facades\DB;

$basePath = dirname(__DIR__, 3);

require $basePath.'/vendor/autoload.php';

/** @var Application $app */
$app = require $basePath.'/bootstrap/app.php';

$app->make(Kernel::class)->bootstrap();

if (! $app->environment('testing')) {
    throw new RuntimeException(
        'F7 E2E fixture may run only with APP_ENV=testing.',
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
    'pgsql|/tmp/pbr-f7-focused-pg/socket|55439|pbr_f7_test|pbragent',
];

if (! in_array($target, $allowedTargets, true)) {
    throw new RuntimeException(
        'F7 E2E fixture refused non-Test/CI PostgreSQL target: '.$target,
    );
}

if (getenv('F7_E2E_RESET') === '1') {
    $exitCode = $app->make(Kernel::class)->call('migrate:fresh', [
        '--force' => true,
        '--no-interaction' => true,
    ]);

    if ($exitCode !== 0) {
        throw new RuntimeException(
            'F7 E2E fixture failed to reset isolated Test/CI database.',
        );
    }
}

foreach (['users', 'businesses', 'memberships', 'partners'] as $table) {
    $count = DB::table($table)->count();

    if ($count !== 0) {
        throw new RuntimeException(sprintf(
            'F7 E2E fixture requires fresh DB; %s has %d rows.',
            $table,
            $count,
        ));
    }
}

$password = getenv('F7_E2E_PASSWORD');

if (! is_string($password) || $password === '') {
    throw new RuntimeException(
        'F7_E2E_PASSWORD must be provided through environment.',
    );
}

$ownerEmail = 'f7-owner@example.com';
$businessName = 'F7 Lifecycle Intelligence Business';
$foreignBusinessName = 'F7 Foreign Business';

$app->make(ProvisionAccount::class)->handle(
    email: $ownerEmail,
    displayName: 'F7 Lifecycle Owner',
    password: $password,
    languageMode: LanguageMode::English,
    timezone: 'UTC',
    actorLabel: 'F7 E2E Fixture',
    reason: 'Deterministic F7 browser account',
    source: 'e2e-fixture',
);

$app->make(ChangeAccountStatus::class)->handle(
    email: $ownerEmail,
    targetStatus: AccountStatus::Active,
    actorLabel: 'F7 E2E Fixture',
    reason: 'Activate deterministic F7 account',
    source: 'e2e-fixture',
);

$owner = User::query()->where('email', $ownerEmail)->sole();

$business = $app->make(CreateBusiness::class)->handle(
    $owner,
    $businessName,
    BusinessOriginType::StartedThroughPbr,
    BusinessStage::Operating,
    'USD',
);

$foreignBusiness = $app->make(CreateBusiness::class)->handle(
    $owner,
    $foreignBusinessName,
    BusinessOriginType::StartedThroughPbr,
    BusinessStage::Operating,
    'USD',
);

$directory = $app->make(PartnerDirectory::class);

$localPartner = $directory->create(
    $owner,
    $business,
    'Lifecycle Local Partner',
    null,
    'local-lifecycle@example.test',
    'Visible only in the current Business.',
);

$foreignPartner = $directory->create(
    $owner,
    $foreignBusiness,
    'Lifecycle Foreign Secret Partner',
    null,
    'foreign-lifecycle@example.test',
    'Must never leak into another Business Search.',
);

if ($localPartner === null || $foreignPartner === null) {
    throw new RuntimeException(
        'F7 E2E fixture could not create deterministic Partners.',
    );
}

$dd = $app->make(DueDiligenceWorkflow::class);
$ddFields = [
    'identity_legal_info' => 'Verified for deterministic F7 browser UAT.',
    'background_summary' => 'Reviewed for deterministic F7 browser UAT.',
    'business_experience' => 'Relevant experience reviewed.',
    'financial_capacity' => 'Capacity reviewed.',
    'reputation' => 'No material issue identified.',
    'existing_business_interests' => 'None declared.',
    'conflict_of_interest' => 'None identified.',
    'time_commitment' => 'Confirmed.',
    'legal_regulatory_check' => 'Clear.',
    'notes' => 'F7 browser Partner Change prerequisite.',
];

$ddCase = $dd->save(
    $owner,
    $business,
    (string) $localPartner['id'],
    null,
    0,
    DueDiligenceStatus::Draft,
    null,
    $ddFields,
);

if ($ddCase === null) {
    throw new RuntimeException(
        'F7 E2E fixture could not create Due Diligence Draft.',
    );
}

$ddCase = $dd->save(
    $owner,
    $business,
    (string) $localPartner['id'],
    (string) $ddCase['id'],
    (int) $ddCase['revision'],
    DueDiligenceStatus::InReview,
    'moderate',
    $ddFields,
);

if ($ddCase === null) {
    throw new RuntimeException(
        'F7 E2E fixture could not move Due Diligence to In Review.',
    );
}

$ddCase = $dd->save(
    $owner,
    $business,
    (string) $localPartner['id'],
    (string) $ddCase['id'],
    (int) $ddCase['revision'],
    DueDiligenceStatus::Completed,
    'moderate',
    $ddFields,
);

if (
    $ddCase === null
    || ($ddCase['status'] ?? null) !== DueDiligenceStatus::Completed->value
) {
    throw new RuntimeException(
        'F7 E2E fixture could not complete Due Diligence.',
    );
}

foreach ([$business, $foreignBusiness] as $candidate) {
    $membershipCount = DB::table('memberships')
        ->where('business_id', $candidate->getKey())
        ->count();

    if ($membershipCount !== 1) {
        throw new RuntimeException(
            'F7 E2E fixture expected exactly one owner Membership per Business.',
        );
    }
}

if (
    DB::table('ownership_register_versions')->count() !== 0
    || DB::table('authority_snapshots')->count() !== 0
    || DB::table('document_access_grants')->count() !== 0
) {
    throw new RuntimeException(
        'F7 E2E setup must not manufacture Ownership, Governance authority, or Document rights.',
    );
}

echo 'F7_E2E_FIXTURE=PASS', PHP_EOL;
