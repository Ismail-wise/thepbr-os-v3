<?php

declare(strict_types=1);

use App\Application\Businesses\CreateBusiness;
use App\Application\Identity\ChangeAccountStatus;
use App\Application\Identity\ProvisionAccount;
use App\Application\Operations\OperationsRegisterWorkflow;
use App\Domain\Businesses\Enums\BusinessOriginType;
use App\Domain\Businesses\Enums\BusinessStage;
use App\Domain\Identity\Enums\AccountStatus;
use App\Domain\Identity\Enums\LanguageMode;
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
        'F6B fixture may run only with APP_ENV=testing.',
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
        'F6B fixture refused non-Test/CI PostgreSQL target: '.$target,
    );
}

foreach ([
    'users',
    'businesses',
    'memberships',
    'formal_record_families',
    'operations_register_versions',
    'proposal_versions',
] as $table) {
    $count = DB::table($table)->count();

    if ($count !== 0) {
        throw new RuntimeException(sprintf(
            'F6B fixture requires fresh Test/CI DB; %s has %d rows.',
            $table,
            $count,
        ));
    }
}

$email = 'f6b-browser@example.com';
$password = getenv('F6B_E2E_PASSWORD');

if (! is_string($password) || $password === '') {
    throw new RuntimeException(
        'F6B_E2E_PASSWORD must be provided through environment.',
    );
}

$app->make(ProvisionAccount::class)->handle(
    email: $email,
    displayName: 'F6B Browser Tester',
    password: $password,
    languageMode: LanguageMode::English,
    timezone: 'UTC',
    actorLabel: 'F6B E2E Fixture',
    reason: 'Deterministic F6B browser account',
    source: 'e2e-fixture',
);

$app->make(ChangeAccountStatus::class)->handle(
    email: $email,
    targetStatus: AccountStatus::Active,
    actorLabel: 'F6B E2E Fixture',
    reason: 'Activate deterministic F6B account',
    source: 'e2e-fixture',
);

$user = User::query()
    ->where('email', $email)
    ->sole();

$business = $app
    ->make(CreateBusiness::class)
    ->handle(
        $user,
        'F6B Operations Business',
        BusinessOriginType::StartedThroughPbr,
        BusinessStage::Planning,
        'USD',
    );

$membership = Membership::query()
    ->where('business_id', $business->getKey())
    ->where('user_id', $user->getKey())
    ->sole();

$workflow = $app->make(
    OperationsRegisterWorkflow::class,
);

$created = $workflow->createDraft(
    $user,
    $business,
    [
        'organization_name' => 'F6B Operations Business',
        'notes' => 'Prepared for deterministic Operations browser review.',
        'roles' => [[
            'role_key' => 'operations_lead',
            'name' => 'Operations Lead',
            'function_name' => 'Operations',
            'purpose' => 'Own operating delivery.',
            'responsibilities' =>
                'Plan, coordinate, report and close work.',
            'operational_authority' =>
                'Coordinate approved operating work only.',
            'reports_to_role_key' => null,
            'report_type' => 'Operating update',
            'reporting_frequency' => 'Weekly',
            'meeting_frequency' => 'Weekly',
            'review_frequency' => 'Quarterly',
            'assignments' => [[
                'membership_id' =>
                    (string) $membership->getKey(),
                'assignment_type' => 'primary',
            ]],
        ]],
        'raci' => [],
        'kpis' => [],
    ],
    now()->subMinute(),
);

if ($created === null) {
    throw new RuntimeException(
        'F6B Operations draft fixture creation failed.',
    );
}

$submitted = $workflow->submitForGovernance(
    $user,
    $business,
    $created['formal_record_version_id'],
    1,
);

if ($submitted === null) {
    throw new RuntimeException(
        'F6B Operations ready-for-review fixture failed.',
    );
}

$state = DB::table(
    'record_version_state_transitions',
)
    ->where(
        'formal_record_version_id',
        $created['formal_record_version_id'],
    )
    ->orderByDesc('sequence')
    ->value('to_state');

if ($state !== 'ready_for_review') {
    throw new RuntimeException(
        'F6B Operations fixture did not reach ReadyForReview.',
    );
}

echo 'F6B_E2E_FIXTURE=PASS', PHP_EOL;
echo 'F6B_E2E_EMAIL=', $email, PHP_EOL;
echo 'F6B_E2E_BUSINESS=', $business->name, PHP_EOL;
echo 'F6B_E2E_OPERATIONS_VERSION=',
    $created['formal_record_version_id'],
    PHP_EOL;
echo 'F6B_E2E_PASSWORD_OUTPUT=SUPPRESSED', PHP_EOL;
