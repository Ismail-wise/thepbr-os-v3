<?php

declare(strict_types=1);

namespace Tests\Feature\Records;

use App\Application\Records\CreateProposal;
use App\Application\Records\FreezeProposalVersion;
use App\Application\Records\UpdateProposal;
use App\Domain\Access\Enums\PermissionEffect;
use App\Domain\Access\ValueObjects\Capability;
use App\Domain\Businesses\Enums\BusinessOriginType;
use App\Domain\Businesses\Enums\BusinessStage;
use App\Domain\Businesses\Enums\SetupPhase;
use App\Domain\Businesses\Enums\WorkspaceStatus;
use App\Domain\Identity\Enums\AccountStatus;
use App\Domain\Members\Enums\MembershipAccessStatus;
use App\Domain\Records\Exceptions\StaleRevision;
use App\Infrastructure\Persistence\Eloquent\Access\AccessPolicy;
use App\Infrastructure\Persistence\Eloquent\Access\Permission;
use App\Infrastructure\Persistence\Eloquent\Access\PermissionGrant;
use App\Infrastructure\Persistence\Eloquent\Businesses\Business;
use App\Infrastructure\Persistence\Eloquent\Identity\User;
use App\Infrastructure\Persistence\Eloquent\Members\Membership;
use App\Infrastructure\Persistence\Eloquent\Records\FormalRecordFamily;
use App\Infrastructure\Persistence\Eloquent\Records\FormalRecordVersion;
use App\Infrastructure\Persistence\Eloquent\Records\Proposal;
use App\Infrastructure\Persistence\Eloquent\Records\ProposalVersion;
use App\Infrastructure\Persistence\Eloquent\Records\ProposalVersionRecord;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use InvalidArgumentException;
use ReflectionClass;
use Tests\TestCase;

final class ProposalFreezeTest extends TestCase
{
    use RefreshDatabase;

    private const CAPABILITY = 'records.propose';

    public function test_proposal_creation_update_and_stale_revision_are_business_scoped(): void
    {
        [$user, $business] = $this->context();

        $proposal = $this->createProposal(
            $user,
            $business,
            str_repeat('1', 64),
        );

        $this->assertSame(
            (string) $business->getKey(),
            (string) $proposal->business_id,
        );
        $this->assertSame(1, $proposal->revision);

        $updated = $this->app->make(UpdateProposal::class)->execute(
            $user,
            $business,
            new Capability(self::CAPABILITY),
            (string) $proposal->getKey(),
            1,
            str_repeat('2', 64),
        );

        $this->assertNotNull($updated);
        $this->assertSame(2, $updated->revision);
        $this->assertSame(str_repeat('2', 64), $updated->content_hash);

        try {
            $this->app->make(UpdateProposal::class)->execute(
                $user,
                $business,
                new Capability(self::CAPABILITY),
                (string) $proposal->getKey(),
                1,
                str_repeat('3', 64),
            );

            $this->fail('Stale Proposal revision must be rejected.');
        } catch (StaleRevision) {
            $this->addToAssertionCount(1);
        }
    }

    public function test_freeze_requires_exact_revision_and_captures_exact_record_identity(): void
    {
        [$user, $business] = $this->context();

        $proposal = $this->createProposal(
            $user,
            $business,
            str_repeat('4', 64),
        );

        $record = $this->recordVersion(
            $user,
            $business,
            str_repeat('5', 64),
        );

        try {
            $this->app->make(FreezeProposalVersion::class)->execute(
                $user,
                $business,
                new Capability(self::CAPABILITY),
                (string) $proposal->getKey(),
                2,
                [(string) $record->getKey()],
            );

            $this->fail('Freeze must bind to the exact expected Proposal revision.');
        } catch (StaleRevision) {
            $this->addToAssertionCount(1);
        }

        $this->assertDatabaseCount('proposal_versions', 0);

        $frozen = $this->freeze(
            $user,
            $business,
            $proposal,
            [$record],
        );

        $this->assertSame(1, $frozen->version_number);
        $this->assertSame(1, $frozen->proposal_revision);
        $this->assertSame($proposal->content_hash, $frozen->proposal_content_hash);
        $this->assertMatchesRegularExpression(
            '/\A[a-f0-9]{64}\z/',
            $frozen->snapshot_hash,
        );

        $binding = ProposalVersionRecord::query()
            ->where('proposal_version_id', $frozen->getKey())
            ->firstOrFail();

        $this->assertSame(
            (string) $record->getKey(),
            (string) $binding->formal_record_version_id,
        );
        $this->assertSame(
            $record->content_hash,
            $binding->captured_content_hash,
        );
    }

