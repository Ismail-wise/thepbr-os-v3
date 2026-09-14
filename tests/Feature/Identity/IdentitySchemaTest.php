<?php

namespace Tests\Feature\Identity;

use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Tests\TestCase;

final class IdentitySchemaTest extends TestCase
{
    use RefreshDatabase;

    public function test_identity_schema_uses_postgresql_and_exact_core_columns(): void
    {
        $this->assertSame('pgsql', DB::connection()->getDriverName());

        $this->assertTrue(Schema::hasColumns('users', [
            'id',
            'email',
            'password',
            'status',
            'password_changed_at',
            'created_at',
            'updated_at',
        ]));

        $this->assertTrue(Schema::hasColumns('user_profiles', [
            'user_id',
            'display_name',
            'language_mode',
            'timezone',
            'created_at',
            'updated_at',
        ]));
    }

    public function test_database_rejects_non_canonical_email(): void
    {
        $now = now();

        $this->expectException(QueryException::class);

        DB::table('users')->insert([
            'id' => (string) Str::uuid7(),
            'email' => 'Person@EXAMPLE.COM',
            'password' => 'not-a-real-hash',
            'status' => 'provisioned',
            'password_changed_at' => $now,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }

    public function test_database_rejects_unknown_account_status(): void
    {
        $now = now();

        $this->expectException(QueryException::class);

        DB::table('users')->insert([
            'id' => (string) Str::uuid7(),
            'email' => 'person@example.com',
            'password' => 'not-a-real-hash',
            'status' => 'owner',
            'password_changed_at' => $now,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }

    public function test_database_rejects_unknown_language_mode(): void
    {
        $now = now();
        $userId = (string) Str::uuid7();

        DB::table('users')->insert([
            'id' => $userId,
            'email' => 'person@example.com',
            'password' => 'not-a-real-hash',
            'status' => 'provisioned',
            'password_changed_at' => $now,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        $this->expectException(QueryException::class);

        DB::table('user_profiles')->insert([
            'user_id' => $userId,
            'display_name' => 'Person',
            'language_mode' => 'invalid',
            'timezone' => 'UTC',
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }
}
