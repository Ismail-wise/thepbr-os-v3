<?php

declare(strict_types=1);

namespace Tests\Feature\Governance;

use App\Application\Governance\GovernanceCharterWorkflow;
use App\Application\Records\CreateAmendedDraftVersion;
use App\Application\Records\CreateDraftRecordVersion;
use App\Application\Records\CreateFormalRecordFamily;
use App\Application\Records\CreateProposal;
use App\Application\Records\FreezeProposalVersion;
use App\Application\Records\SubmitRecordVersionForReview;
use App\Application\Records\TransitionFormalRecordVersion;
use App\Infrastructure\Persistence\Eloquent\Businesses\Business;
use App\Infrastructure\Persistence\Eloquent\Identity\User;
use App\Infrastructure\Persistence\Eloquent\Members\Membership;
use App\Infrastructure\Persistence\Eloquent\Records\FormalRecordFamily;
use App\Infrastructure\Persistence\Eloquent\Records\FormalRecordVersion;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use ReflectionClass;
use Tests\TestCase;

final class F6AGovernanceCharterTest extends TestCase
{
    use RefreshDatabase;

    public function test_f6a_schema_and_database_guards_exist(): void
    {
        foreach ([
            'governance_charter_versions',
            'governance_charter_rules',
            'governance_charter_rule_actors',
            'governance_authority_change_submissions',
        ] as $table) {
            self::assertTrue(
                DB::getSchemaBuilder()->hasTable($table),
                $table.' must exist.',
            );
        }

        foreach ([
            'source_kind',
            'meeting_required',
            'record_required',
        ] as $column) {
            self::assertTrue(
                DB::getSchemaBuilder()->hasColumn(
                    'authority_snapshots',
                    $column,
                ),
            );
        }

        foreach ([
            'authority_snapshots_validate',
            'decision_participants_validate',
            'emergency_authority_grants_protect_history',
            'gov_charter_versions_mutable',
            'gov_charter_rules_mutable',
            'gov_charter_actors_mutable',
            'gov_auth_changes_validate',
            'gov_auth_changes_protect',
        ] as $trigger) {
            $count = (int) DB::selectOne(
                <<<'SQL'
SELECT COUNT(*)::int AS count
FROM pg_trigger
WHERE tgname = ?
  AND NOT tgisinternal
SQL,
                [$trigger],
            )->count;

            self::assertSame(1, $count, $trigger.' must exist once.');
        }
    }

    public function test_charter_orchestrator_reuses_f2_record_and_proposal_foundations(): void
    {
        $constructor = (new ReflectionClass(
            GovernanceCharterWorkflow::class,
        ))->getConstructor();

        self::assertNotNull($constructor);

        $types = [];

        foreach ($constructor->getParameters() as $parameter) {
            $type = $parameter->getType();

            self::assertNotNull($type);
            $types[] = $type->getName();
        }

        foreach ([
            CreateFormalRecordFamily::class,
            CreateDraftRecordVersion::class,
            CreateAmendedDraftVersion::class,
            SubmitRecordVersionForReview::class,
            TransitionFormalRecordVersion::class,
            CreateProposal::class,
            FreezeProposalVersion::class,
        ] as $required) {
            self::assertContains($required, $types);
        }

        $source = file_get_contents(
            (new ReflectionClass(
                GovernanceCharterWorkflow::class,
            ))->getFileName(),
        );

        self::assertIsString($source);
        self::assertStringNotContainsString(
            "DB::table('approvals')->insert",
            $source,
        );
        self::assertStringNotContainsString(
            "DB::table('votes')->insert",
            $source,
        );
        self::assertStringNotContainsString(
            "DB::table('decisions')->insert",
            $source,
        );
    }

    public function test_frozen_governance_charter_content_is_immutable(): void
    {
        [$business, $user, $membership] = $this->identity(
            'f6-charter-immutable',
        );

        $family = FormalRecordFamily::query()->create([
            'business_id' => $business->getKey(),
            'record_type' => 'governance_charter',
            'subject_type' => 'business',
            'subject_id' => (string) $business->getKey(),
        ]);

        $version = FormalRecordVersion::query()->create([
            'business_id' => $business->getKey(),
            'formal_record_family_id' => $family->getKey(),
            'version_number' => 1,
            'predecessor_version_id' => null,
            'revision' => 1,
            'change_summary' => 'Initial Charter',
            'created_by_user_id' => $user->getKey(),
            'last_changed_by_user_id' => $user->getKey(),
            'effective_from' => now()->subMinute(),
            'effective_until' => null,
            'review_due_at' => null,
            'content_hash' => str_repeat('a', 64),
            'frozen_at' => null,
        ]);

        DB::table('governance_charter_versions')->insert([
            'id' => (string) Str::uuid7(),
            'business_id' => $business->getKey(),
            'formal_record_version_id' => $version->getKey(),
            'governance_owner_membership_id' => $membership->getKey(),
            'voting_basis' => 'One eligible participant, one vote',
            'default_approval_rule' => 'Exact matrix rule',
            'meeting_frequency' => 'Monthly',
            'default_quorum_count' => 1,
            'minutes_owner_membership_id' => $membership->getKey(),
            'conflict_of_interest_rule' => 'Disclose and recuse.',
            'deadlock_rule' => 'Escalate under approved process.',
            'remote_voting_allowed' => true,
            'written_resolution_allowed' => true,
            'created_at' => now(),
        ]);

        $ruleId = (string) Str::uuid7();

        DB::table('governance_charter_rules')->insert([
            'id' => $ruleId,
            'business_id' => $business->getKey(),
            'formal_record_version_id' => $version->getKey(),
            'sequence' => 1,
            'decision_type' => 'general_management',
            'category' => 'management',
            'decision_method' => 'approval',
            'required_approvals' => 1,
            'required_votes' => 0,
            'quorum_count' => 1,
            'signature_required' => false,
            'reserved_matter' => false,
            'meeting_required' => false,
            'record_required' => true,
            'amount_min' => null,
            'amount_max' => null,
            'created_at' => now(),
        ]);

        DB::table('governance_charter_rule_actors')->insert([
            'id' => (string) Str::uuid7(),
            'business_id' => $business->getKey(),
            'governance_charter_rule_id' => $ruleId,
            'membership_id' => $membership->getKey(),
            'capacity' => 'Decision Owner',
            'is_decision_owner' => true,
            'is_consulted' => false,
            'can_approve' => true,
            'can_vote' => false,
            'can_sign' => false,
            'created_at' => now(),
        ]);

        $version->frozen_at = now();
        $version->save();

        $this->assertDatabaseRejects(function () use ($ruleId): void {
            DB::table('governance_charter_rules')
                ->where('id', $ruleId)
                ->update(['required_approvals' => 2]);
        });

        self::assertSame(
            1,
            (int) DB::table('governance_charter_rules')
                ->where('id', $ruleId)
                ->value('required_approvals'),
        );
    }

    /**
     * @return array{Business,User,Membership}
     */
    private function identity(string $prefix): array
    {
        $business = Business::query()->create([
            'name' => $prefix.' Business',
            'origin_type' => 'started_through_pbr',
            'business_stage' => 'planning',
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

        return [$business, $user, $membership];
    }

    private function assertDatabaseRejects(callable $callback): void
    {
        DB::beginTransaction();

        try {
            $callback();
            DB::rollBack();
            $this->fail('Expected PostgreSQL to reject immutable Charter mutation.');
        } catch (QueryException) {
            if (DB::transactionLevel() > 0) {
                DB::rollBack();
            }

            $this->addToAssertionCount(1);
        }
    }
}
