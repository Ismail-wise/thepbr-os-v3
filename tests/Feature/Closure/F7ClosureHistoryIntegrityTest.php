<?php

declare(strict_types=1);

namespace Tests\Feature\Closure;

use App\Application\Access\ProvisionStandardAccessProfiles;
use App\Application\Closure\ClosureWorkflow;
use App\Application\Governance\CompleteProposalReview;
use App\Application\Governance\CreateProposalReview;
use App\Application\Governance\OpenGovernanceDecision;
use App\Application\Governance\RecordGovernanceApproval;
use App\Application\Governance\ResolveGovernanceDecision;
use App\Domain\Access\CapabilityCatalog;
use App\Domain\Businesses\Enums\WorkspaceStatus;
use App\Domain\Closure\Enums\ClosureCaseStatus;
use App\Domain\Closure\Enums\ClosureClaimStatus;
use App\Domain\Governance\Enums\ApprovalOutcome;
use App\Domain\Governance\Enums\DecisionMethod;
use App\Domain\Governance\Enums\ProposalReviewOutcome;
use App\Domain\Governance\ValueObjects\DecisionType;
use App\Domain\Records\Enums\FormalRecordState;
use App\Infrastructure\Persistence\Eloquent\Access\AccessPolicy;
use App\Infrastructure\Persistence\Eloquent\Access\Permission;
use App\Infrastructure\Persistence\Eloquent\Access\PermissionGrant;
use App\Infrastructure\Persistence\Eloquent\Businesses\Business;
use App\Infrastructure\Persistence\Eloquent\Closure\ClosureCase;
use App\Infrastructure\Persistence\Eloquent\Governance\Decision;
use App\Infrastructure\Persistence\Eloquent\Governance\FormationAuthorityEstablishment;
use App\Infrastructure\Persistence\Eloquent\Governance\FormationAuthorityPolicyActor;
use App\Infrastructure\Persistence\Eloquent\Governance\FormationAuthorityPolicyRule;
use App\Infrastructure\Persistence\Eloquent\Identity\User;
use App\Infrastructure\Persistence\Eloquent\Members\Membership;
use App\Infrastructure\Persistence\Eloquent\Records\FormalRecordFamily;
use App\Infrastructure\Persistence\Eloquent\Records\FormalRecordVersion;
use App\Infrastructure\Persistence\Eloquent\Records\RecordVersionStateTransition;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Tests\TestCase;

final class F7ClosureHistoryIntegrityTest extends TestCase
{
    use RefreshDatabase;

