<?php

declare(strict_types=1);

namespace Tests\Feature\Governance;

use App\Domain\Governance\Enums\ActionStatus;
use App\Domain\Governance\Enums\SignatureRequestStatus;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

final class F3CompletionSchemaTest extends TestCase
{
    use RefreshDatabase;

    public function test_round_one_governance_execution_schema_exists(): void
    {
        foreach ([
            'governance_delegations',
            'emergency_authority_grants',
            'governance_notifications',
        ] as $table) {
            $this->assertTrue(Schema::hasTable($table));
        }

        foreach ([
            'sent_at',
            'cancelled_by_membership_id',
            'declined_at',
            'declined_by_membership_id',
            'expired_at',
        ] as $column) {
            $this->assertTrue(Schema::hasColumn('signature_requests', $column));
        }

        $this->assertTrue(Schema::hasColumn('actions', 'blocked_reason'));
    }

    public function test_signature_and_action_states_match_master_f3_lifecycles(): void
    {
        $this->assertSame(
            [
                'draft',
                'sent',
                'partially_signed',
                'fully_signed',
                'completed',
                'expired',
                'cancelled',
                'declined',
            ],
            array_map(
                static fn (SignatureRequestStatus $status): string => $status->value,
                SignatureRequestStatus::cases(),
            ),
        );

        $this->assertContains(ActionStatus::Blocked, ActionStatus::cases());
    }

    public function test_postgresql_round_one_integrity_triggers_exist(): void
    {
        $names = collect(DB::select(
            "SELECT tgname
             FROM pg_trigger
             WHERE NOT tgisinternal
               AND tgrelid::regclass::text IN (
                   'signature_requests',
                   'signature_participants',
                   'signatures',
                   'actions',
                   'governance_delegations',
                   'emergency_authority_grants',
                   'governance_notifications'
               )
             ORDER BY tgname",
        ))->pluck('tgname')->all();

        foreach ([
            'signature_requests_validate',
            'signature_requests_protect_history',
            'signature_participants_validate',
            'signatures_validate',
            'actions_protect_history',
            'governance_delegations_protect_history',
            'emergency_authority_grants_protect_history',
            'governance_notifications_protect_history',
        ] as $expected) {
            $this->assertContains($expected, $names);
        }
    }
}
