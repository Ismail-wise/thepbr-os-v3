<?php

namespace Tests\Feature\Identity;

use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Tests\TestCase;

final class SecurityEventSchemaTest extends TestCase
{
    use RefreshDatabase;

    public function test_security_event_schema_uses_postgresql_and_exact_columns(): void
    {
        $this->assertSame('pgsql', DB::connection()->getDriverName());

        $this->assertTrue(Schema::hasColumns('security_events', [
            'id',
            'event_type',
            'subject_user_id',
            'actor_label',
            'source',
            'reason',
            'metadata',
            'occurred_at',
        ]));

        $this->assertFalse(Schema::hasColumn('security_events', 'updated_at'));
    }

    public function test_database_rejects_unknown_security_event_type(): void
    {
        $userId = $this->insertUser();

        $this->expectException(QueryException::class);

        DB::table('security_events')->insert([
            'id' => (string) Str::uuid7(),
            'event_type' => 'account.unknown',
            'subject_user_id' => $userId,
            'actor_label' => 'Administrator',
            'source' => 'test',
            'reason' => 'Constraint proof',
            'metadata' => json_encode([], JSON_THROW_ON_ERROR),
            'occurred_at' => now(),
        ]);
    }

    public function test_security_event_update_is_blocked_by_append_only_trigger(): void
    {
        $eventId = $this->insertSecurityEvent();

        $this->expectException(QueryException::class);

        DB::table('security_events')
            ->where('id', $eventId)
            ->update(['reason' => 'Mutated']);
    }

    public function test_security_event_delete_is_blocked_by_append_only_trigger(): void
    {
        $eventId = $this->insertSecurityEvent();

        $this->expectException(QueryException::class);

        DB::table('security_events')
            ->where('id', $eventId)
            ->delete();
    }

    private function insertUser(): string
    {
        $userId = (string) Str::uuid7();
        $now = now();

        DB::table('users')->insert([
            'id' => $userId,
            'email' => strtolower($userId).'@example.com',
            'password' => 'not-a-real-hash',
            'status' => 'provisioned',
            'password_changed_at' => $now,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        return $userId;
    }

    private function insertSecurityEvent(): string
    {
        $eventId = (string) Str::uuid7();

        DB::table('security_events')->insert([
            'id' => $eventId,
            'event_type' => 'account.provisioned',
            'subject_user_id' => $this->insertUser(),
            'actor_label' => 'Administrator',
            'source' => 'test',
            'reason' => 'Append-only proof',
            'metadata' => json_encode([
                'initial_status' => 'provisioned',
            ], JSON_THROW_ON_ERROR),
            'occurred_at' => now(),
        ]);

        return $eventId;
    }
}