    public function test_snapshot_hash_is_deterministic_across_input_order(): void
    {
        [$user, $business] = $this->context();

        $proposal = $this->createProposal(
            $user,
            $business,
            str_repeat('6', 64),
        );

        $recordA = $this->recordVersion(
            $user,
            $business,
            str_repeat('7', 64),
        );
        $recordB = $this->recordVersion(
            $user,
            $business,
            str_repeat('8', 64),
        );

        $first = $this->app->make(FreezeProposalVersion::class)->execute(
            $user,
            $business,
            new Capability(self::CAPABILITY),
            (string) $proposal->getKey(),
            1,
            [
                (string) $recordB->getKey(),
                (string) $recordA->getKey(),
            ],
        );

        $second = $this->app->make(FreezeProposalVersion::class)->execute(
            $user,
            $business,
            new Capability(self::CAPABILITY),
            (string) $proposal->getKey(),
            1,
            [
                (string) $recordA->getKey(),
                (string) $recordB->getKey(),
            ],
        );

        $this->assertNotNull($first);
        $this->assertNotNull($second);
        $this->assertSame($first->snapshot_hash, $second->snapshot_hash);
        $this->assertSame(1, $first->version_number);
        $this->assertSame(2, $second->version_number);
    }

    public function test_duplicate_record_bindings_are_rejected_without_partial_snapshot(): void
    {
        [$user, $business] = $this->context();

        $proposal = $this->createProposal(
            $user,
            $business,
            str_repeat('9', 64),
        );

        $record = $this->recordVersion(
            $user,
            $business,
            str_repeat('a', 64),
        );

        try {
            $this->app->make(FreezeProposalVersion::class)->execute(
                $user,
                $business,
                new Capability(self::CAPABILITY),
                (string) $proposal->getKey(),
                1,
                [
                    (string) $record->getKey(),
                    (string) $record->getKey(),
                ],
            );

            $this->fail('Duplicate bindings must be rejected.');
        } catch (InvalidArgumentException) {
            $this->addToAssertionCount(1);
        }

        $this->assertDatabaseCount('proposal_versions', 0);
        $this->assertDatabaseCount('proposal_version_records', 0);
    }

    public function test_cross_business_record_binding_fails_closed_and_rolls_back_atomically(): void
    {
        [$user, $businessA] = $this->context();

        $proposal = $this->createProposal(
            $user,
            $businessA,
            str_repeat('b', 64),
        );

        $recordA = $this->recordVersion(
            $user,
            $businessA,
            str_repeat('c', 64),
        );

        $businessB = $this->business('Foreign Business');

        $recordB = $this->recordVersion(
            $user,
            $businessB,
            str_repeat('d', 64),
        );

        $result = $this->app->make(FreezeProposalVersion::class)->execute(
            $user,
            $businessA,
            new Capability(self::CAPABILITY),
            (string) $proposal->getKey(),
            1,
            [
                (string) $recordA->getKey(),
                (string) $recordB->getKey(),
            ],
        );

        $this->assertNull($result);
        $this->assertDatabaseCount('proposal_versions', 0);
        $this->assertDatabaseCount('proposal_version_records', 0);
    }

    public function test_cross_business_proposal_access_fails_closed(): void
    {
        [$user, $businessA] = $this->context();
        $businessB = $this->business('Foreign Proposal Business');

        $foreign = Proposal::query()->create([
            'business_id' => $businessB->getKey(),
            'revision' => 1,
            'content_hash' => str_repeat('e', 64),
            'created_by_user_id' => $user->getKey(),
            'last_changed_by_user_id' => $user->getKey(),
        ]);

        $this->assertNull(
            $this->app->make(UpdateProposal::class)->execute(
                $user,
                $businessA,
                new Capability(self::CAPABILITY),
                (string) $foreign->getKey(),
                1,
                str_repeat('f', 64),
            ),
        );

        $this->assertNull(
            $this->app->make(FreezeProposalVersion::class)->execute(
                $user,
                $businessA,
                new Capability(self::CAPABILITY),
                (string) $foreign->getKey(),
                1,
                [],
            ),
        );

        $this->assertDatabaseCount('proposal_versions', 0);
    }

