<?php

declare(strict_types=1);

use App\Application\Identity\ChangeAccountStatus;
use App\Application\Identity\ProvisionAccount;
use App\Domain\Access\Enums\PermissionEffect;
use App\Domain\Businesses\Enums\BusinessOriginType;
use App\Domain\Businesses\Enums\BusinessStage;
use App\Domain\Businesses\Enums\SetupPhase;
use App\Domain\Businesses\Enums\WorkspaceStatus;
use App\Domain\Identity\Enums\AccountStatus;
use App\Domain\Identity\Enums\LanguageMode;
use App\Domain\Members\Enums\MembershipAccessStatus;
use App\Infrastructure\Persistence\Eloquent\Access\AccessPolicy;
use App\Infrastructure\Persistence\Eloquent\Access\Permission;
use App\Infrastructure\Persistence\Eloquent\Access\PermissionGrant;
use App\Infrastructure\Persistence\Eloquent\Access\PermissionProfile;
use App\Infrastructure\Persistence\Eloquent\Businesses\Business;
use App\Infrastructure\Persistence\Eloquent\Identity\User;
use App\Infrastructure\Persistence\Eloquent\Members\Membership;
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
        'F2 Round5 fixture may run only with APP_ENV=testing.',
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
        'F2 Round5 fixture refused non-Test/CI PostgreSQL target: '.$target,
    );
}

$zeroTables = [
    'users',
    'user_profiles',
    'businesses',
    'memberships',
    'security_events',
    'permission_profiles',
    'permission_profile_permissions',
    'membership_permission_profiles',
    'permission_grants',
    'access_policies',
    'record_access_rules',
    'formal_record_families',
    'formal_record_versions',
    'proposals',
    'proposal_versions',
    'audit_events',
    'business_events',
    'documents',
    'document_versions',
    'document_access_grants',
    'evidence',
    'evidence_links',
];

foreach ($zeroTables as $table) {
    $count = DB::table($table)->count();

    if ($count !== 0) {
        throw new RuntimeException(sprintf(
            'F2 Round5 fixture requires a fresh Test/CI database; %s has %d rows.',
            $table,
            $count,
        ));
    }
}

/*
 * Later milestones may install system-capability reference rows during
 * migration. Those rows are schema baseline, not stale fixture data.
 *
 * The F2 browser fixture owns only these three permission keys, so freshness
 * means these keys must not already exist before the fixture creates them.
 */
$fixturePermissionKeys = [
    'permission_profiles.view',
    'records.activity.view',
    'records.view',
];

$existingFixturePermissionKeys = Permission::query()
    ->whereIn('key', $fixturePermissionKeys)
    ->pluck('key')
    ->sort()
    ->values()
    ->all();

if ($existingFixturePermissionKeys !== []) {
    throw new RuntimeException(
        'F2 Round5 fixture-owned permissions already exist: '
        .implode(', ', $existingFixturePermissionKeys),
    );
}

$email = 'f2-round5-browser@example.com';
$password = getenv('F2_E2E_PASSWORD');

if (! is_string($password) || $password === '') {
    throw new RuntimeException(
        'F2_E2E_PASSWORD must be provided through the environment.',
    );
}

$app->make(ProvisionAccount::class)->handle(
    email: $email,
    displayName: 'F2 Round5 Browser Tester',
    password: $password,
    languageMode: LanguageMode::English,
    timezone: 'UTC',
    actorLabel: 'F2 Round5 E2E Fixture',
    reason: 'Deterministic F2 integrated browser-test account',
    source: 'e2e-fixture',
);

$app->make(ChangeAccountStatus::class)->handle(
    email: $email,
    targetStatus: AccountStatus::Active,
    actorLabel: 'F2 Round5 E2E Fixture',
    reason: 'Activate deterministic F2 browser-test account',
    source: 'e2e-fixture',
);

$user = User::query()
    ->where('email', $email)
    ->sole();

$business = Business::query()->create([
    'name' => 'F2 Integrated Business',
    'origin_type' => BusinessOriginType::StartedThroughPbr,
    'business_stage' => BusinessStage::Idea,
    'setup_phase' => SetupPhase::Formation,
    'workspace_status' => WorkspaceStatus::Active,
    'base_currency' => 'USD',
]);

$membership = Membership::query()->create([
    'user_id' => $user->getKey(),
    'business_id' => $business->getKey(),
    'access_status' => MembershipAccessStatus::Active,
]);

$profileView = Permission::query()->create([
    'key' => 'permission_profiles.view',
]);

$activityView = Permission::query()->create([
    'key' => 'records.activity.view',
]);

$recordsView = Permission::query()->create([
    'key' => 'records.view',
]);

PermissionGrant::query()->create([
    'business_id' => $business->getKey(),
    'membership_id' => $membership->getKey(),
    'permission_id' => $profileView->getKey(),
    'effect' => PermissionEffect::Allow,
]);

$profile = PermissionProfile::query()->create([
    'business_id' => $business->getKey(),
    'name' => 'F2 Operator',
]);

$now = now();

DB::table('permission_profile_permissions')->insert([
    'business_id' => $business->getKey(),
    'permission_profile_id' => $profile->getKey(),
    'permission_id' => $activityView->getKey(),
    'created_at' => $now,
    'updated_at' => $now,
]);

DB::table('permission_profile_permissions')->insert([
    'business_id' => $business->getKey(),
    'permission_profile_id' => $profile->getKey(),
    'permission_id' => $recordsView->getKey(),
    'created_at' => $now,
    'updated_at' => $now,
]);

DB::table('membership_permission_profiles')->insert([
    'business_id' => $business->getKey(),
    'membership_id' => $membership->getKey(),
    'permission_profile_id' => $profile->getKey(),
    'created_at' => $now,
    'updated_at' => $now,
]);

AccessPolicy::query()->create([
    'business_id' => $business->getKey(),
    'membership_id' => $membership->getKey(),
    'permission_profile_id' => null,
    'permission_id' => $profileView->getKey(),
    'resource_type' => PermissionProfile::class,
    'effect' => PermissionEffect::Allow,
]);

echo 'F2_E2E_FIXTURE=PASS', PHP_EOL;
echo 'F2_E2E_EMAIL=', $email, PHP_EOL;
echo 'F2_E2E_BUSINESS=', $business->name, PHP_EOL;
echo 'F2_E2E_PROFILE=', $profile->name, PHP_EOL;
echo 'F2_E2E_PASSWORD_OUTPUT=SUPPRESSED', PHP_EOL;
