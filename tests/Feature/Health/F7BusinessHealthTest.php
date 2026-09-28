<?php

declare(strict_types=1);

namespace Tests\Feature\Health;

use App\Application\Access\ProvisionStandardAccessProfiles;
use App\Application\Health\GetBusinessHealth;
use App\Infrastructure\Persistence\Eloquent\Access\Permission;
use App\Infrastructure\Persistence\Eloquent\Access\PermissionGrant;
use App\Infrastructure\Persistence\Eloquent\Businesses\Business;
use App\Infrastructure\Persistence\Eloquent\Identity\User;
use App\Infrastructure\Persistence\Eloquent\Members\Membership;
use App\Infrastructure\Persistence\Eloquent\Records\FormalRecordFamily;
use App\Infrastructure\Persistence\Eloquent\Records\FormalRecordVersion;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Tests\TestCase;

final class F7BusinessHealthTest extends TestCase
{
    use RefreshDatabase;

    public function test_health_is_deterministic_explainable_and_read_only(): void
    {
        [$user, $business] = $this->workspace('health-main');

        $governance = $this->effectiveRecord(
            $user,
            $business,
            'governance_charter',
            now()->addDays(60),
            str_repeat('a', 64),
        );

        $operations = $this->effectiveRecord(
            $user,
            $business,
            'operations_register',
            now()->subDay(),
            str_repeat('b', 64),
        );

        $before = $this->canonicalCounts();

        $first = $this->app->make(GetBusinessHealth::class)
            ->execute($user, $business);

        $second = $this->app->make(GetBusinessHealth::class)
            ->execute($user, $business);

        self::assertNotNull($first);
        self::assertNotNull($second);
        self::assertSame($first['summary'], $second['summary']);
        self::assertSame(
            $first['requirements'],
            $second['requirements'],
            'Generated timestamp may move, but deterministic requirement truth must not.',
        );

        self::assertSame([
            'met' => 2,
            'warning' => 1,
            'blocked' => 0,
            'unknown' => 6,
        ], $first['summary']);

        $requirements = collect($first['requirements'])->keyBy('key');

        self::assertSame('met', $requirements['workspace']['state']);
        self::assertSame('met', $requirements['governance']['state']);
        self::assertSame(
            (string) $governance->getKey(),
            $requirements['governance']['source']['id'],
        );
        self::assertSame(
            str_repeat('a', 64),
            $requirements['governance']['source']['hash'],
        );
        self::assertSame('warning', $requirements['operations']['state']);
        self::assertSame(
            (string) $operations->getKey(),
            $requirements['operations']['source']['id'],
        );
        self::assertSame(
            'review_due',
            $requirements['operations']['reason_code'],
        );
        self::assertSame('unknown', $requirements['finance']['state']);
        self::assertSame(
            'authorized_source_unavailable',
            $requirements['finance']['reason_code'],
        );
        self::assertNull($requirements['finance']['source']);

        self::assertSame($before, $this->canonicalCounts());
    }

    public function test_workspace_state_maps_to_warning_and_blocked_without_a_numeric_score(): void
    {
        [$user, $business] = $this->workspace('health-workspace');

        $business->workspace_status = 'restricted';
        $business->save();

        $restricted = $this->app->make(GetBusinessHealth::class)
            ->execute($user, $business->fresh());

        self::assertNotNull($restricted);
        self::assertSame(
            'warning',
            collect($restricted['requirements'])
                ->firstWhere('key', 'workspace')['state'],
        );
        self::assertArrayNotHasKey('score', $restricted);
        self::assertArrayNotHasKey('percentage', $restricted);

        $business->workspace_status = 'closed';
        $business->save();

        $closed = $this->app->make(GetBusinessHealth::class)
            ->execute($user, $business->fresh());

        self::assertNotNull($closed);
        self::assertSame(
            'blocked',
            collect($closed['requirements'])
                ->firstWhere('key', 'workspace')['state'],
        );
    }

    public function test_active_membership_and_health_capability_are_required_and_cross_business_fails_closed(): void
    {
        [$userA, $businessA, $membershipA] = $this->workspace(
            'health-access-a',
            true,
        );
        [, $businessB] = $this->workspace('health-access-b');

        self::assertNotNull(
            $this->app->make(GetBusinessHealth::class)
                ->execute($userA, $businessA),
        );

        self::assertNull(
            $this->app->make(GetBusinessHealth::class)
                ->execute($userA, $businessB),
        );

        $permission = Permission::query()
            ->where('key', 'business_health.view')
            ->sole();

        PermissionGrant::query()->create([
            'business_id' => $businessA->getKey(),
            'membership_id' => $membershipA->getKey(),
            'permission_id' => $permission->getKey(),
            'effect' => 'deny',
        ]);

        self::assertNull(
            $this->app->make(GetBusinessHealth::class)
                ->execute($userA, $businessA),
        );

        PermissionGrant::query()
            ->where('business_id', $businessA->getKey())
            ->where('membership_id', $membershipA->getKey())
            ->where('permission_id', $permission->getKey())
            ->delete();

        $membershipA->access_status = 'suspended';
        $membershipA->save();

        self::assertNull(
            $this->app->make(GetBusinessHealth::class)
                ->execute($userA, $businessA),
        );

        $membershipA->access_status = 'revoked';
        $membershipA->save();

        self::assertNull(
            $this->app->make(GetBusinessHealth::class)
                ->execute($userA, $businessA),
        );
    }

    /**
     * @return array{0:User,1:Business,2?:Membership}
     */
    private function workspace(
        string $prefix,
        bool $includeMembership = false,
    ): array {
        $business = Business::query()->create([
            'name' => 'F7 Health '.Str::uuid7(),
            'origin_type' => 'started_through_pbr',
            'business_stage' => 'operating',
            'setup_phase' => 'formation',
            'workspace_status' => 'active',
            'base_currency' => 'USD',
        ]);

        $user = User::query()->create([
            'email' => $prefix.'-'.Str::uuid7().'@example.test',
            'password' => Hash::make('test-password'),
            'status' => 'active',
            'password_changed_at' => now(),
        ]);

        $membership = Membership::query()->create([
            'user_id' => $user->getKey(),
            'business_id' => $business->getKey(),
            'access_status' => 'active',
        ]);

        $this->app
            ->make(ProvisionStandardAccessProfiles::class)
            ->execute($business, $membership);

        return $includeMembership
            ? [$user, $business, $membership]
            : [$user, $business];
    }

    private function effectiveRecord(
        User $user,
        Business $business,
        string $recordType,
        mixed $reviewDueAt,
        string $hash,
    ): FormalRecordVersion {
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
            'change_summary' => 'Health fixture '.$recordType,
            'created_by_user_id' => $user->getKey(),
            'last_changed_by_user_id' => $user->getKey(),
            'effective_from' => now()->subMinute(),
            'effective_until' => null,
            'review_due_at' => $reviewDueAt,
            'content_hash' => $hash,
            'frozen_at' => now()->subMinute(),
        ]);

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
                'transitioned_by_user_id' => $user->getKey(),
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

        return $version;
    }

    /** @return array<string,int> */
    private function canonicalCounts(): array
    {
        return [
            'permissions' => DB::table('permission_grants')->count(),
            'documents' => DB::table('document_access_grants')->count(),
            'authority' => DB::table('authority_snapshots')->count(),
            'ownership' => DB::table('ownership_register_versions')->count(),
            'records' => DB::table('formal_record_versions')->count(),
            'heads' => DB::table('record_family_effective_heads')->count(),
        ];
    }
}