    public function test_frozen_proposal_version_is_database_immutable(): void
    {
        [$user, $business] = $this->context();

        $proposal = $this->createProposal(
            $user,
            $business,
            str_repeat('1', 64),
        );

        $frozen = $this->freeze(
            $user,
            $business,
            $proposal,
            [],
        );

        $originalHash = $frozen->snapshot_hash;

        $this->assertDatabaseRejects(
            fn () => DB::table('proposal_versions')
                ->where('id', $frozen->getKey())
                ->update(['snapshot_hash' => str_repeat('2', 64)]),
        );

        $this->assertDatabaseRejects(
            fn () => DB::table('proposal_versions')
                ->where('id', $frozen->getKey())
                ->delete(),
        );

        $this->assertSame(
            $originalHash,
            $frozen->fresh()->snapshot_hash,
        );
    }

    public function test_proposal_version_record_is_database_immutable(): void
    {
        [$user, $business] = $this->context();

        $proposal = $this->createProposal(
            $user,
            $business,
            str_repeat('3', 64),
        );

        $record = $this->recordVersion(
            $user,
            $business,
            str_repeat('4', 64),
        );

        $frozen = $this->freeze(
            $user,
            $business,
            $proposal,
            [$record],
        );

        $binding = ProposalVersionRecord::query()
            ->where('proposal_version_id', $frozen->getKey())
            ->firstOrFail();

        $captured = $binding->captured_content_hash;

        $this->assertDatabaseRejects(
            fn () => DB::table('proposal_version_records')
                ->where('id', $binding->getKey())
                ->update([
                    'captured_content_hash' => str_repeat('5', 64),
                ]),
        );

        $this->assertDatabaseRejects(
            fn () => DB::table('proposal_version_records')
                ->where('id', $binding->getKey())
                ->delete(),
        );

        $this->assertSame(
            $captured,
            $binding->fresh()->captured_content_hash,
        );
    }

    public function test_proposal_can_evolve_and_second_freeze_preserves_first_snapshot(): void
    {
        [$user, $business] = $this->context();

        $proposal = $this->createProposal(
            $user,
            $business,
            str_repeat('6', 64),
        );

        $record = $this->recordVersion(
            $user,
            $business,
            str_repeat('7', 64),
        );

        $first = $this->freeze(
            $user,
            $business,
            $proposal,
            [$record],
        );

        $firstSnapshot = [
            'id' => (string) $first->getKey(),
            'proposal_revision' => $first->proposal_revision,
            'proposal_content_hash' => $first->proposal_content_hash,
            'snapshot_hash' => $first->snapshot_hash,
        ];

        $updated = $this->app->make(UpdateProposal::class)->execute(
            $user,
            $business,
            new Capability(self::CAPABILITY),
            (string) $proposal->getKey(),
            1,
            str_repeat('8', 64),
        );

        $this->assertNotNull($updated);
        $this->assertSame(2, $updated->revision);

        $second = $this->freeze(
            $user,
            $business,
            $updated,
            [$record],
        );

        $this->assertSame(2, $second->version_number);
        $this->assertSame(2, $second->proposal_revision);
        $this->assertNotSame($first->snapshot_hash, $second->snapshot_hash);

        $firstFresh = $first->fresh();

        $this->assertSame($firstSnapshot['id'], (string) $firstFresh->getKey());
        $this->assertSame(
            $firstSnapshot['proposal_revision'],
            $firstFresh->proposal_revision,
        );
        $this->assertSame(
            $firstSnapshot['proposal_content_hash'],
            $firstFresh->proposal_content_hash,
        );
        $this->assertSame(
            $firstSnapshot['snapshot_hash'],
            $firstFresh->snapshot_hash,
        );
    }

