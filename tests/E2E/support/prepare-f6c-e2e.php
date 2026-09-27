<?php

declare(strict_types=1);

use App\Application\Businesses\CreateBusiness;
use App\Application\Identity\ChangeAccountStatus;
use App\Application\Identity\ProvisionAccount;
use App\Domain\Businesses\Enums\BusinessOriginType;
use App\Domain\Businesses\Enums\BusinessStage;
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
        'F6C fixture may run only with APP_ENV=testing.',
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
        'F6C fixture refused non-Test/CI PostgreSQL target: '.$target,
    );
}

foreach ([
    'users',
    'businesses',
    'memberships',
    'finance_policy_versions',
    'finance_payments',
    'reward_policy_versions',
    'distribution_runs',
] as $table) {
    $count = DB::table($table)->count();

    if ($count !== 0) {
        throw new RuntimeException(sprintf(
            'F6C fixture requires fresh Test/CI DB; %s has %d rows.',
            $table,
            $count,
        ));
    }
}

$email = 'f6c-browser@example.com';
$password = getenv('F6C_E2E_PASSWORD');

if (! is_string($password) || $password === '') {
    throw new RuntimeException(
        'F6C_E2E_PASSWORD must be provided through environment.',
    );
}

$app->make(ProvisionAccount::class)->handle(
    email: $email,
    displayName: 'F6C Browser Tester',
    password: $password,
    languageMode: LanguageMode::English,
    timezone: 'UTC',
    actorLabel: 'F6C E2E Fixture',
    reason: 'Deterministic F6C browser account',
    source: 'e2e-fixture',
);

$app->make(ChangeAccountStatus::class)->handle(
    email: $email,
    targetStatus: AccountStatus::Active,
    actorLabel: 'F6C E2E Fixture',
    reason: 'Activate deterministic F6C account',
    source: 'e2e-fixture',
);

$user = User::query()
    ->where('email', $email)
    ->sole();

$business = $app->make(CreateBusiness::class)->handle(
    $user,
    'F6C Finance Rewards Business',
    BusinessOriginType::StartedThroughPbr,
    BusinessStage::Planning,
    'USD',
);

echo 'F6C_E2E_FIXTURE=PASS', PHP_EOL;
echo 'F6C_E2E_EMAIL=', $email, PHP_EOL;
echo 'F6C_E2E_BUSINESS=', $business->name, PHP_EOL;
echo 'F6C_E2E_PASSWORD_OUTPUT=SUPPRESSED', PHP_EOL;
