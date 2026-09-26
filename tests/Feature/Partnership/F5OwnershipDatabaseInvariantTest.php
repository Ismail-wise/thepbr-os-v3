<?php

declare(strict_types=1);

namespace Tests\Feature\Partnership;

use App\Domain\Members\Enums\MembershipAccessStatus;
use App\Infrastructure\Persistence\Eloquent\Businesses\Business;
use App\Infrastructure\Persistence\Eloquent\Identity\User;
use App\Infrastructure\Persistence\Eloquent\Members\Membership;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

final class F5OwnershipDatabaseInvariantTest extends TestCase
{
    use RefreshDatabase;

    public function test_database_refuses_non_accepted_contribution_as_ownership_source(): void
    {
        [$business, $membership] = $this->businessContext(
            'ownership-db@example.test',
        );

        $partnerId = (string) Str::uuid();
        $contributionId = (string) Str::uuid();
        $scenarioId = (string) Str::uuid();

        DB::table('partners')->insert([
            'id' => $partnerId,
            'business_id' => $business->getKey(),
            'display_name' => 'Partner A',
            'status' => 'prospective',
            'revision' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('contributions')->insert([
            'id' => $contributionId,
            'business_id' => $business->getKey(),
            'partner_id' => $partnerId,
            'contribution_type' => 'cash',
            'status' => 'proposed',
            'currency' => 'THB',
            'description' => 'Non-accepted ownership invariant fixture.',
            'proposed_value' => '100000.00',
            'reviewed_value' => null,
            'approved_value' => null,
            'accepted_value' => null,
            'valuation_method' => null,
            'conditions' => null,
            'committed_date' => null,
            'due_date' => null,
            'approval_decision_id' => null,
            'acceptance_decision_id' => null,
            'revision' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('ownership_scenarios')->insert([
            'id' => $scenarioId,
            'business_id' => $business->getKey(),
            'name' => 'Scenario',
            'currency' => 'THB',
            'share_value_minor_units' => 10_000_000,
            'authorized_shares' => '100',
            'reserved_unissued_shares' => '0',
            'status' => 'draft',
            'revision' => 1,
            'created_by_membership_id' => $membership->getKey(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->expectException(QueryException::class);

        $this->expectExceptionMessage(
            'Only Accepted Contribution may feed Ownership',
        );

        DB::table(
            'ownership_scenario_contribution_sources',
        )->insert([
            'id' => (string) Str::uuid(),
            'business_id' => $business->getKey(),
            'ownership_scenario_id' => $scenarioId,
            'contribution_id' => $contributionId,
            'partner_id' => $partnerId,
            'contribution_revision' => 1,
            'currency' => 'THB',
            'accepted_value_minor_units' => 10_000_000,
            'captured_at' => now(),
        ]);
    }

    public function test_frozen_ownership_scenario_guard_exists(): void
    {
        $triggerCount = (int) DB::selectOne(
            <<<'SQL'
SELECT COUNT(*)::int AS count
FROM pg_trigger
WHERE tgname = 'ownership_scenarios_guard'
  AND NOT tgisinternal
SQL
        )->count;

        self::assertSame(
            1,
            $triggerCount,
        );
    }

    public function test_effective_register_uniqueness_index_exists(): void
    {
        $indexCount = (int) DB::selectOne(
            <<<'SQL'
SELECT COUNT(*)::int AS count
FROM pg_indexes
WHERE schemaname = 'public'
  AND indexname = 'ownership_one_effective_version_per_business'
SQL
        )->count;

        self::assertSame(
            1,
            $indexCount,
        );
    }

    public function test_database_refuses_cross_business_partner_in_scenario_position(): void
    {
        [$businessA, $membershipA] = $this->businessContext(
            'ownership-cross-a@example.test',
        );

        [$businessB] = $this->businessContext(
            'ownership-cross-b@example.test',
        );

        $partnerId = (string) Str::uuid();
        $scenarioId = (string) Str::uuid();
        $classId = (string) Str::uuid();

        DB::table('partners')->insert([
            'id' => $partnerId,
            'business_id' => $businessB->getKey(),
            'display_name' => 'Foreign Partner',
            'status' => 'prospective',
            'revision' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('ownership_scenarios')->insert([
            'id' => $scenarioId,
            'business_id' => $businessA->getKey(),
            'name' => 'Tenant Isolation Scenario',
            'currency' => 'THB',
            'share_value_minor_units' => 10_000,
            'authorized_shares' => '100',
            'reserved_unissued_shares' => '0',
            'status' => 'draft',
            'revision' => 1,
            'created_by_membership_id' => $membershipA->getKey(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('ownership_scenario_share_classes')->insert([
            'id' => $classId,
            'business_id' => $businessA->getKey(),
            'ownership_scenario_id' => $scenarioId,
            'name' => 'Ordinary',
            'voting_right_per_share' => '1',
            'profit_right_per_share' => '1',
            'transfer_allowed' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->expectException(QueryException::class);
        $this->expectExceptionMessage(
            'Ownership Scenario position Partner/Share Class mismatch',
        );

        DB::table('ownership_scenario_positions')->insert([
            'id' => (string) Str::uuid(),
            'business_id' => $businessA->getKey(),
            'ownership_scenario_id' => $scenarioId,
            'partner_id' => $partnerId,
            'share_class_id' => $classId,
            'accepted_contribution_minor_units' => 0,
            'shares_issued' => '1',
            'shares_vested' => '1',
            'voting_rights' => '1',
            'profit_rights' => '1',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function test_database_refuses_share_class_from_different_scenario(): void
    {
        [$business, $membership] = $this->businessContext(
            'ownership-class-scope@example.test',
        );

        $partnerId = (string) Str::uuid();
        $scenarioA = (string) Str::uuid();
        $scenarioB = (string) Str::uuid();
        $classB = (string) Str::uuid();

        DB::table('partners')->insert([
            'id' => $partnerId,
            'business_id' => $business->getKey(),
            'display_name' => 'Partner',
            'status' => 'prospective',
            'revision' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        foreach ([$scenarioA, $scenarioB] as $index => $scenarioId) {
            DB::table('ownership_scenarios')->insert([
                'id' => $scenarioId,
                'business_id' => $business->getKey(),
                'name' => 'Scenario '.($index + 1),
                'currency' => 'THB',
                'share_value_minor_units' => 10_000,
                'authorized_shares' => '100',
                'reserved_unissued_shares' => '0',
                'status' => 'draft',
                'revision' => 1,
                'created_by_membership_id' => $membership->getKey(),
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        DB::table('ownership_scenario_share_classes')->insert([
            'id' => $classB,
            'business_id' => $business->getKey(),
            'ownership_scenario_id' => $scenarioB,
            'name' => 'Ordinary',
            'voting_right_per_share' => '1',
            'profit_right_per_share' => '1',
            'transfer_allowed' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->expectException(QueryException::class);
        $this->expectExceptionMessage(
            'Ownership Scenario position Partner/Share Class mismatch',
        );

        DB::table('ownership_scenario_positions')->insert([
            'id' => (string) Str::uuid(),
            'business_id' => $business->getKey(),
            'ownership_scenario_id' => $scenarioA,
            'partner_id' => $partnerId,
            'share_class_id' => $classB,
            'accepted_contribution_minor_units' => 0,
            'shares_issued' => '1',
            'shares_vested' => '1',
            'voting_rights' => '1',
            'profit_rights' => '1',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function test_database_requires_governance_binding_before_register_effectivity(): void
    {
        [$business, $membership] = $this->businessContext(
            'ownership-effectivity@example.test',
        );

        $scenarioId = (string) Str::uuid();
        $registerId = (string) Str::uuid();

        DB::table('ownership_scenarios')->insert([
            'id' => $scenarioId,
            'business_id' => $business->getKey(),
            'name' => 'Frozen Scenario',
            'currency' => 'THB',
            'share_value_minor_units' => 10_000,
            'authorized_shares' => '100',
            'reserved_unissued_shares' => '0',
            'status' => 'frozen',
            'revision' => 2,
            'frozen_at' => now(),
            'created_by_membership_id' => $membership->getKey(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('ownership_registers')->insert([
            'id' => $registerId,
            'business_id' => $business->getKey(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->expectException(QueryException::class);
        $this->expectExceptionMessage(
            'Governance binding is required before Ownership Register effectivity',
        );

        DB::table('ownership_register_versions')->insert([
            'id' => (string) Str::uuid(),
            'business_id' => $business->getKey(),
            'ownership_register_id' => $registerId,
            'version_number' => 1,
            'source_ownership_scenario_id' => $scenarioId,
            'proposal_version_id' => null,
            'governance_decision_id' => null,
            'currency' => 'THB',
            'share_value_minor_units' => 10_000,
            'authorized_shares' => '100',
            'issued_shares' => '0',
            'reserved_unissued_shares' => '0',
            'available_shares' => '100',
            'status' => 'effective',
            'approved_at' => now(),
            'signed_at' => null,
            'effective_from' => now(),
            'effective_until' => null,
            'authority_snapshot_id' => null,
            'created_by_membership_id' => $membership->getKey(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function test_register_child_insert_guards_exist(): void
    {
        $count = (int) DB::selectOne(
            <<<'SQL'
SELECT COUNT(*)::int AS count
FROM pg_trigger
WHERE tgname IN (
    'ownership_register_share_classes_insert_guard',
    'ownership_register_positions_insert_guard',
    'ownership_register_contribution_sources_insert_guard'
)
AND NOT tgisinternal
SQL
        )->count;

        self::assertSame(3, $count);
    }

    public function test_database_refuses_invalid_ownership_capacity(): void
    {
        [$business, $membership] = $this->businessContext(
            'ownership-capacity@example.test',
        );

        $this->expectException(QueryException::class);

        DB::table('ownership_scenarios')->insert([
            'id' => (string) Str::uuid(),
            'business_id' => $business->getKey(),
            'name' => 'Invalid Capacity',
            'currency' => 'THB',
            'share_value_minor_units' => 10_000,
            'authorized_shares' => '100',
            'reserved_unissued_shares' => '101',
            'status' => 'draft',
            'revision' => 1,
            'created_by_membership_id' => $membership->getKey(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    /**
     * @return array{Business, Membership}
     */
    private function businessContext(string $email): array
    {
        $user = User::query()->create([
            'id' => (string) Str::uuid(),
            'email' => $email,
            'password' => 'test-password-hash',
            'password_changed_at' => now(),
        ]);

        $business = Business::query()->create([
            'id' => (string) Str::uuid(),
            'name' => 'Ownership DB Test',
            'origin_type' => 'started_through_pbr',
            'business_stage' => 'planning',
            'base_currency' => 'THB',
        ]);

        $membership = Membership::query()->create([
            'id' => (string) Str::uuid(),
            'business_id' => $business->getKey(),
            'user_id' => $user->getKey(),
            'access_status' => MembershipAccessStatus::Active,
        ]);

        return [
            $business,
            $membership,
        ];
    }
}
