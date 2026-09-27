<?php

declare(strict_types=1);

namespace Tests\Feature\Governance;

use App\Application\Governance\GovernanceAuthorityChangeWorkflow;
use App\Application\Governance\OpenGovernanceDecision;
use App\Application\Governance\RecordGovernanceApproval;
use App\Application\Governance\ResolveGovernanceAuthority;
use App\Application\Governance\ResolveGovernanceDecision;
use App\Domain\Access\CapabilityCatalog;
use App\Domain\Governance\Enums\ApprovalOutcome;
use App\Domain\Governance\Enums\DecisionMethod;
use App\Domain\Governance\ValueObjects\DecisionType;
use App\Infrastructure\Persistence\Eloquent\Access\AccessPolicy;
use App\Infrastructure\Persistence\Eloquent\Access\Permission;
use App\Infrastructure\Persistence\Eloquent\Access\PermissionGrant;
use App\Infrastructure\Persistence\Eloquent\Businesses\Business;
use App\Infrastructure\Persistence\Eloquent\Governance\Decision;
use App\Infrastructure\Persistence\Eloquent\Governance\DecisionParticipant;
use App\Infrastructure\Persistence\Eloquent\Governance\EmergencyAuthorityGrant;
use App\Infrastructure\Persistence\Eloquent\Governance\FormationAuthorityEstablishment;
use App\Infrastructure\Persistence\Eloquent\Governance\FormationAuthorityPolicyActor;
use App\Infrastructure\Persistence\Eloquent\Governance\FormationAuthorityPolicyRule;
use App\Infrastructure\Persistence\Eloquent\Governance\GovernanceDelegation;
use App\Infrastructure\Persistence\Eloquent\Governance\ProposalReview;
use App\Infrastructure\Persistence\Eloquent\Identity\User;
use App\Infrastructure\Persistence\Eloquent\Members\Membership;
use App\Infrastructure\Persistence\Eloquent\Records\FormalRecordFamily;
use App\Infrastructure\Persistence\Eloquent\Records\FormalRecordVersion;
use App\Infrastructure\Persistence\Eloquent\Records\Proposal;
use App\Infrastructure\Persistence\Eloquent\Records\ProposalVersion;
use App\Infrastructure\Persistence\Eloquent\Records\RecordVersionStateTransition;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Tests\TestCase;

final class F6ADelegationEmergencyTest extends TestCase
{
    use RefreshDatabase;

    public function test_delegation_is_inert_until_governed_and_then_substitutes_without_expansion(): void
    {
        $context = $this->context([
            'governance_delegation',
            'delegated_target',
        ]);

        $workflow = $this->app->make(
            GovernanceAuthorityChangeWorkflow::class,
        );

        $submission = $workflow->proposeDelegation(
            $context['manager'],
            $context['business'],
            (string) $context['managerMembership']->getKey(),
            (string) $context['delegateMembership']->getKey(),
            'delegated_target',
            'Exact delegated_target decision scope only.',
            now()->subMinute(),
            now()->addDay(),
        );

        self::assertNotNull($submission);

        $before = $this->app->make(
            ResolveGovernanceAuthority::class,
        )->resolve(
            $context['business'],
            new DecisionType('delegated_target'),
        );

        self::assertNotNull($before);
        self::assertSame(
            [(string) $context['managerMembership']->getKey()],
            $before['actors']->pluck('membershipId')->all(),
        );

        $this->approveAuthorityChange(
            $context,
            $submission['proposal_version_id'],
            'governance_delegation',
        );

        self::assertTrue(
            $workflow->authorize(
                $context['manager'],
                $context['business'],
                $submission['submission_id'],
            ),
        );

        $after = $this->app->make(
            ResolveGovernanceAuthority::class,
        )->resolve(
            $context['business'],
            new DecisionType('delegated_target'),
        );

        self::assertNotNull($after);
        self::assertCount(1, $after['actors']);

        $delegated = $after['actors']->first();

        self::assertSame(
            (string) $context['delegateMembership']->getKey(),
            $delegated->membershipId,
        );
        self::assertTrue($delegated->canApprove);
        self::assertFalse($delegated->canVote);
        self::assertFalse($delegated->canSign);
        self::assertSame('delegation', $delegated->source);

        $targetProposal = $this->reviewedProposal($context);
        $targetDecision = $this->app->make(
            OpenGovernanceDecision::class,
        )->execute(
            $context['manager'],
            $context['business'],
            (string) $targetProposal->getKey(),
            new DecisionType('delegated_target'),
        );

        self::assertInstanceOf(Decision::class, $targetDecision);

        $participants = DecisionParticipant::query()
            ->where('business_id', $context['business']->getKey())
            ->where('decision_id', $targetDecision->getKey())
            ->get();

        self::assertCount(1, $participants);
        self::assertSame(
            (string) $context['delegateMembership']->getKey(),
            (string) $participants->first()->membership_id,
        );
        self::assertSame(
            'Delegated: Formation Approver',
            (string) $participants->first()->capacity,
        );
    }

