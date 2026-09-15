<?php

namespace Tests\Feature\Members;

use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Tests\TestCase;

final class MembershipSchemaTest extends TestCase
{
    use RefreshDatabase;

    public function test_membership_schema_uses_postgresql_and_exact_core_columns(): void
    {
        $this->assertSame('pgsql', DB::connection()->getDriverName());

        $this->assertSame([
            'id',
            'user_id',
            'business_id',
            'access_status',
            'created_at',
            'updated_at',
        ], Schema::getColumnListing('memberships'));

        $this->assertFalse(Schema::hasColumn('businesses', 'owner_user_id'));
    }

    public function test_database_rejects_unknown_membership_access_status(): void
    {
        $userId = $this->insertUser();
        $businessId = $this->insertBusiness();

        $this->expectException(QueryException::class);

        $this->insertMembership(
            userId: $userId,
            businessId: $businessId,
            accessStatus: 'invalid',
        );
    }

    public function test_database_requires_membership_access_status_to_be_explicit(): void
    {
        $now = now();

        $this->expectException(QueryException::class);

        DB::table('memberships')->insert([
            'id' => (string) Str::uuid7(),
            'user_id' => $this->insertUser(),
            'business_id' => $this->insertBusiness(),
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }

    public function test_database_requires_valid_membership_user(): void
    {
        $this->expectException(QueryException::class);

        $this->insertMembership(
            userId: (string) Str::uuid7(),
            businessId: $this->insertBusiness(),
        );
    }

    public function test_database_requires_valid_membership_business(): void
    {
        $this->expectException(QueryException::class);

        $this->insertMembership(
            userId: $this->insertUser(),
            businessId: (string) Str::uuid7(),
        );
    }

    public function test_database_rejects_duplicate_user_business_membership(): void
    {
        $userId = $this->insertUser();
        $businessId = $this->insertBusiness();

        $this->insertMembership(
            userId: $userId,
            businessId: $businessId,
        );

        $this->expectException(QueryException::class);

        $this->insertMembership(
            userId: $userId,
            businessId: $businessId,
        );
    }

    public function test_database_restricts_deleting_referenced_user(): void
    {
        $userId = $this->insertUser();
        $businessId = $this->insertBusiness();

        $this->insertMembership(
            userId: $userId,
            businessId: $businessId,
        );

        $this->expectException(QueryException::class);

        DB::table('users')
            ->where('id', $userId)
            ->delete();
    }

    public function test_database_restricts_deleting_referenced_business(): void
    {
        $userId = $this->insertUser();
        $businessId = $this->insertBusiness();

        $this->insertMembership(
            userId: $userId,
            businessId: $businessId,
        );

        $this->expectException(QueryException::class);

        DB::table('businesses')
            ->where('id', $businessId)
            ->delete();
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

    private function insertBusiness(): string
    {
        $id = (string) Str::uuid7();
        $now = now();

        DB::table('businesses')->insert([
            'id' => $id,
            'name' => sprintf('Business %s', $id),
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
        string $accessStatus = 'active',
    ): string {
        $id = (string) Str::uuid7();
        $now = now();

        DB::table('memberships')->insert([
            'id' => $id,
            'user_id' => $userId,
            'business_id' => $businessId,
            'access_status' => $accessStatus,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        return $id;
    }
}