    public function test_later_record_mutation_does_not_rewrite_frozen_binding_identity(): void
    {
        [$user, $business] = $this->context();

        $proposal = $this->createProposal(
            $user,
            $business,
            str_repeat('9', 64),
        );

        $record = $this->recordVersion(
            $user,
            $business,
            str_repeat('a', 64),
        );

        $frozen = $this->freeze(
            $user,
            $business,
            $proposal,
            [$record],
        );

        $binding = ProposalVersionRecord::query()
            ->where('proposal_version_id', $frozen->getKey())
            ->firstOrFail();

        $capturedHash = $binding->captured_content_hash;
        $snapshotHash = $frozen->snapshot_hash;

        $record->content_hash = str_repeat('b', 64);
        $record->save();

        $this->assertSame(
            $capturedHash,
            $binding->fresh()->captured_content_hash,
        );
        $this->assertSame(
            $snapshotHash,
            $frozen->fresh()->snapshot_hash,
        );
        $this->assertNotSame(
            $record->fresh()->content_hash,
            $binding->fresh()->captured_content_hash,
        );
    }

    public function test_database_rejects_cross_business_proposal_version_binding(): void
    {
        [$user, $businessA] = $this->context();
        $businessB = $this->business('Foreign Snapshot Business');

        $proposalB = Proposal::query()->create([
            'business_id' => $businessB->getKey(),
            'revision' => 1,
            'content_hash' => str_repeat('c', 64),
            'created_by_user_id' => $user->getKey(),
            'last_changed_by_user_id' => $user->getKey(),
        ]);

        $foreignVersion = ProposalVersion::query()->create([
            'business_id' => $businessB->getKey(),
            'proposal_id' => $proposalB->getKey(),
            'version_number' => 1,
            'proposal_revision' => 1,
            'proposal_content_hash' => $proposalB->content_hash,
            'snapshot_hash' => str_repeat('d', 64),
            'frozen_by_user_id' => $user->getKey(),
            'frozen_at' => now(),
        ]);

        $recordA = $this->recordVersion(
            $user,
            $businessA,
            str_repeat('e', 64),
        );

        $this->assertDatabaseRejects(
            fn () => ProposalVersionRecord::query()->create([
                'business_id' => $businessA->getKey(),
                'proposal_version_id' => $foreignVersion->getKey(),
                'formal_record_version_id' => $recordA->getKey(),
                'captured_content_hash' => $recordA->content_hash,
            ]),
        );
    }

    public function test_proposal_schema_enforces_revision_hash_and_immutable_triggers(): void
    {
        [$user, $business] = $this->context();

        $this->assertDatabaseRejects(
            fn () => DB::table('proposals')->insert([
                'id' => (string) Str::uuid7(),
                'business_id' => $business->getKey(),
                'revision' => 0,
                'content_hash' => str_repeat('f', 64),
                'created_by_user_id' => $user->getKey(),
                'last_changed_by_user_id' => $user->getKey(),
                'created_at' => now(),
                'updated_at' => now(),
            ]),
        );

        $triggerNames = collect(
            DB::select(
                "SELECT tgname
                 FROM pg_trigger
                 WHERE NOT tgisinternal
                   AND tgrelid::regclass::text IN (
                       'proposal_versions',
                       'proposal_version_records'
                   )
                 ORDER BY tgname",
            ),
        )->pluck('tgname')->all();

        foreach ([
            'proposal_versions_validate_snapshot',
            'proposal_versions_immutable',
            'proposal_version_records_validate',
            'proposal_version_records_immutable',
        ] as $expected) {
            $this->assertContains($expected, $triggerNames);
        }
    }

    public function test_freeze_has_no_governance_or_effectivity_semantics(): void
    {
        foreach ([
            'approved_at',
            'signed_at',
            'effective_from',
            'effective_until',
            'decision_id',
            'approval_id',
            'signature_id',
            'authority_snapshot_id',
        ] as $forbiddenColumn) {
            $this->assertFalse(
                Schema::hasColumn(
                    'proposal_versions',
                    $forbiddenColumn,
                ),
            );
        }

        $source = file_get_contents(
            (new ReflectionClass(FreezeProposalVersion::class))
                ->getFileName(),
        );

        $this->assertIsString($source);

        foreach ([
            'Decision::',
            'Approval::',
            'Vote::',
            'Signature::',
            'GovernanceAuthority::',
            'AuthoritySnapshot::',
            'applyScenario',
            'applyProposal',
            'makeEffective',
        ] as $forbidden) {
            $this->assertStringNotContainsString($forbidden, $source);
        }
    }

