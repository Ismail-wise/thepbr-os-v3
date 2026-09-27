<?php

declare(strict_types=1);

namespace Tests\Feature\Governance;

use App\Application\Governance\GovernanceMeetingWorkflow;
use App\Application\Governance\OpenGovernanceDecision;
use App\Application\Governance\ResolveGovernanceAuthority;
use App\Domain\Access\CapabilityCatalog;
use App\Domain\Governance\Enums\DecisionMethod;
use App\Domain\Governance\ValueObjects\DecisionType;
use App\Infrastructure\Persistence\Eloquent\Access\AccessPolicy;
use App\Infrastructure\Persistence\Eloquent\Access\Permission;
use App\Infrastructure\Persistence\Eloquent\Access\PermissionGrant;
use App\Infrastructure\Persistence\Eloquent\Businesses\Business;
use App\Infrastructure\Persistence\Eloquent\Governance\AuthoritySnapshot;
use App\Infrastructure\Persistence\Eloquent\Governance\Decision;
use App\Infrastructure\Persistence\Eloquent\Governance\FormationAuthorityEstablishment;
use App\Infrastructure\Persistence\Eloquent\Governance\FormationAuthorityPolicyActor;
use App\Infrastructure\Persistence\Eloquent\Governance\FormationAuthorityPolicyRule;
use App\Infrastructure\Persistence\Eloquent\Governance\ProposalReview;
use App\Infrastructure\Persistence\Eloquent\Identity\User;
use App\Infrastructure\Persistence\Eloquent\Members\Membership;
use App\Infrastructure\Persistence\Eloquent\Records\FormalRecordFamily;
use App\Infrastructure\Persistence\Eloquent\Records\FormalRecordVersion;
use App\Infrastructure\Persistence\Eloquent\Records\Proposal;
use App\Infrastructure\Persistence\Eloquent\Records\ProposalVersion;
use App\Infrastructure\Persistence\Eloquent\Records\RecordFamilyEffectiveHead;
use App\Infrastructure\Persistence\Eloquent\Records\RecordVersionStateTransition;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use RuntimeException;
use Tests\TestCase;

final class F6AAuthorityResolutionTest extends TestCase
{
    use RefreshDatabase;

    public function test_effective_governance_charter_replaces_temporary_formation_authority(): void
    {
        $business = $this->business('F6 Authority Precedence');
        [$formationUser, $formationMember] = $this->member(
            $business,
            'formation-authority',
        );
        [, $charterMember] = $this->member(
            $business,
            'charter-authority',
        );

        $this->seedFormationAuthority(
            $business,
            $formationUser,
            $formationMember,
            'centralized_test',
        );

        $resolver = $this->app->make(
            ResolveGovernanceAuthority::class,
        );

        $formation = $resolver->resolve(
            $business,
            new DecisionType('centralized_test'),
        );

        self::assertNotNull($formation);
        self::assertSame(
            'formation_authority',
            $formation['source_kind'],
        );
        self::assertSame(
            [(string) $formationMember->getKey()],
            $formation['actors']
                ->pluck('membershipId')
                ->values()
                ->all(),
        );

        $charterV1 = $this->seedEffectiveCharter(
            $business,
            $formationUser,
            $charterMember,
            'centralized_test',
            1,
            null,
            1,
        );

        $governance = $resolver->resolve(
            $business,
            new DecisionType('centralized_test'),
        );

        self::assertNotNull($governance);
        self::assertSame(
            'governance_charter',
            $governance['source_kind'],
        );
        self::assertSame(
            (string) $charterV1->getKey(),
            (string) $governance['source_version']->getKey(),
        );
        self::assertSame(
            [(string) $charterMember->getKey()],
            $governance['actors']
                ->pluck('membershipId')
                ->values()
                ->all(),
        );
        self::assertNotContains(
            (string) $formationMember->getKey(),
            $governance['actors']
                ->pluck('membershipId')
                ->all(),
            'System/formation access must not become current Governance authority after Charter effectivity.',
        );
    }

