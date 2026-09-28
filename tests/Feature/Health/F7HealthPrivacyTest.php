<?php

declare(strict_types=1);

namespace Tests\Feature\Health;

require_once __DIR__.'/../Conflict/F6EConflictPolicyTest.php';

use App\Application\Conflict\ConflictRecordVisibility;
use App\Application\Health\GetBusinessHealth;
use App\Domain\Access\Enums\StandardAccessProfile;
use App\Infrastructure\Persistence\Eloquent\Access\PermissionProfile;
use Illuminate\Support\Facades\DB;
use Tests\Feature\Conflict\F6EConflictTestCase;

final class F7HealthPrivacyTest extends F6EConflictTestCase
{
    public function test_hidden_conflict_case_cannot_change_health_state_count_reason_or_source(): void
    {
        $context = $this->context();

        $profile = PermissionProfile::query()
            ->where('business_id', $context['business']->getKey())
            ->where('name', StandardAccessProfile::Partner->value)
            ->sole();

        DB::table('membership_permission_profiles')->insert([
            'business_id' => $context['business']->getKey(),
            'membership_id' => $context['party']->getKey(),
            'permission_profile_id' => $profile->getKey(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $health = $this->app->make(GetBusinessHealth::class);

        $before = $health->execute(
            $context['party_user'],
            $context['business'],
        );

        self::assertNotNull($before);

        $opened = $this->openCase($context);
        $caseId = $opened['id'];
        $caseNumber = (string) DB::table('conflict_cases')
            ->where('business_id', $context['business']->getKey())
            ->where('id', $caseId)
            ->value('case_number');

        self::assertFalse(
            $this->app->make(ConflictRecordVisibility::class)->canView(
                $context['party_user'],
                $context['business'],
                $caseId,
            ),
        );

        $afterHiddenCase = $health->execute(
            $context['party_user'],
            $context['business'],
        );

        self::assertNotNull($afterHiddenCase);
        self::assertSame($before['summary'], $afterHiddenCase['summary']);
        self::assertSame(
            $before['requirements'],
            $afterHiddenCase['requirements'],
        );

        $encoded = json_encode($afterHiddenCase, JSON_THROW_ON_ERROR);

        self::assertStringNotContainsString($caseId, $encoded);
        self::assertStringNotContainsString($caseNumber, $encoded);

        $visibility = $this->app->make(ConflictRecordVisibility::class);

        $visibility->grantRestrictedAccess(
            $context['business'],
            $caseId,
            [(string) $context['party']->getKey()],
            false,
        );

        $afterVisibleCase = $health->execute(
            $context['party_user'],
            $context['business'],
        );

        self::assertNotNull($afterVisibleCase);
        self::assertSame(
            $before['summary'],
            $afterVisibleCase['summary'],
            'Health readiness must not manufacture a case-count signal from Conflict records.',
        );
        self::assertSame(
            $before['requirements'],
            $afterVisibleCase['requirements'],
        );
    }

    public function test_health_only_capability_does_not_reveal_hidden_module_existence(): void
    {
        $context = $this->context();

        $this->grantCapability(
            $context['business'],
            $context['party'],
            'business_health.view',
        );

        $payload = $this->app->make(GetBusinessHealth::class)
            ->execute(
                $context['party_user'],
                $context['business'],
            );

        self::assertNotNull($payload);
        self::assertCount(1, $payload['requirements']);
        self::assertSame('workspace', $payload['requirements'][0]['key']);
        self::assertSame([
            'met' => 1,
            'warning' => 0,
            'blocked' => 0,
            'unknown' => 0,
        ], $payload['summary']);

        $encoded = json_encode($payload, JSON_THROW_ON_ERROR);

        self::assertStringNotContainsString(
            (string) $context['governance_version_id'],
            $encoded,
        );
        self::assertStringNotContainsString(
            (string) $context['operations_version_id'],
            $encoded,
        );
        self::assertStringNotContainsString(
            (string) $context['policy_version_id'],
            $encoded,
        );
    }
}
