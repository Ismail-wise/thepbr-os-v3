<?php

declare(strict_types=1);

use App\Application\Businesses\CreateBusiness;
use App\Application\Formation\BusinessModelPlanning;
use App\Application\Formation\CapitalPlanning;
use App\Application\Formation\ExistingBusinessBaseline;
use App\Application\Formation\NewBusinessPlanning;
use App\Application\Identity\ChangeAccountStatus;
use App\Application\Identity\ProvisionAccount;
use App\Domain\Businesses\Enums\BusinessOriginType;
use App\Domain\Businesses\Enums\BusinessStage;
use App\Domain\Capital\ValueObjects\CapitalRequirement;
use App\Domain\Identity\Enums\AccountStatus;
use App\Domain\Identity\Enums\LanguageMode;
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
        'F4 fixture may run only with APP_ENV=testing.',
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
        'F4 fixture refused non-Test/CI PostgreSQL target: '.$target,
    );
}

foreach (
    [
        'users',
        'businesses',
        'memberships',
        'business_ideas',
        'business_model_canvases',
        'capital_scenarios',
        'existing_business_profiles',
        'valuations',
    ] as $table
) {
    $count = DB::table($table)->count();

    if ($count !== 0) {
        throw new RuntimeException(sprintf(
            'F4 fixture requires a fresh Test/CI database; %s has %d rows.',
            $table,
            $count,
        ));
    }
}

$email = 'f4-browser@example.com';
$password = getenv('F4_E2E_PASSWORD');

if (! is_string($password) || $password === '') {
    throw new RuntimeException(
        'F4_E2E_PASSWORD must be provided through the environment.',
    );
}

$app->make(ProvisionAccount::class)->handle(
    email: $email,
    displayName: 'F4 Browser Tester',
    password: $password,
    languageMode: LanguageMode::English,
    timezone: 'UTC',
    actorLabel: 'F4 E2E Fixture',
    reason: 'Deterministic F4 browser-test account',
    source: 'e2e-fixture',
);

$app->make(ChangeAccountStatus::class)->handle(
    email: $email,
    targetStatus: AccountStatus::Active,
    actorLabel: 'F4 E2E Fixture',
    reason: 'Activate deterministic F4 browser-test account',
    source: 'e2e-fixture',
);

$user = User::query()
    ->where('email', $email)
    ->sole();

$createBusiness = $app->make(CreateBusiness::class);

$newBusiness = $createBusiness->handle(
    $user,
    'F4 New Business',
    BusinessOriginType::StartedThroughPbr,
    BusinessStage::Planning,
    'USD',
);

$existingBusiness = $createBusiness->handle(
    $user,
    'F4 Existing Business',
    BusinessOriginType::ExistingBusinessImportedIntoPbr,
    BusinessStage::Operating,
    'USD',
);

$newPlanning = $app->make(NewBusinessPlanning::class);
$businessModel = $app->make(BusinessModelPlanning::class);

$newPlanning->saveIdea(
    $user,
    $newBusiness,
    0,
    [
        'summary' => 'Prepared F4 new-business concept',
        'problem' => 'Fragmented SME operating decisions',
        'target_customer' => 'SME owners',
        'proposed_solution' => 'Structured partnership operating system',
    ],
);

$businessModel->saveBmc(
    $user,
    $newBusiness,
    0,
    [
        'customer_segments' => 'SME owners',
        'value_propositions' => 'One controlled business workspace',
        'channels' => 'Direct',
        'customer_relationships' => 'Guided',
        'revenue_streams' => 'Service revenue',
        'key_resources' => 'Team and systems',
        'key_activities' => 'Validation and delivery',
        'key_partnerships' => 'Business partners',
        'cost_structure' => 'People and systems',
    ],
);

$newPlanning->addFeasibilityScenario(
    $user,
    $newBusiness,
    'Base feasibility',
    '12000.00',
    '8000.00',
    'Prepared E2E feasibility scenario.',
);

$newPlanning->savePartnershipFit(
    $user,
    $newBusiness,
    0,
    [
        'goals_alignment' => 'Aligned',
        'role_expectations' => 'Explicit',
        'decision_process' => 'Governed',
        'risk_tolerance' => 'Moderate',
        'unresolved_questions' => 'None for E2E',
    ],
);

$capital = $app->make(CapitalPlanning::class);

$capital->saveScenario(
    $user,
    $newBusiness,
    'base',
    0,
    'Base',
    new CapitalRequirement(
        '1000.00',
        '2000.00',
        '3000.00',
        '500.00',
        '2500.00',
    ),
    'Prepared Base scenario.',
);

$baseline = $app->make(ExistingBusinessBaseline::class);

$businessModel->saveBmc(
    $user,
    $existingBusiness,
    0,
    [
        'customer_segments' => 'Existing customer base',
        'value_propositions' => 'Established operating offer',
        'channels' => 'Existing channels',
        'customer_relationships' => 'Ongoing relationships',
        'revenue_streams' => 'Operating revenue',
        'key_resources' => 'Existing assets and team',
        'key_activities' => 'Current operations',
        'key_partnerships' => 'Existing suppliers and partners',
        'cost_structure' => 'Operating cost base',
    ],
);

$baseline->saveProfile(
    $user,
    $existingBusiness,
    0,
    [
        'operating_since' => '2022-01-01',
        'summary' => 'Prepared operating business baseline',
        'notes' => null,
    ],
);

$baseline->addFinancialSnapshot(
    $user,
    $existingBusiness,
    [
        'as_of_date' => '2026-09-26',
        'revenue' => '300000.00',
        'expenses' => '220000.00',
        'cash' => '45000.00',
        'receivables' => '25000.00',
        'payables' => '15000.00',
        'notes' => 'Prepared E2E snapshot.',
    ],
);

$baseline->addOwnerPosition(
    $user,
    $existingBusiness,
    [
        'owner_name' => 'Existing Owner',
        'baseline_percent' => '100.0000',
        'notes' => 'Baseline position only.',
    ],
);

$baseline->addValuation(
    $user,
    $existingBusiness,
    [
        'as_of_date' => '2026-09-26',
        'amount' => '250000.00',
        'method' => 'Prepared baseline method',
        'review_state' => 'reviewed',
        'notes' => 'Reviewed baseline state only.',
    ],
);

echo 'F4_E2E_FIXTURE=PASS', PHP_EOL;
echo 'F4_E2E_EMAIL=', $email, PHP_EOL;
echo 'F4_E2E_NEW_BUSINESS=', $newBusiness->name, PHP_EOL;
echo 'F4_E2E_EXISTING_BUSINESS=', $existingBusiness->name, PHP_EOL;
echo 'F4_E2E_PASSWORD_OUTPUT=SUPPRESSED', PHP_EOL;