    public function test_meeting_required_rule_fails_closed_until_a_same_authority_quorum_meeting_is_held(): void
    {
        $business = $this->business('F6 Meeting Required');
        [$user, $member] = $this->member(
            $business,
            'meeting-required',
        );

        $this->grant(
            $business,
            $member,
            CapabilityCatalog::GOVERNANCE_RECORDS_MANAGE,
            [
                ProposalVersion::class,
                Decision::class,
            ],
        );

        $charter = $this->seedEffectiveCharter(
            $business,
            $user,
            $member,
            'meeting_required_test',
            1,
            null,
            1,
            true,
        );

        $proposalVersion = $this->reviewedProposal(
            $business,
            $user,
            $member,
        );

        try {
            $this->app->make(
                OpenGovernanceDecision::class,
            )->execute(
                $user,
                $business,
                (string) $proposalVersion->getKey(),
                new DecisionType('meeting_required_test'),
            );

            $this->fail(
                'Meeting-required Governance rule must fail closed without a qualifying Held Meeting.',
            );
        } catch (RuntimeException $exception) {
            self::assertStringContainsString(
                'requires a held, quorum-met Meeting',
                $exception->getMessage(),
            );
        }

        $meetingWorkflow = $this->app->make(
            GovernanceMeetingWorkflow::class,
        );

        $meetingId = $meetingWorkflow->schedule(
            $user,
            $business,
            'Reserved Matter Meeting',
            now()->subMinute(),
            1,
            (string) $member->getKey(),
            (string) $member->getKey(),
            'Consider meeting_required_test.',
            [(string) $member->getKey()],
            now()->subMinutes(2),
        );

        self::assertNotNull($meetingId);

        self::assertTrue(
            $meetingWorkflow->hold(
                $user,
                $business,
                $meetingId,
                'Quorum was present and the agenda was considered.',
                [(string) $member->getKey() => 'present'],
            ),
        );

        $decision = $this->app->make(
            OpenGovernanceDecision::class,
        )->execute(
            $user,
            $business,
            (string) $proposalVersion->getKey(),
            new DecisionType('meeting_required_test'),
            null,
            $meetingId,
        );

        self::assertInstanceOf(Decision::class, $decision);
        self::assertSame(
            $meetingId,
            (string) $decision->meeting_id,
        );

        $snapshot = AuthoritySnapshot::query()
            ->whereKey($decision->authority_snapshot_id)
            ->sole();

        self::assertTrue($snapshot->meeting_required);
        self::assertSame(
            'governance_charter',
            (string) $snapshot->source_kind,
        );
        self::assertSame(
            (string) $charter->getKey(),
            (string) $snapshot->source_formal_record_version_id,
        );
    }

