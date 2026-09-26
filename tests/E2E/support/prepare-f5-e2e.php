<?php

declare(strict_types=1);

use App\Application\Businesses\CreateBusiness;
use App\Application\Identity\ChangeAccountStatus;
use App\Application\Identity\ProvisionAccount;
use App\Application\Partnership\ContributionWorkflow;
use App\Application\Partnership\DueDiligenceWorkflow;
use App\Application\Partnership\PartnerDirectory;
use App\Application\Partnership\PartnerDynamicsReference;
use App\Domain\Businesses\Enums\BusinessOriginType;
use App\Domain\Businesses\Enums\BusinessStage;
use App\Domain\Identity\Enums\AccountStatus;
use App\Domain\Identity\Enums\LanguageMode;
use App\Domain\Partnership\Enums\ContributionType;
use App\Domain\Partnership\Enums\DueDiligenceStatus;
use App\Domain\Partnership\ValueObjects\ContributionValue;
use App\Infrastructure\Persistence\Eloquent\Identity\User;
use Carbon\CarbonImmutable;
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

$ddFields = [
    'identity_legal_info' => 'Identity prepared for UAT.',
    'background_summary' => 'Background prepared for UAT.',
    'business_experience' => 'SME operating experience.',
    'financial_capacity' => 'Prepared review state.',
    'reputation' => 'Prepared review state.',
    'existing_business_interests' => null,
    'conflict_of_interest' => 'No known conflict in fixture.',
    'time_commitment' => 'Available for agreed commitment.',
    'legal_regulatory_check' => 'Pending final human review.',
    'notes' => 'Prepared deterministic DD.',
];

$ddDraft = $app
    ->make(DueDiligenceWorkflow::class)
    ->save(
        $user,
        $business,
        $partnerId,
        null,
        0,
        DueDiligenceStatus::Draft,
        null,
        $ddFields,
    );

if (
    $ddDraft === null
    || ($ddDraft['status'] ?? null) !== DueDiligenceStatus::Draft->value
    || (int) ($ddDraft['revision'] ?? 0) !== 1
) {
    throw new RuntimeException(
        'F5 Due Diligence Draft fixture creation failed.',
    );
}

$dd = $app
    ->make(DueDiligenceWorkflow::class)
    ->save(
        $user,
        $business,
        $partnerId,
        (string) $ddDraft['id'],
        (int) $ddDraft['revision'],
        DueDiligenceStatus::InReview,
        'moderate',
        $ddFields,
    );

if (
    $dd === null
    || ($dd['status'] ?? null) !== DueDiligenceStatus::InReview->value
    || (int) ($dd['revision'] ?? 0) !== 2
) {
    throw new RuntimeException(
        'F5 Due Diligence In Review fixture transition failed.',
    );
}

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

echo 'F5_E2E_FIXTURE=PASS', PHP_EOL;
echo 'F5_E2E_EMAIL=', $email, PHP_EOL;
echo 'F5_E2E_BUSINESS=', $business->name, PHP_EOL;
echo 'F5_E2E_PARTNER=Prepared Partner', PHP_EOL;
echo 'F5_E2E_PASSWORD_OUTPUT=SUPPRESSED', PHP_EOL;