    public function test_emergency_authority_is_governed_time_limited_and_does_not_change_base_threshold(): void
    {
        $context = $this->context([
            'emergency_authority',
            'emergency_target',
        ]);

        $workflow = $this->app->make(
            GovernanceAuthorityChangeWorkflow::class,
        );

        $submission = $workflow->proposeEmergencyAuthority(
            $context['manager'],
            $context['business'],
            (string) $context['delegateMembership']->getKey(),
            'emergency_target',
            'Emergency authority only for emergency_target.',
            'Emergency Approver',
            true,
            false,
            false,
            'Primary approver continuity exception.',
            now()->addHour(),
            now()->subMinute(),
        );

        self::assertNotNull($submission);

        $this->approveAuthorityChange(
            $context,
            $submission['proposal_version_id'],
            'emergency_authority',
        );

        self::assertTrue(
            $workflow->authorize(
                $context['manager'],
                $context['business'],
                $submission['submission_id'],
            ),
        );

        $resolved = $this->app->make(
            ResolveGovernanceAuthority::class,
        )->resolve(
            $context['business'],
            new DecisionType('emergency_target'),
        );

        self::assertNotNull($resolved);
        self::assertSame(
            1,
            $resolved['rule']['required_approvals'],
            'Emergency Authority must not lower or rewrite the base threshold.',
        );
        self::assertCount(2, $resolved['actors']);

        $byMembership = $resolved['actors']->keyBy('membershipId');

        self::assertSame(
            'base',
            $byMembership[
                (string) $context['managerMembership']->getKey()
            ]->source,
        );
        self::assertSame(
            'emergency',
            $byMembership[
                (string) $context['delegateMembership']->getKey()
            ]->source,
        );

        $targetProposal = $this->reviewedProposal($context);
        $targetDecision = $this->app->make(
            OpenGovernanceDecision::class,
        )->execute(
            $context['manager'],
            $context['business'],
            (string) $targetProposal->getKey(),
            new DecisionType('emergency_target'),
        );

        self::assertInstanceOf(Decision::class, $targetDecision);
        self::assertCount(
            2,
            DecisionParticipant::query()
                ->where(
                    'business_id',
                    $context['business']->getKey(),
                )
                ->where(
                    'decision_id',
                    $targetDecision->getKey(),
                )
                ->get(),
        );

        $expired = $this->app->make(
            ResolveGovernanceAuthority::class,
        )->resolve(
            $context['business'],
            new DecisionType('emergency_target'),
            null,
            now()->addHours(2)->toImmutable(),
        );

        self::assertNotNull($expired);
        self::assertCount(1, $expired['actors']);
        self::assertSame(
            (string) $context['managerMembership']->getKey(),
            $expired['actors']->first()->membershipId,
        );
    }

    public function test_unauthorized_and_cross_business_delegation_fail_closed(): void
    {
        $context = $this->context([
            'governance_delegation',
            'delegated_target',
        ]);

        [$unauthorized] = $this->member(
            $context['business'],
            'f6-unauthorized',
        );

        $workflow = $this->app->make(
            GovernanceAuthorityChangeWorkflow::class,
        );

        self::assertNull(
            $workflow->proposeDelegation(
                $unauthorized,
                $context['business'],
                (string) $context['managerMembership']->getKey(),
                (string) $context['delegateMembership']->getKey(),
                'delegated_target',
                'Unauthorized proposal must fail closed.',
            ),
        );

        $other = $this->context([
            'governance_delegation',
            'delegated_target',
        ]);

        self::assertNull(
            $workflow->proposeDelegation(
                $context['manager'],
                $context['business'],
                (string) $context['managerMembership']->getKey(),
                (string) $other['delegateMembership']->getKey(),
                'delegated_target',
                'Cross-Business delegate must fail closed.',
            ),
        );
    }