    public function test_governed_closure_preserves_exact_history_and_closes_workspace_only_after_legal_effect(): void
    {
        $context = $this->governedContext();
        $workflow = $this->app->make(ClosureWorkflow::class);

        $case = $workflow->createCase(
            $context['manager'],
            $context['business'],
            'approved_orderly_dissolution',
            'Thailand law plus qualified local legal and accounting advice.',
            'business_closure_approval',
            'Governed orderly wind-down.',
            'Local legal entity registration reference.',
            now()->subMinute(),
        );

        self::assertNotNull($case);
        self::assertSame(ClosureCaseStatus::Draft, $case->status);

        foreach ([
            ['asset', 'assets_protected'],
            ['asset', 'asset_inventory_complete'],
            ['liability', 'liability_inventory_complete'],
        ] as [$type, $key]) {
            self::assertTrue($workflow->recordRequirement(
                $context['manager'],
                $context['business'],
                (string) $case->getKey(),
                1,
                $type,
                $key,
                'met',
                'Verified before Governance.',
            ));
        }

        $claimId = $workflow->createClaim(
            $context['manager'],
            $context['business'],
            (string) $case->getKey(),
            1,
            'CLAIM-HISTORY-001',
            'creditor',
            'Trade creditor',
            true,
            null,
            null,
            'Claim recorded before residual distribution.',
            'Priority must follow the applicable jurisdiction-specific rule.',
        );

        self::assertNotNull($claimId);

        self::assertTrue($workflow->transitionClaim(
            $context['manager'],
            $context['business'],
            (string) $case->getKey(),
            1,
            $claimId,
            1,
            ClosureClaimStatus::Verified,
            'Claim verified.',
        ));

        self::assertTrue($workflow->transitionClaim(
            $context['manager'],
            $context['business'],
            (string) $case->getKey(),
            1,
            $claimId,
            2,
            ClosureClaimStatus::Waived,
            'Creditor waiver evidenced outside payment ledger.',
        ));

        $submission = $workflow->submitGovernance(
            $context['manager'],
            $context['business'],
            (string) $case->getKey(),
            1,
        );

        self::assertNotNull($submission);

        self::assertTrue($workflow->advanceContentReview(
            $context['manager'],
            $context['business'],
            (string) $case->getKey(),
            $submission['formal_record_version_id'],
            FormalRecordState::UnderReview,
        ));

        self::assertTrue($workflow->advanceContentReview(
            $context['manager'],
            $context['business'],
            (string) $case->getKey(),
            $submission['formal_record_version_id'],
            FormalRecordState::Approved,
        ));

        $review = $this->app->make(CreateProposalReview::class)->execute(
            $context['manager'],
            $context['business'],
            $submission['proposal_version_id'],
            (string) $context['managerMembership']->getKey(),
        );

        self::assertNotNull($review);

        self::assertNotNull(
            $this->app->make(CompleteProposalReview::class)->execute(
                $context['manager'],
                $context['business'],
                (string) $review->getKey(),
                ProposalReviewOutcome::Approved,
                'Closure reviewed against the exact frozen proposal version.',
            ),
        );

        $decision = $this->app->make(OpenGovernanceDecision::class)->execute(
            $context['manager'],
            $context['business'],
            $submission['proposal_version_id'],
            new DecisionType('business_closure_approval'),
        );

        self::assertInstanceOf(Decision::class, $decision);
        $authoritySnapshotId = (string) $decision->authority_snapshot_id;

        self::assertNotNull(
            $this->app->make(RecordGovernanceApproval::class)->execute(
                $context['approver'],
                $context['business'],
                (string) $decision->getKey(),
                ApprovalOutcome::Approved,
                'Approve the exact frozen Closure proposal.',
            ),
        );

        self::assertNotNull(
            $this->app->make(ResolveGovernanceDecision::class)->approve(
                $context['manager'],
                $context['business'],
                (string) $decision->getKey(),
            ),
        );

        $case = $workflow->syncDecision(
            $context['manager'],
            $context['business'],
            (string) $case->getKey(),
            2,
        );

        self::assertNotNull($case);
        self::assertSame(ClosureCaseStatus::Approved, $case->status);
        self::assertSame(3, (int) $case->revision);

        $case = $workflow->activateWindDown(
            $context['manager'],
            $context['business'],
            (string) $case->getKey(),
            3,
        );

        self::assertNotNull($case);
        self::assertSame(ClosureCaseStatus::WindDownActive, $case->status);
        self::assertSame(4, (int) $case->revision);

        foreach ([
            ['finance', 'finance_reconciled'],
            ['operations', 'operations_reconciled'],
            ['continuity', 'risk_continuity_reconciled'],
            ['conflict', 'conflict_reconciled'],
        ] as [$type, $key]) {
            self::assertTrue($workflow->recordRequirement(
                $context['manager'],
                $context['business'],
                (string) $case->getKey(),
                4,
                $type,
                $key,
                'met',
                'Connected domain reconciled before residual readiness.',
            ));
        }

        $case = $workflow->prepareResidualDistribution(
            $context['manager'],
            $context['business'],
            (string) $case->getKey(),
            4,
        );

        self::assertNotNull($case);
        self::assertSame(ClosureCaseStatus::ResidualReady, $case->status);
        self::assertSame(5, (int) $case->revision);

        $case = $workflow->recordResidualDistribution(
            $context['manager'],
            $context['business'],
            (string) $case->getKey(),
            5,
            'not_applicable',
            null,
            null,
            'No residual value remains after jurisdiction-specific settlement.',
        );

        self::assertNotNull($case);
        self::assertSame(6, (int) $case->revision);
        self::assertSame(
            'not_applicable',
            $case->residual_distribution_status,
        );

        foreach ([
            ['legal', 'legal_conditions_satisfied'],
            ['tax', 'tax_requirements_resolved'],
            ['records', 'records_retention_ready'],
            ['legal', 'post_closure_duties_recorded'],
        ] as [$type, $key]) {
            self::assertTrue($workflow->recordRequirement(
                $context['manager'],
                $context['business'],
                (string) $case->getKey(),
                6,
                $type,
                $key,
                'met',
                'Verified before legal Closure effectivity.',
            ));
        }

        $case = $workflow->prepareLegalClosure(
            $context['manager'],
            $context['business'],
            (string) $case->getKey(),
            6,
        );

        self::assertNotNull($case);
        self::assertSame(
            ClosureCaseStatus::LegalClosureReady,
            $case->status,
        );
        self::assertSame(7, (int) $case->revision);

        $case = $workflow->effectLegalClosure(
            $context['manager'],
            $context['business'],
            (string) $case->getKey(),
            7,
        );

        self::assertNotNull($case);
        self::assertSame(
            ClosureCaseStatus::LegallyClosed,
            $case->status,
        );
        self::assertSame(8, (int) $case->revision);
        self::assertNotNull($case->legal_closed_at);
        self::assertSame(
            WorkspaceStatus::Active,
            $context['business']->fresh()->workspace_status,
            'Legal closure must remain distinct from workspace closure.',
        );

        $formalCountBeforeWorkspaceClose = DB::table('formal_record_versions')
            ->where('business_id', $context['business']->getKey())
            ->count();
        $eventCountBeforeWorkspaceClose = DB::table('business_events')
            ->where('business_id', $context['business']->getKey())
            ->count();

        $case = $workflow->closeWorkspace(
            $context['manager'],
            $context['business'],
            (string) $case->getKey(),
            8,
            (string) $context['business']->name,
        );

        self::assertNotNull($case);
        self::assertSame(ClosureCaseStatus::Completed, $case->status);
        self::assertSame(9, (int) $case->revision);
        self::assertNotNull($case->workspace_closed_at);
        self::assertSame(
            WorkspaceStatus::Closed,
            $context['business']->fresh()->workspace_status,
        );

        self::assertSame(
            $formalCountBeforeWorkspaceClose,
            DB::table('formal_record_versions')
                ->where('business_id', $context['business']->getKey())
                ->count(),
            'Workspace closure must not delete formal record history.',
        );
        self::assertGreaterThanOrEqual(
            $eventCountBeforeWorkspaceClose,
            DB::table('business_events')
                ->where('business_id', $context['business']->getKey())
                ->count(),
        );

        self::assertDatabaseHas('closure_governance_submissions', [
            'business_id' => $context['business']->getKey(),
            'closure_case_id' => $case->getKey(),
            'formal_record_version_id' => $submission['formal_record_version_id'],
            'proposal_version_id' => $submission['proposal_version_id'],
            'decision_id' => $decision->getKey(),
        ]);

        self::assertDatabaseHas('decisions', [
            'id' => $decision->getKey(),
            'business_id' => $context['business']->getKey(),
            'proposal_version_id' => $submission['proposal_version_id'],
            'authority_snapshot_id' => $authoritySnapshotId,
            'status' => 'decided',
            'outcome' => 'approved',
        ]);

        self::assertDatabaseHas('authority_snapshots', [
            'id' => $authoritySnapshotId,
            'business_id' => $context['business']->getKey(),
            'proposal_version_id' => $submission['proposal_version_id'],
        ]);

        $effectiveState = DB::table('record_version_state_transitions')
            ->where('business_id', $context['business']->getKey())
            ->where(
                'formal_record_version_id',
                $submission['formal_record_version_id'],
            )
            ->orderByDesc('sequence')
            ->value('to_state');

        self::assertSame('effective', $effectiveState);
    }

