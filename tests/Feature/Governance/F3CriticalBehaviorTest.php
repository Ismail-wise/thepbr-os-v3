<?php

declare(strict_types=1);

namespace Tests\Feature\Governance;

use App\Application\Governance\OpenGovernanceDecision;
use App\Application\Governance\RecordGovernanceApproval;
use App\Application\Governance\RecuseGovernanceDecisionParticipant;
use App\Application\Governance\ResolveGovernanceDecision;
use App\Domain\Access\CapabilityCatalog;
use App\Domain\Governance\Enums\ApprovalOutcome;
use App\Domain\Governance\Enums\DecisionMethod;
use App\Domain\Governance\Enums\DecisionStatus;
use App\Domain\Governance\ValueObjects\DecisionType;
use App\Infrastructure\Persistence\Eloquent\Access\AccessPolicy;
use App\Infrastructure\Persistence\Eloquent\Access\Permission;
use App\Infrastructure\Persistence\Eloquent\Access\PermissionGrant;
use App\Infrastructure\Persistence\Eloquent\Businesses\Business;
use App\Infrastructure\Persistence\Eloquent\Governance\Approval;
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
use App\Infrastructure\Persistence\Eloquent\Records\RecordVersionStateTransition;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use RuntimeException;
use Tests\TestCase;

final class F3CriticalBehaviorTest extends TestCase
{
    use RefreshDatabase;

    public function test_system_permission_does_not_allow_non_authority_actor_to_approve(): void
    {
        $context = $this->decisionContext();

        $approval = $this->app->make(RecordGovernanceApproval::class)->execute(
            $context['manager'],
            $context['business'],
            (string) $context['decision']->getKey(),
            ApprovalOutcome::Approved,
            'System permission is not governance authority.',
        );

        $this->assertNull($approval);
        $this->assertDatabaseCount('approvals', 0);
        $this->assertSame(
            DecisionStatus::Open,
            $context['decision']->fresh()->status,
        );
    }

    public function test_recused_actor_approval_is_not_counted_by_application_or_postgresql(): void
    {
        $context = $this->decisionContext();

        $approval = $this->app->make(RecordGovernanceApproval::class)->execute(
            $context['authorityUser'],
            $context['business'],
            (string) $context['decision']->getKey(),
            ApprovalOutcome::Approved,
            'Approval before a later conflict was identified.',
        );

        $this->assertInstanceOf(Approval::class, $approval);

        $recused = $this->app->make(RecuseGovernanceDecisionParticipant::class)->execute(
            $context['authorityUser'],
            $context['business'],
            (string) $context['decision']->getKey(),
            'Conflict identified before resolution.',
        );

        $this->assertNotNull($recused);
        $this->assertSame('recused', $recused->status->value);

        try {
            $this->app->make(ResolveGovernanceDecision::class)->approve(
                $context['manager'],
                $context['business'],
                (string) $context['decision']->getKey(),
            );

            $this->fail('Recused approval must not satisfy the captured authority threshold.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString(
                'threshold has not been met',
                $exception->getMessage(),
            );
        }

        $this->assertSame(
            DecisionStatus::Open,
            $context['decision']->fresh()->status,
        );

        $this->assertDatabaseRejects(function () use ($context): void {
            DB::table('decisions')
                ->where('id', $context['decision']->getKey())
                ->update([
                    'status' => 'decided',
                    'outcome' => 'approved',
                    'resolved_at' => now(),
                ]);
        });

        $this->assertSame(
            DecisionStatus::Open,
            $context['decision']->fresh()->status,
        );
    }

    public function test_authority_snapshot_is_immutable_after_capture(): void
    {
        $context = $this->decisionContext();

        $snapshot = AuthoritySnapshot::query()
            ->whereKey($context['decision']->authority_snapshot_id)
            ->firstOrFail();

        $originalHash = (string) $snapshot->snapshot_hash;

        $this->assertDatabaseRejects(function () use ($snapshot): void {
            DB::table('authority_snapshots')
                ->where('id', $snapshot->getKey())
                ->update([
                    'required_approvals' => 2,
                ]);
        });

        $this->assertSame(
            $originalHash,
            (string) $snapshot->fresh()->snapshot_hash,
        );
        $this->assertSame(1, (int) $snapshot->fresh()->required_approvals);
    }