    public function test_authority_snapshot_keeps_exact_historical_charter_after_new_version_becomes_effective(): void
    {
        $business = $this->business('F6 Snapshot History');
        [$user, $memberV1] = $this->member(
            $business,
            'charter-v1',
        );
        [, $memberV2] = $this->member(
            $business,
            'charter-v2',
        );

        $charterV1 = $this->seedEffectiveCharter(
            $business,
            $user,
            $memberV1,
            'history_test',
            1,
            null,
            1,
        );

        $proposal = Proposal::query()->create([
            'business_id' => $business->getKey(),
            'revision' => 1,
            'content_hash' => str_repeat('3', 64),
            'created_by_user_id' => $user->getKey(),
            'last_changed_by_user_id' => $user->getKey(),
        ]);

        $proposalVersion = ProposalVersion::query()->create([
            'business_id' => $business->getKey(),
            'proposal_id' => $proposal->getKey(),
            'version_number' => 1,
            'proposal_revision' => 1,
            'proposal_content_hash' => $proposal->content_hash,
            'snapshot_hash' => str_repeat('4', 64),
            'frozen_by_user_id' => $user->getKey(),
            'frozen_at' => now(),
        ]);

        $snapshot = AuthoritySnapshot::query()->create([
            'business_id' => $business->getKey(),
            'proposal_version_id' => $proposalVersion->getKey(),
            'source_formal_record_version_id' => $charterV1->getKey(),
            'source_rule_sequence' => 1,
            'source_content_hash' => $charterV1->content_hash,
            'source_kind' => 'governance_charter',
            'decision_type' => 'history_test',
            'decision_method' => DecisionMethod::Approval->value,
            'required_approvals' => 1,
            'required_votes' => 0,
            'quorum_count' => 1,
            'signature_required' => false,
            'reserved_matter' => false,
            'meeting_required' => false,
            'record_required' => true,
            'amount_min' => null,
            'amount_max' => null,
            'snapshot_hash' => str_repeat('5', 64),
            'captured_by_membership_id' => $memberV1->getKey(),
            'captured_at' => now(),
        ]);

        $charterV2 = $this->seedEffectiveCharter(
            $business,
            $user,
            $memberV2,
            'history_test',
            2,
            $charterV1,
            1,
        );

        self::assertSame(
            (string) $charterV1->getKey(),
            (string) $snapshot->fresh()
                ->source_formal_record_version_id,
        );
        self::assertSame(
            str_repeat('1', 64),
            (string) $snapshot->fresh()->source_content_hash,
        );

        $resolver = $this->app->make(
            ResolveGovernanceAuthority::class,
        );
        $current = $resolver->resolve(
            $business,
            new DecisionType('history_test'),
        );

        self::assertNotNull($current);
        self::assertSame(
            (string) $charterV2->getKey(),
            (string) $current['source_version']->getKey(),
        );
        self::assertSame(
            [(string) $memberV2->getKey()],
            $current['actors']->pluck('membershipId')->all(),
        );

        $this->assertDatabaseRejects(function () use ($snapshot): void {
            DB::table('authority_snapshots')
                ->where('id', $snapshot->getKey())
                ->update(['required_approvals' => 2]);
        });
    }

    private function seedFormationAuthority(
        Business $business,
        User $user,
        Membership $member,
        string $decisionType,
    ): FormalRecordVersion {
        $family = FormalRecordFamily::query()->create([
            'business_id' => $business->getKey(),
            'record_type' => 'formation_authority_policy',
            'subject_type' => 'business',
            'subject_id' => (string) $business->getKey(),
        ]);

        $version = $this->recordVersion(
            $business,
            $family,
            $user,
            1,
            null,
            str_repeat('0', 64),
        );

        $rule = FormationAuthorityPolicyRule::query()->create([
            'business_id' => $business->getKey(),
            'formal_record_version_id' => $version->getKey(),
            'sequence' => 1,
            'decision_type' => $decisionType,
            'decision_method' => DecisionMethod::Approval->value,
            'required_approvals' => 1,
            'required_votes' => 0,
            'quorum_count' => 1,
            'signature_required' => false,
            'reserved_matter' => false,
            'amount_min' => null,
            'amount_max' => null,
        ]);

        FormationAuthorityPolicyActor::query()->create([
            'business_id' => $business->getKey(),
            'formation_authority_policy_rule_id' => $rule->getKey(),
            'membership_id' => $member->getKey(),
            'capacity' => 'Temporary Formation Approver',
            'can_approve' => true,
            'can_vote' => false,
            'can_sign' => false,
        ]);

        $version->frozen_at = now();
        $version->save();

        $this->state(
            $version,
            $user,
            1,
            null,
            'draft',
        );
        $this->state(
            $version,
            $user,
            2,
            'draft',
            'ready_for_review',
        );

        FormationAuthorityEstablishment::query()->create([
            'business_id' => $business->getKey(),
            'formal_record_version_id' => $version->getKey(),
            'established_by_membership_id' => $member->getKey(),
            'establishment_hash' => $version->content_hash,
            'established_at' => now(),
        ]);

        return $version->fresh();
    }