    public function test_required_unresolved_claim_blocks_residual_distribution(): void
    {
        $context = $this->governedContext();
        $workflow = $this->app->make(ClosureWorkflow::class);

        $case = $this->approvedWindDownCase($context, $workflow);

        $claimId = $workflow->createClaim(
            $context['manager'],
            $context['business'],
            (string) $case->getKey(),
            4,
            'CLAIM-BLOCK-001',
            'creditor',
            'Unresolved creditor',
            true,
            null,
            null,
            'Required claim intentionally unresolved.',
            'Applicable jurisdiction-specific priority reference.',
        );

        self::assertNotNull($claimId);

        foreach ([
            ['finance', 'finance_reconciled'],
            ['operations', 'operations_reconciled'],
            ['continuity', 'risk_continuity_reconciled'],
            ['conflict', 'conflict_reconciled'],
        ] as [$type, $key]) {
            self::assertTrue($workflow->recordRequirement(
                $context['manager'],
                $context['business'],
                (string) $case->getKey(),
                4,
                $type,
                $key,
                'met',
            ));
        }

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage(
            'Required Closure Claims must be settled or waived',
        );

        $workflow->prepareResidualDistribution(
            $context['manager'],
            $context['business'],
            (string) $case->getKey(),
            4,
        );
    }