    /**
     * @return array{User, Business}
     */
    private function context(): array
    {
        $user = User::query()->create([
            'email' => 'proposal-'.Str::uuid7().'@example.test',
            'password' => Hash::make('test-password'),
            'status' => AccountStatus::Active->value,
            'password_changed_at' => now(),
        ]);

        $business = $this->business('Proposal Business');

        $membership = Membership::query()->create([
            'user_id' => $user->getKey(),
            'business_id' => $business->getKey(),
            'access_status' => MembershipAccessStatus::Active->value,
        ]);

        $permission = Permission::query()->create([
            'key' => self::CAPABILITY,
        ]);

        PermissionGrant::query()->create([
            'business_id' => $business->getKey(),
            'membership_id' => $membership->getKey(),
            'permission_id' => $permission->getKey(),
            'effect' => PermissionEffect::Allow->value,
        ]);

        foreach ([Proposal::class, FormalRecordVersion::class] as $type) {
            AccessPolicy::query()->create([
                'business_id' => $business->getKey(),
                'membership_id' => $membership->getKey(),
                'permission_profile_id' => null,
                'permission_id' => $permission->getKey(),
                'resource_type' => $type,
                'effect' => PermissionEffect::Allow->value,
            ]);
        }

        return [$user, $business];
    }

    private function business(string $name): Business
    {
        return Business::query()->create([
            'name' => $name.' '.Str::uuid7(),
            'origin_type' => BusinessOriginType::StartedThroughPbr->value,
            'business_stage' => BusinessStage::Idea->value,
            'setup_phase' => SetupPhase::Formation->value,
            'workspace_status' => WorkspaceStatus::Active->value,
            'base_currency' => 'USD',
        ]);
    }

    private function createProposal(
        User $user,
        Business $business,
        string $hash,
    ): Proposal {
        $proposal = $this->app->make(CreateProposal::class)->execute(
            $user,
            $business,
            new Capability(self::CAPABILITY),
            $hash,
        );

        $this->assertNotNull($proposal);

        return $proposal;
    }

    private function recordVersion(
        User $user,
        Business $business,
        string $hash,
    ): FormalRecordVersion {
        $family = FormalRecordFamily::query()->create([
            'business_id' => $business->getKey(),
            'record_type' => 'proposal-test-record',
            'subject_type' => 'fixture',
            'subject_id' => (string) Str::uuid7(),
        ]);

        return FormalRecordVersion::query()->create([
            'business_id' => $business->getKey(),
            'formal_record_family_id' => $family->getKey(),
            'version_number' => 1,
            'predecessor_version_id' => null,
            'revision' => 1,
            'change_summary' => 'Proposal binding fixture',
            'created_by_user_id' => $user->getKey(),
            'last_changed_by_user_id' => $user->getKey(),
            'effective_from' => null,
            'effective_until' => null,
            'review_due_at' => null,
            'content_hash' => $hash,
            'frozen_at' => null,
        ]);
    }

    /**
     * @param  array<FormalRecordVersion>  $records
     */
    private function freeze(
        User $user,
        Business $business,
        Proposal $proposal,
        array $records,
    ): ProposalVersion {
        $frozen = $this->app->make(FreezeProposalVersion::class)->execute(
            $user,
            $business,
            new Capability(self::CAPABILITY),
            (string) $proposal->getKey(),
            (int) $proposal->revision,
            array_map(
                static fn (FormalRecordVersion $record): string => (string) $record->getKey(),
                $records,
            ),
        );

        $this->assertNotNull($frozen);

        return $frozen;
    }

    private function assertDatabaseRejects(callable $callback): void
    {
        DB::beginTransaction();

        try {
            $callback();
            DB::rollBack();
            $this->fail('Expected PostgreSQL to reject the invalid write.');
        } catch (QueryException) {
            if (DB::transactionLevel() > 0) {
                DB::rollBack();
            }

            $this->addToAssertionCount(1);
        }
    }
}