    private function seedEffectiveCharter(
        Business $business,
        User $user,
        Membership $actor,
        string $decisionType,
        int $versionNumber,
        ?FormalRecordVersion $predecessor,
        int $requiredApprovals,
        bool $meetingRequired = false,
    ): FormalRecordVersion {
        $family = FormalRecordFamily::query()
            ->where('business_id', $business->getKey())
            ->where('record_type', 'governance_charter')
            ->first();

        if ($family === null) {
            $family = FormalRecordFamily::query()->create([
                'business_id' => $business->getKey(),
                'record_type' => 'governance_charter',
                'subject_type' => 'business',
                'subject_id' => (string) $business->getKey(),
            ]);
        }

        $hash = $versionNumber === 1
            ? str_repeat('1', 64)
            : str_repeat('2', 64);

        $version = $this->recordVersion(
            $business,
            $family,
            $user,
            $versionNumber,
            $predecessor,
            $hash,
        );

        DB::table('governance_charter_versions')->insert([
            'id' => (string) Str::uuid7(),
            'business_id' => $business->getKey(),
            'formal_record_version_id' => $version->getKey(),
            'governance_owner_membership_id' => $actor->getKey(),
            'voting_basis' => 'One eligible participant, one vote',
            'default_approval_rule' => 'Exact authority rule',
            'meeting_frequency' => 'Monthly',
            'default_quorum_count' => $requiredApprovals,
            'minutes_owner_membership_id' => $actor->getKey(),
            'conflict_of_interest_rule' => 'Disclose and recuse.',
            'deadlock_rule' => 'Escalate under the approved process.',
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
            'decision_type' => $decisionType,
            'category' => 'management',
            'decision_method' => DecisionMethod::Approval->value,
            'required_approvals' => $requiredApprovals,
            'required_votes' => 0,
            'quorum_count' => $requiredApprovals,
            'signature_required' => false,
            'reserved_matter' => false,
            'meeting_required' => $meetingRequired,
            'record_required' => true,
            'amount_min' => null,
            'amount_max' => null,
            'created_at' => now(),
        ]);

        /*
         * Each version carries its own actor identity. Historical snapshots
         * remain bound to the old source even after the Effective head moves.
         */
        DB::table('governance_charter_rule_actors')->insert([
            'id' => (string) Str::uuid7(),
            'business_id' => $business->getKey(),
            'governance_charter_rule_id' => $ruleId,
            'membership_id' => $actor->getKey(),
            'capacity' => 'Governance Approver',
            'is_decision_owner' => true,
            'is_consulted' => false,
            'can_approve' => true,
            'can_vote' => false,
            'can_sign' => false,
            'created_at' => now(),
        ]);

        $version->frozen_at = now();
        $version->save();

        $this->effectiveStateChain($version, $user);

        $head = RecordFamilyEffectiveHead::query()
            ->where('business_id', $business->getKey())
            ->where('formal_record_family_id', $family->getKey())
            ->first();

        if ($head === null) {
            RecordFamilyEffectiveHead::query()->create([
                'business_id' => $business->getKey(),
                'formal_record_family_id' => $family->getKey(),
                'formal_record_version_id' => $version->getKey(),
                'activated_at' => now(),
            ]);
        } else {
            $head->formal_record_version_id = $version->getKey();
            $head->activated_at = now();
            $head->save();
        }

        return $version->fresh();
    }

    private function recordVersion(
        Business $business,
        FormalRecordFamily $family,
        User $user,
        int $versionNumber,
        ?FormalRecordVersion $predecessor,
        string $hash,
    ): FormalRecordVersion {
        return FormalRecordVersion::query()->create([
            'business_id' => $business->getKey(),
            'formal_record_family_id' => $family->getKey(),
            'version_number' => $versionNumber,
            'predecessor_version_id' => $predecessor?->getKey(),
            'revision' => 1,
            'change_summary' => 'F6 authority fixture v'.$versionNumber,
            'created_by_user_id' => $user->getKey(),
            'last_changed_by_user_id' => $user->getKey(),
            'effective_from' => now()->subMinute(),
            'effective_until' => null,
            'review_due_at' => null,
            'content_hash' => $hash,
            'frozen_at' => null,
        ]);
    }

