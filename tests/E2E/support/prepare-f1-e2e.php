<?php

declare(strict_types=1);

use App\Application\Identity\ChangeAccountStatus;
use App\Application\Identity\ProvisionAccount;
use App\Domain\Identity\Enums\AccountStatus;
use App\Domain\Identity\Enums\LanguageMode;
use App\Infrastructure\Persistence\Eloquent\Businesses\Business;
use App\Infrastructure\Persistence\Eloquent\Identity\SecurityEvent;
use App\Infrastructure\Persistence\Eloquent\Identity\User;
use App\Infrastructure\Persistence\Eloquent\Identity\UserProfile;
use App\Infrastructure\Persistence\Eloquent\Members\Membership;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Foundation\Application;

$basePath = dirname(__DIR__, 3);

require $basePath.'/vendor/autoload.php';

/** @var Application $app */
$app = require $basePath.'/bootstrap/app.php';

$app->make(Kernel::class)->bootstrap();

if (! $app->environment('testing')) {
    throw new RuntimeException(
        'F1-A13 fixture may run only with APP_ENV=testing.',
    );
}

$config = $app->make('config');

$connection = (string) $config->get('database.default');
$host = (string) $config->get('database.connections.pgsql.host');
$port = (string) $config->get('database.connections.pgsql.port');
$database = (string) $config->get('database.connections.pgsql.database');
$username = (string) $config->get('database.connections.pgsql.username');

$target = implode('|', [
    $connection,
    $host,
    $port,
    $database,
    $username,
]);

$allowedTargets = [
    'pgsql|127.0.0.1|5434|thepbr_os_v3_test|thepbr_os_v3_test_app',
    'pgsql|127.0.0.1|5432|pbr_ci|pbr_ci',
];

if (! in_array($target, $allowedTargets, true)) {
    throw new RuntimeException(
        'F1-A13 fixture refused non-Test/CI PostgreSQL target: '.$target,
    );
}

$initialCounts = [
    'users' => User::query()->count(),
    'user_profiles' => UserProfile::query()->count(),
    'businesses' => Business::query()->count(),
    'memberships' => Membership::query()->count(),
    'security_events' => SecurityEvent::query()->count(),
];

foreach ($initialCounts as $table => $count) {
    if ($count !== 0) {
        throw new RuntimeException(sprintf(
            'F1-A13 fixture requires a freshly reset database; %s has %d rows.',
            $table,
            $count,
        ));
    }
}

$email = 'f1-a13-browser@example.com';
$password = getenv('F1_E2E_PASSWORD');

if (! is_string($password) || $password === '') {
    throw new RuntimeException(
        'F1_E2E_PASSWORD must be provided through the environment.',
    );
}

$provisionAccount = $app->make(ProvisionAccount::class);

$provisionAccount->handle(
    email: $email,
    displayName: 'F1 A13 Browser Tester',
    password: $password,
    languageMode: LanguageMode::English,
    timezone: 'UTC',
    actorLabel: 'F1-A13 E2E Fixture',
    reason: 'Deterministic browser-test account provisioning',
    source: 'e2e-fixture',
);

$changeAccountStatus = $app->make(ChangeAccountStatus::class);

$changeAccountStatus->handle(
    email: $email,
    targetStatus: AccountStatus::Active,
    actorLabel: 'F1-A13 E2E Fixture',
    reason: 'Activate deterministic browser-test account',
    source: 'e2e-fixture',
);

$user = User::query()
    ->with('profile')
    ->where('email', $email)
    ->sole();

if ($user->status !== AccountStatus::Active) {
    throw new RuntimeException('Fixture account is not Active.');
}

if ($user->profile === null) {
    throw new RuntimeException('Fixture account profile is missing.');
}

if ($user->profile->language_mode !== LanguageMode::English) {
    throw new RuntimeException('Fixture account did not start in English mode.');
}

$finalCounts = [
    'users' => User::query()->count(),
    'user_profiles' => UserProfile::query()->count(),
    'businesses' => Business::query()->count(),
    'memberships' => Membership::query()->count(),
    'security_events' => SecurityEvent::query()->count(),
];

$expectedCounts = [
    'users' => 1,
    'user_profiles' => 1,
    'businesses' => 0,
    'memberships' => 0,
    'security_events' => 2,
];

if ($finalCounts !== $expectedCounts) {
    throw new RuntimeException(sprintf(
        'Unexpected F1-A13 fixture counts. Expected %s; received %s.',
        json_encode($expectedCounts, JSON_THROW_ON_ERROR),
        json_encode($finalCounts, JSON_THROW_ON_ERROR),
    ));
}

echo 'F1_E2E_FIXTURE=PASS', PHP_EOL;
echo 'F1_E2E_ACCOUNT_STATUS=', $user->status->value, PHP_EOL;
echo 'F1_E2E_LANGUAGE=', $user->profile->language_mode->value, PHP_EOL;
echo 'F1_E2E_USERS=', $finalCounts['users'], PHP_EOL;
echo 'F1_E2E_BUSINESSES=', $finalCounts['businesses'], PHP_EOL;
echo 'F1_E2E_MEMBERSHIPS=', $finalCounts['memberships'], PHP_EOL;
echo 'F1_E2E_SECURITY_EVENTS=', $finalCounts['security_events'], PHP_EOL;
echo 'F1_E2E_PASSWORD_OUTPUT=SUPPRESSED', PHP_EOL;