    public function test_approved_delegation_revocation_restores_the_base_authority_seat(): void
    {
        $context = $this->context([
            'governance_delegation',
            'governance_delegation_revoke',
            'delegated_target',
        ]);

        $workflow = $this->app->make(
            GovernanceAuthorityChangeWorkflow::class,
        );

        $grant = $workflow->proposeDelegation(
            $context['manager'],
            $context['business'],
            (string) $context['managerMembership']->getKey(),
            (string) $context['delegateMembership']->getKey(),
            'delegated_target',
            'Exact delegated target scope.',
            now()->subMinute(),
            now()->addDay(),
        );

        self::assertNotNull($grant);

        $this->approveAuthorityChange(
            $context,
            $grant['proposal_version_id'],
            'governance_delegation',
        );

        self::assertTrue(
            $workflow->authorize(
                $context['manager'],
                $context['business'],
                $grant['submission_id'],
            ),
        );

        $delegated = $this->app->make(
            ResolveGovernanceAuthority::class,
        )->resolve(
            $context['business'],
            new DecisionType('delegated_target'),
        );

        self::assertNotNull($delegated);
        self::assertSame(
            (string) $context['delegateMembership']->getKey(),
            $delegated['actors']->first()->membershipId,
        );

        $revocation = $workflow->proposeRevocation(
            $context['manager'],
            $context['business'],
            'delegation',
            $grant['subject_id'],
        );

        self::assertNotNull($revocation);

        $this->approveAuthorityChange(
            $context,
            $revocation['proposal_version_id'],
            'governance_delegation_revoke',
        );

        self::assertTrue(
            $workflow->authorize(
                $context['manager'],
                $context['business'],
                $revocation['submission_id'],
            ),
        );

        $restored = $this->app->make(
            ResolveGovernanceAuthority::class,
        )->resolve(
            $context['business'],
            new DecisionType('delegated_target'),
        );

        self::assertNotNull($restored);
        self::assertCount(1, $restored['actors']);
        self::assertSame(
            (string) $context['managerMembership']->getKey(),
            $restored['actors']->first()->membershipId,
        );
        self::assertSame('base', $restored['actors']->first()->source);
    }

    /**
     * @param  list<string>  $decisionTypes
     * @return array{
     *   business:Business,
     *   manager:User,
     *   managerMembership:Membership,
     *   delegate:User,
     *   delegateMembership:Membership
     * }
     */
    private function context(array $decisionTypes): array
    {
        $business = Business::query()->create([
            'name' => 'F6 Authority Change '.Str::uuid7(),
            'origin_type' => 'started_through_pbr',
            'business_stage' => 'planning',
            'setup_phase' => 'formation',
            'workspace_status' => 'active',
            'base_currency' => 'USD',
        ]);

        [$manager, $managerMembership] = $this->member(
            $business,
            'f6-manager',
        );
        [$delegate, $delegateMembership] = $this->member(
            $business,
            'f6-delegate',
        );

        $this->grant(
            $business,
            $managerMembership,
            CapabilityCatalog::GOVERNANCE_RECORDS_MANAGE,
            [
                Proposal::class,
                ProposalVersion::class,
                Decision::class,
                GovernanceDelegation::class,
                EmergencyAuthorityGrant::class,
            ],
        );

        $family = FormalRecordFamily::query()->create([
            'business_id' => $business->getKey(),
            'record_type' => 'formation_authority_policy',
            'subject_type' => 'business',
            'subject_id' => (string) $business->getKey(),
        ]);

        $version = FormalRecordVersion::query()->create([
            'business_id' => $business->getKey(),
            'formal_record_family_id' => $family->getKey(),
            'version_number' => 1,
            'predecessor_version_id' => null,
            'revision' => 1,
            'change_summary' => 'F6 authority change fixture',
            'created_by_user_id' => $manager->getKey(),
            'last_changed_by_user_id' => $manager->getKey(),
            'effective_from' => now()->subMinute(),
            'effective_until' => null,
            'review_due_at' => null,
            'content_hash' => str_repeat('1', 64),
            'frozen_at' => null,
        ]);

        foreach ($decisionTypes as $index => $decisionType) {
            $rule = FormationAuthorityPolicyRule::query()->create([
                'business_id' => $business->getKey(),
                'formal_record_version_id' => $version->getKey(),
                'sequence' => $index + 1,
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
                'membership_id' => $managerMembership->getKey(),
                'capacity' => 'Formation Approver',
                'can_approve' => true,
                'can_vote' => false,
                'can_sign' => false,
            ]);
        }

        $version->frozen_at = now();
        $version->save();

        $this->state($version, $manager, 1, null, 'draft');
        $this->state(
            $version,
            $manager,
            2,
            'draft',
            'ready_for_review',
        );

        FormationAuthorityEstablishment::query()->create([
            'business_id' => $business->getKey(),
            'formal_record_version_id' => $version->getKey(),
            'established_by_membership_id' => $managerMembership->getKey(),
            'establishment_hash' => $version->content_hash,
            'established_at' => now(),
        ]);

        return [
            'business' => $business,
            'manager' => $manager,
            'managerMembership' => $managerMembership,
            'delegate' => $delegate,
            'delegateMembership' => $delegateMembership,
        ];
    }