    public function test_closure_history_tables_are_append_only_and_effective_binding_is_immutable(): void
    {
        $triggers = [
            'closure_case_transitions_append_only',
            'closure_claim_transitions_append_only',
            'closure_requirements_append_only',
            'closure_finance_links_append_only',
            'closure_record_versions_append_only',
            'closure_governance_submissions_delete_protect',
            'closure_cases_history_guard',
            'closure_claims_history_guard',
            'closure_governance_submissions_history_guard',
        ];

        $count = (int) DB::selectOne(
            <<<'SQL'
SELECT COUNT(*)::int AS count
FROM pg_trigger
WHERE tgname = ANY(?::text[])
  AND NOT tgisinternal
SQL,
            ['{'.implode(',', $triggers).'}'],
        )->count;

        self::assertSame(count($triggers), $count);

        $context = $this->governedContext();
        $workflow = $this->app->make(ClosureWorkflow::class);
        $case = $workflow->createCase(
            $context['manager'],
            $context['business'],
            'history_guard_test',
            'Applicable jurisdiction-specific rule.',
            'business_closure_approval',
        );

        self::assertNotNull($case);

        self::assertTrue($workflow->recordRequirement(
            $context['manager'],
            $context['business'],
            (string) $case->getKey(),
            1,
            'asset',
            'assets_protected',
            'met',
        ));

        $this->expectException(QueryException::class);

        DB::table('closure_case_transitions')
            ->where('closure_case_id', $case->getKey())
            ->delete();
    }

    /** @param array<string,mixed> $context */
    private function approvedWindDownCase(
        array $context,
        ClosureWorkflow $workflow,
    ): ClosureCase {
        $case = $workflow->createCase(
            $context['manager'],
            $context['business'],
            'residual_block_test',
            'Applicable jurisdiction-specific rule.',
            'business_closure_approval',
            'Prepare governed wind-down for residual blocking test.',
            null,
            now()->subMinute(),
        );

        self::assertNotNull($case);

        foreach ([
            ['asset', 'assets_protected'],
            ['asset', 'asset_inventory_complete'],
            ['liability', 'liability_inventory_complete'],
        ] as [$type, $key]) {
            self::assertTrue($workflow->recordRequirement(
                $context['manager'],
                $context['business'],
                (string) $case->getKey(),
                1,
                $type,
                $key,
                'met',
            ));
        }

        $submission = $workflow->submitGovernance(
            $context['manager'],
            $context['business'],
            (string) $case->getKey(),
            1,
        );

        self::assertNotNull($submission);

        self::assertTrue($workflow->advanceContentReview(
            $context['manager'],
            $context['business'],
            (string) $case->getKey(),
            $submission['formal_record_version_id'],
            FormalRecordState::UnderReview,
        ));
        self::assertTrue($workflow->advanceContentReview(
            $context['manager'],
            $context['business'],
            (string) $case->getKey(),
            $submission['formal_record_version_id'],
            FormalRecordState::Approved,
        ));

        $review = $this->app->make(CreateProposalReview::class)->execute(
            $context['manager'],
            $context['business'],
            $submission['proposal_version_id'],
            (string) $context['managerMembership']->getKey(),
        );

        self::assertNotNull($review);
        self::assertNotNull(
            $this->app->make(CompleteProposalReview::class)->execute(
                $context['manager'],
                $context['business'],
                (string) $review->getKey(),
                ProposalReviewOutcome::Approved,
                'Residual blocking Closure review.',
            ),
        );

        $decision = $this->app->make(OpenGovernanceDecision::class)->execute(
            $context['manager'],
            $context['business'],
            $submission['proposal_version_id'],
            new DecisionType('business_closure_approval'),
        );

        self::assertInstanceOf(Decision::class, $decision);
        self::assertNotNull(
            $this->app->make(RecordGovernanceApproval::class)->execute(
                $context['approver'],
                $context['business'],
                (string) $decision->getKey(),
                ApprovalOutcome::Approved,
                'Approve Closure for residual blocking test.',
            ),
        );
        self::assertNotNull(
            $this->app->make(ResolveGovernanceDecision::class)->approve(
                $context['manager'],
                $context['business'],
                (string) $decision->getKey(),
            ),
        );

        $case = $workflow->syncDecision(
            $context['manager'],
            $context['business'],
            (string) $case->getKey(),
            2,
        );

        self::assertNotNull($case);
        self::assertSame(3, (int) $case->revision);

        $case = $workflow->activateWindDown(
            $context['manager'],
            $context['business'],
            (string) $case->getKey(),
            3,
        );

        self::assertNotNull($case);
        self::assertSame(ClosureCaseStatus::WindDownActive, $case->status);
        self::assertSame(4, (int) $case->revision);

        return $case;
    }