    /**
     * @return array{
     *     business: Business,
     *     manager: User,
     *     managerMembership: Membership,
     *     authorityUser: User,
     *     authorityMembership: Membership,
     *     decision: Decision
     * }
     */
    private function decisionContext(): array
    {
        $business = Business::query()->create([
            'name' => 'F3 Critical '.Str::uuid7(),
            'origin_type' => 'started_through_pbr',
            'business_stage' => 'idea',
            'setup_phase' => 'formation',
            'workspace_status' => 'active',
            'base_currency' => 'USD',
        ]);

        [$manager, $managerMembership] = $this->userMembership(
            $business,
            'manager',
        );

        [$authorityUser, $authorityMembership] = $this->userMembership(
            $business,
            'authority',
        );

        $this->grant(
            $business,
            $managerMembership,
            CapabilityCatalog::GOVERNANCE_RECORDS_MANAGE,
            [ProposalVersion::class, Decision::class],
        );

        $this->grant(
            $business,
            $authorityMembership,
            CapabilityCatalog::GOVERNANCE_RECORDS_MANAGE,
            [Decision::class],
        );

        $family = FormalRecordFamily::query()->create([
            'business_id' => $business->getKey(),
            'record_type' => 'formation_authority_policy',
            'subject_type' => 'business',
            'subject_id' => (string) $business->getKey(),
        ]);

        $authorityVersion = FormalRecordVersion::query()->create([
            'business_id' => $business->getKey(),
            'formal_record_family_id' => $family->getKey(),
            'version_number' => 1,
            'predecessor_version_id' => null,
            'revision' => 1,
            'change_summary' => 'Initial explicit Formation Authority',
            'created_by_user_id' => $manager->getKey(),
            'last_changed_by_user_id' => $manager->getKey(),
            'effective_from' => now()->subMinute(),
            'effective_until' => null,
            'review_due_at' => null,
            'content_hash' => str_repeat('1', 64),
            'frozen_at' => null,
        ]);

        RecordVersionStateTransition::query()->create([
            'business_id' => $business->getKey(),
            'formal_record_version_id' => $authorityVersion->getKey(),
            'sequence' => 1,
            'from_state' => null,
            'to_state' => 'draft',
            'transitioned_by_user_id' => $manager->getKey(),
            'occurred_at' => now()->subSeconds(10),
        ]);

        $rule = FormationAuthorityPolicyRule::query()->create([
            'business_id' => $business->getKey(),
            'formal_record_version_id' => $authorityVersion->getKey(),
            'sequence' => 1,
            'decision_type' => 'critical_test',
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
            'membership_id' => $authorityMembership->getKey(),
            'capacity' => 'Formation Approver',
            'can_approve' => true,
            'can_vote' => false,
            'can_sign' => false,
        ]);

        $authorityVersion->frozen_at = now()->subSeconds(5);
        $authorityVersion->save();

        RecordVersionStateTransition::query()->create([
            'business_id' => $business->getKey(),
            'formal_record_version_id' => $authorityVersion->getKey(),
            'sequence' => 2,
            'from_state' => 'draft',
            'to_state' => 'ready_for_review',
            'transitioned_by_user_id' => $manager->getKey(),
            'occurred_at' => now(),
        ]);

        FormationAuthorityEstablishment::query()->create([
            'business_id' => $business->getKey(),
            'formal_record_version_id' => $authorityVersion->getKey(),
            'established_by_membership_id' => $managerMembership->getKey(),
            'establishment_hash' => $authorityVersion->content_hash,
            'established_at' => now(),
        ]);

        $proposal = Proposal::query()->create([
            'business_id' => $business->getKey(),
            'revision' => 1,
            'content_hash' => str_repeat('2', 64),
            'created_by_user_id' => $manager->getKey(),
            'last_changed_by_user_id' => $manager->getKey(),
        ]);

        $proposalVersion = ProposalVersion::query()->create([
            'business_id' => $business->getKey(),
            'proposal_id' => $proposal->getKey(),
            'version_number' => 1,
            'proposal_revision' => 1,
            'proposal_content_hash' => $proposal->content_hash,
            'snapshot_hash' => str_repeat('3', 64),
            'frozen_by_user_id' => $manager->getKey(),
            'frozen_at' => now(),
        ]);

        $proposalReview = ProposalReview::query()->create([
            'business_id' => $business->getKey(),
            'proposal_version_id' => $proposalVersion->getKey(),
            'reviewer_membership_id' => $managerMembership->getKey(),
            'created_by_membership_id' => $managerMembership->getKey(),
            'status' => 'open',
            'outcome' => null,
            'notes' => null,
            'due_at' => null,
            'resolved_at' => null,
        ]);

        $proposalReview->fill([
            'status' => 'completed',
            'outcome' => 'approved',
            'notes' => 'Approved test fixture review.',
            'resolved_at' => now(),
        ])->save();

        $decision = $this->app->make(OpenGovernanceDecision::class)->execute(
            $manager,
            $business,
            (string) $proposalVersion->getKey(),
            new DecisionType('critical_test'),
        );

        $this->assertInstanceOf(Decision::class, $decision);

        return [
            'business' => $business,
            'manager' => $manager,
            'managerMembership' => $managerMembership,
            'authorityUser' => $authorityUser,
            'authorityMembership' => $authorityMembership,
            'decision' => $decision,
        ];
    }

    /** @return array{User, Membership} */
    private function userMembership(Business $business, string $prefix): array
    {
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

    private function assertDatabaseRejects(callable $callback): void
    {
        DB::beginTransaction();

        try {
            $callback();
            DB::rollBack();
            $this->fail('Expected PostgreSQL to reject the invalid governance write.');
        } catch (QueryException) {
            if (DB::transactionLevel() > 0) {
                DB::rollBack();
            }

            $this->addToAssertionCount(1);
        }
    }
}