    /** @param array<string,mixed> $context */
    private function approveAuthorityChange(
        array $context,
        string $proposalVersionId,
        string $decisionType,
    ): void {
        $proposalVersion = ProposalVersion::query()
            ->where('business_id', $context['business']->getKey())
            ->whereKey($proposalVersionId)
            ->sole();

        $review = ProposalReview::query()->create([
            'business_id' => $context['business']->getKey(),
            'proposal_version_id' => $proposalVersion->getKey(),
            'reviewer_membership_id' => $context['managerMembership']->getKey(),
            'created_by_membership_id' => $context['managerMembership']->getKey(),
            'status' => 'open',
            'outcome' => null,
            'notes' => null,
            'due_at' => null,
            'resolved_at' => null,
        ]);

        $review->fill([
            'status' => 'completed',
            'outcome' => 'approved',
            'notes' => 'Approved exact authority-change proposal.',
            'resolved_at' => now(),
        ])->save();

        $decision = $this->app->make(
            OpenGovernanceDecision::class,
        )->execute(
            $context['manager'],
            $context['business'],
            $proposalVersionId,
            new DecisionType($decisionType),
        );

        self::assertInstanceOf(Decision::class, $decision);

        $approval = $this->app->make(
            RecordGovernanceApproval::class,
        )->execute(
            $context['manager'],
            $context['business'],
            (string) $decision->getKey(),
            ApprovalOutcome::Approved,
            'Approved exact frozen authority change.',
        );

        self::assertNotNull($approval);

        $resolved = $this->app->make(
            ResolveGovernanceDecision::class,
        )->approve(
            $context['manager'],
            $context['business'],
            (string) $decision->getKey(),
        );

        self::assertNotNull($resolved);
        self::assertSame('approved', $resolved->outcome?->value);
    }

    /** @param array<string,mixed> $context */
    private function reviewedProposal(array $context): ProposalVersion
    {
        $proposal = Proposal::query()->create([
            'business_id' => $context['business']->getKey(),
            'revision' => 1,
            'content_hash' => hash(
                'sha256',
                'target-'.Str::uuid7(),
            ),
            'created_by_user_id' => $context['manager']->getKey(),
            'last_changed_by_user_id' => $context['manager']->getKey(),
        ]);

        $proposalVersion = ProposalVersion::query()->create([
            'business_id' => $context['business']->getKey(),
            'proposal_id' => $proposal->getKey(),
            'version_number' => 1,
            'proposal_revision' => 1,
            'proposal_content_hash' => $proposal->content_hash,
            'snapshot_hash' => hash(
                'sha256',
                'snapshot-'.Str::uuid7(),
            ),
            'frozen_by_user_id' => $context['manager']->getKey(),
            'frozen_at' => now(),
        ]);

        $review = ProposalReview::query()->create([
            'business_id' => $context['business']->getKey(),
            'proposal_version_id' => $proposalVersion->getKey(),
            'reviewer_membership_id' => $context['managerMembership']->getKey(),
            'created_by_membership_id' => $context['managerMembership']->getKey(),
            'status' => 'open',
            'outcome' => null,
            'notes' => null,
            'due_at' => null,
            'resolved_at' => null,
        ]);

        $review->fill([
            'status' => 'completed',
            'outcome' => 'approved',
            'notes' => 'Approved target proposal.',
            'resolved_at' => now(),
        ])->save();

        return $proposalVersion;
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
}
