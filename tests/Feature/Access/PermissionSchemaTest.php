<?php

namespace Tests\Feature\Access;

use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Tests\TestCase;

final class PermissionSchemaTest extends TestCase
{
    use RefreshDatabase;

    public function test_access_schema_uses_postgresql_and_expected_tables(): void
    {
        $this->assertSame('pgsql', DB::connection()->getDriverName());

        $this->assertSame(
            ['id', 'key', 'created_at', 'updated_at'],
            Schema::getColumnListing('permissions'),
        );

        $this->assertSame(
            [
                'id',
                'business_id',
                'name',
                'created_at',
                'updated_at',
            ],
            Schema::getColumnListing('permission_profiles'),
        );

        $this->assertSame(
            [
                'business_id',
                'permission_profile_id',
                'permission_id',
                'created_at',
                'updated_at',
            ],
            Schema::getColumnListing('permission_profile_permissions'),
        );

        $this->assertSame(
            [
                'business_id',
                'membership_id',
                'permission_profile_id',
                'created_at',
                'updated_at',
            ],
            Schema::getColumnListing('membership_permission_profiles'),
        );

        $this->assertSame(
            [
                'id',
                'business_id',
                'membership_id',
                'permission_id',
                'effect',
                'created_at',
                'updated_at',
            ],
            Schema::getColumnListing('permission_grants'),
        );

        $this->assertSame(
            [
                'id',
                'business_id',
                'membership_id',
                'permission_profile_id',
                'permission_id',
                'resource_type',
                'effect',
                'created_at',
                'updated_at',
            ],
            Schema::getColumnListing('access_policies'),
        );

        $this->assertSame(
            [
                'id',
                'business_id',
                'membership_id',
                'permission_profile_id',
                'permission_id',
                'resource_type',
                'resource_id',
                'effect',
                'created_at',
                'updated_at',
            ],
            Schema::getColumnListing('record_access_rules'),
        );

        $this->assertFalse(
            Schema::hasColumn('businesses', 'owner_user_id'),
        );
    }

    public function test_membership_profile_assignment_cannot_cross_businesses(): void
    {
        $userId = $this->insertUser();
        $businessA = $this->insertBusiness('Schema Business A');
        $businessB = $this->insertBusiness('Schema Business B');
        $membershipA = $this->insertMembership($userId, $businessA);
        $profileB = $this->insertProfile(
            $businessB,
            'Business B Profile',
        );

        $this->expectException(QueryException::class);

        DB::table('membership_permission_profiles')->insert([
            'business_id' => $businessB,
            'membership_id' => $membershipA,
            'permission_profile_id' => $profileB,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function test_permission_grant_rejects_unknown_effect(): void
    {
        $userId = $this->insertUser();
        $businessId = $this->insertBusiness('Grant Effect Business');
        $membershipId = $this->insertMembership(
            $userId,
            $businessId,
        );
        $permissionId = $this->insertPermission('records.view');

        $this->expectException(QueryException::class);

        DB::table('permission_grants')->insert([
            'id' => (string) Str::uuid7(),
            'business_id' => $businessId,
            'membership_id' => $membershipId,
            'permission_id' => $permissionId,
            'effect' => 'unknown',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function test_access_policy_requires_exactly_one_subject(): void
    {
        $businessId = $this->insertBusiness('Policy Subject Business');
        $permissionId = $this->insertPermission('records.view');

        $this->expectException(QueryException::class);

        DB::table('access_policies')->insert([
            'id' => (string) Str::uuid7(),
            'business_id' => $businessId,
            'membership_id' => null,
            'permission_profile_id' => null,
            'permission_id' => $permissionId,
            'resource_type' => 'risk',
            'effect' => 'allow',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function insertUser(): string
    {
        $id = (string) Str::uuid7();
        $now = now();

        DB::table('users')->insert([
            'id' => $id,
            'email' => sprintf('%s@example.com', $id),
            'password' => 'not-a-real-hash',
            'status' => 'provisioned',
            'password_changed_at' => $now,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        return $id;
    }

    private function insertBusiness(string $name): string
    {
        $id = (string) Str::uuid7();
        $now = now();

        DB::table('businesses')->insert([
            'id' => $id,
            'name' => $name,
            'origin_type' => 'started_through_pbr',
            'business_stage' => 'idea',
            'setup_phase' => 'formation',
            'workspace_status' => 'active',
            'base_currency' => 'USD',
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        return $id;
    }

    private function insertMembership(
        string $userId,
        string $businessId,
    ): string {
        $id = (string) Str::uuid7();
        $now = now();

        DB::table('memberships')->insert([
            'id' => $id,
            'user_id' => $userId,
            'business_id' => $businessId,
            'access_status' => 'active',
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        return $id;
    }

    private function insertPermission(string $key): string
    {
        $id = (string) Str::uuid7();

        DB::table('permissions')->insert([
            'id' => $id,
            'key' => $key,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $id;
    }

    private function insertProfile(
        string $businessId,
        string $name,
    ): string {
        $id = (string) Str::uuid7();

        DB::table('permission_profiles')->insert([
            'id' => $id,
            'business_id' => $businessId,
            'name' => $name,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $id;
    }
}