    private function effectiveStateChain(
        FormalRecordVersion $version,
        User $user,
    ): void {
        $states = [
            [null, 'draft'],
            ['draft', 'ready_for_review'],
            ['ready_for_review', 'under_review'],
            ['under_review', 'approved'],
            ['approved', 'ready_for_effect'],
            ['ready_for_effect', 'effective'],
        ];

        foreach ($states as $index => [$from, $to]) {
            $this->state(
                $version,
                $user,
                $index + 1,
                $from,
                $to,
            );
        }
    }

    private function state(
        FormalRecordVersion $version,
        User $user,
        int $sequence,
        ?string $from,
        string $to,
    ): void {
        RecordVersionStateTransition::query()->create([
            'business_id' => $version->business_id,
            'formal_record_version_id' => $version->getKey(),
            'sequence' => $sequence,
            'from_state' => $from,
            'to_state' => $to,
            'transitioned_by_user_id' => $user->getKey(),
            'occurred_at' => now(),
        ]);
    }

    private function reviewedProposal(
        Business $business,
        User $user,
        Membership $reviewer,
    ): ProposalVersion {
        $proposal = Proposal::query()->create([
            'business_id' => $business->getKey(),
            'revision' => 1,
            'content_hash' => hash(
                'sha256',
                'meeting-proposal-'.Str::uuid7(),
            ),
            'created_by_user_id' => $user->getKey(),
            'last_changed_by_user_id' => $user->getKey(),
        ]);

        $version = ProposalVersion::query()->create([
            'business_id' => $business->getKey(),
            'proposal_id' => $proposal->getKey(),
            'version_number' => 1,
            'proposal_revision' => 1,
            'proposal_content_hash' => $proposal->content_hash,
            'snapshot_hash' => hash(
                'sha256',
                'meeting-snapshot-'.Str::uuid7(),
            ),
            'frozen_by_user_id' => $user->getKey(),
            'frozen_at' => now(),
        ]);

        $review = ProposalReview::query()->create([
            'business_id' => $business->getKey(),
            'proposal_version_id' => $version->getKey(),
            'reviewer_membership_id' => $reviewer->getKey(),
            'created_by_membership_id' => $reviewer->getKey(),
            'status' => 'open',
            'outcome' => null,
            'notes' => null,
            'due_at' => null,
            'resolved_at' => null,
        ]);

        $review->fill([
            'status' => 'completed',
            'outcome' => 'approved',
            'notes' => 'Approved exact Frozen Proposal Version.',
            'resolved_at' => now(),
        ])->save();

        return $version;
    }

    /** @param list<class-string> $resourceTypes */
    private function grant(
        Business $business,
        Membership $membership,
        string $capability,
        array $resourceTypes,
    ): void {
        $permission = Permission::query()->firstOrCreate([
            'key' => $capability,
        ]);

        PermissionGrant::query()->firstOrCreate([
            'business_id' => $business->getKey(),
            'membership_id' => $membership->getKey(),
            'permission_id' => $permission->getKey(),
        ], [
            'effect' => 'allow',
        ]);

        foreach ($resourceTypes as $resourceType) {
            AccessPolicy::query()->firstOrCreate([
                'business_id' => $business->getKey(),
                'membership_id' => $membership->getKey(),
                'permission_profile_id' => null,
                'permission_id' => $permission->getKey(),
                'resource_type' => $resourceType,
            ], [
                'effect' => 'allow',
            ]);
        }
    }

    private function business(string $name): Business
    {
        return Business::query()->create([
            'name' => $name,
            'origin_type' => 'started_through_pbr',
            'business_stage' => 'planning',
            'setup_phase' => 'formation',
            'workspace_status' => 'active',
            'base_currency' => 'USD',
        ]);
    }

    /** @return array{User,Membership} */
    private function member(
        Business $business,
        string $prefix,
    ): array {
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

        return [$user, $membership];
    }

    private function assertDatabaseRejects(callable $callback): void
    {
        DB::beginTransaction();

        try {
            $callback();
            DB::rollBack();
            $this->fail(
                'Expected PostgreSQL to reject immutable Authority Snapshot mutation.',
            );
        } catch (QueryException) {
            if (DB::transactionLevel() > 0) {
                DB::rollBack();
            }

            $this->addToAssertionCount(1);
        }
    }
}