    /** @return array<string,mixed> */
    private function governedContext(): array
    {
        $business = Business::query()->create([
            'name' => 'F7 Closure Governance '.Str::uuid7(),
            'origin_type' => 'started_through_pbr',
            'business_stage' => 'planning',
            'setup_phase' => 'formation',
            'workspace_status' => 'active',
            'base_currency' => 'USD',
        ]);

        [$manager, $managerMembership] =
            $this->userMembership($business, 'manager');
        [$approver, $approverMembership] =
            $this->userMembership($business, 'approver');

        $this->app
            ->make(ProvisionStandardAccessProfiles::class)
            ->execute($business, $managerMembership);

        $this->grant(
            $business,
            $approverMembership,
            CapabilityCatalog::GOVERNANCE_RECORDS_MANAGE,
            [Decision::class],
        );

        $this->seedFormationAuthority(
            $business,
            $manager,
            $managerMembership,
            $approverMembership,
        );

        return [
            'business' => $business,
            'manager' => $manager,
            'managerMembership' => $managerMembership,
            'approver' => $approver,
            'approverMembership' => $approverMembership,
        ];
    }

    private function seedFormationAuthority(
        Business $business,
        User $manager,
        Membership $managerMembership,
        Membership $approverMembership,
    ): void {
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
            'change_summary' => 'F7 Closure temporary Formation Authority.',
            'created_by_user_id' => $manager->getKey(),
            'last_changed_by_user_id' => $manager->getKey(),
            'effective_from' => now()->subMinute(),
            'effective_until' => null,
            'review_due_at' => null,
            'content_hash' => str_repeat('c', 64),
            'frozen_at' => null,
        ]);

        RecordVersionStateTransition::query()->create([
            'business_id' => $business->getKey(),
            'formal_record_version_id' => $version->getKey(),
            'sequence' => 1,
            'from_state' => null,
            'to_state' => 'draft',
            'transitioned_by_user_id' => $manager->getKey(),
            'occurred_at' => now()->subSeconds(10),
        ]);

        $rule = FormationAuthorityPolicyRule::query()->create([
            'business_id' => $business->getKey(),
            'formal_record_version_id' => $version->getKey(),
            'sequence' => 1,
            'decision_type' => 'business_closure_approval',
            'decision_method' => DecisionMethod::Approval->value,
            'required_approvals' => 1,
            'required_votes' => 0,
            'quorum_count' => 1,
            'signature_required' => false,
            'reserved_matter' => true,
            'amount_min' => null,
            'amount_max' => null,
        ]);

        FormationAuthorityPolicyActor::query()->create([
            'business_id' => $business->getKey(),
            'formation_authority_policy_rule_id' => $rule->getKey(),
            'membership_id' => $approverMembership->getKey(),
            'capacity' => 'Formation Closure Approver',
            'can_approve' => true,
            'can_vote' => false,
            'can_sign' => false,
        ]);

        $version->frozen_at = now()->subSeconds(5);
        $version->save();

        RecordVersionStateTransition::query()->create([
            'business_id' => $business->getKey(),
            'formal_record_version_id' => $version->getKey(),
            'sequence' => 2,
            'from_state' => 'draft',
            'to_state' => 'ready_for_review',
            'transitioned_by_user_id' => $manager->getKey(),
            'occurred_at' => now(),
        ]);

        FormationAuthorityEstablishment::query()->create([
            'business_id' => $business->getKey(),
            'formal_record_version_id' => $version->getKey(),
            'established_by_membership_id' => $managerMembership->getKey(),
            'establishment_hash' => $version->content_hash,
            'established_at' => now(),
        ]);
    }

    /** @return array{User,Membership} */
    private function userMembership(
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
        $permission = Permission::query()
            ->firstOrCreate(['key' => $capability]);

        PermissionGrant::query()->firstOrCreate(
            [
                'business_id' => $business->getKey(),
                'membership_id' => $membership->getKey(),
                'permission_id' => $permission->getKey(),
            ],
            ['effect' => 'allow'],
        );

        foreach ($resourceTypes as $resourceType) {
            AccessPolicy::query()->firstOrCreate(
                [
                    'business_id' => $business->getKey(),
                    'membership_id' => $membership->getKey(),
                    'permission_profile_id' => null,
                    'permission_id' => $permission->getKey(),
                    'resource_type' => $resourceType,
                ],
                ['effect' => 'allow'],
            );
        }
    }
}
