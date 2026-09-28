<?php

declare(strict_types=1);

namespace Tests\Feature\Exit;

use App\Application\Access\ProvisionStandardAccessProfiles;
use App\Application\Exit\ExitCaseWorkflow;
use App\Application\Governance\CompleteProposalReview;
use App\Application\Governance\CreateProposalReview;
use App\Application\Governance\OpenGovernanceDecision;
use App\Application\Governance\RecordGovernanceApproval;
use App\Application\Governance\ResolveGovernanceDecision;
use App\Application\Partnership\OwnershipGovernanceWorkflow;
use App\Domain\Access\CapabilityCatalog;
use App\Domain\Exit\Enums\ExitCaseStatus;
use App\Domain\Exit\Enums\ExitTrigger;
use App\Domain\Exit\Enums\LeaverClassification;
use App\Domain\Governance\Enums\ApprovalOutcome;
use App\Domain\Governance\Enums\DecisionMethod;
use App\Domain\Governance\Enums\ProposalReviewOutcome;
use App\Domain\Governance\ValueObjects\DecisionType;
use App\Domain\Records\Enums\FormalRecordState;
use App\Infrastructure\Persistence\Eloquent\Access\AccessPolicy;
use App\Infrastructure\Persistence\Eloquent\Access\Permission;
use App\Infrastructure\Persistence\Eloquent\Access\PermissionGrant;
use App\Infrastructure\Persistence\Eloquent\Businesses\Business;
use App\Infrastructure\Persistence\Eloquent\Governance\Decision;
use App\Infrastructure\Persistence\Eloquent\Governance\FormationAuthorityEstablishment;
use App\Infrastructure\Persistence\Eloquent\Governance\FormationAuthorityPolicyActor;
use App\Infrastructure\Persistence\Eloquent\Governance\FormationAuthorityPolicyRule;
use App\Infrastructure\Persistence\Eloquent\Identity\User;
use App\Infrastructure\Persistence\Eloquent\Members\Membership;
use App\Infrastructure\Persistence\Eloquent\Records\FormalRecordFamily;
use App\Infrastructure\Persistence\Eloquent\Records\FormalRecordVersion;
use App\Infrastructure\Persistence\Eloquent\Records\RecordVersionStateTransition;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Tests\TestCase;

final class F7ExitHistoryIntegrityTest extends TestCase
{
    use RefreshDatabase;

    public function test_governed_exit_becomes_former_without_erasing_governance_history(): void
    {
        $context = $this->governedContext();
        $workflow = $this->app->make(ExitCaseWorkflow::class);

        $partnerId = $this->seedPartner(
            $context['business'],
            'Governed Exit Partner',
        );

        $case = $workflow->createCase(
            $context['manager'],
            $context['business'],
            $partnerId,
            ExitTrigger::Voluntary,
            'partner_exit_approval',
            'Governed exit history test.',
            CarbonImmutable::now()->subMinute(),
        );

        self::assertNotNull($case);

        $case = $workflow->recordNotice(
            $context['manager'],
            $context['business'],
            (string) $case->getKey(),
            1,
            CarbonImmutable::now()->subDays(30)->startOfDay(),
            CarbonImmutable::now()->subMinute(),
            30,
            'Thirty-day Exit Notice completed.',
        );

        self::assertNotNull($case);
        self::assertSame(ExitCaseStatus::NoticeRecorded, $case->status);

        $case = $workflow->recordShareTreatment(
            $context['manager'],
            $context['business'],
            (string) $case->getKey(),
            2,
            'no_shares',
            LeaverClassification::NotApplicable,
            null,
            null,
            null,
            null,
            null,
            null,
            null,
            null,
            null,
        );

        self::assertNotNull($case);
        self::assertSame(ExitCaseStatus::TreatmentReady, $case->status);

        $case = $workflow->recordPaymentTerms(
            $context['manager'],
            $context['business'],
            (string) $case->getKey(),
            3,
            null,
            null,
            null,
            null,
            null,
            null,
            null,
            null,
            null,
            null,
            null,
            'not_applicable',
            null,
        );

        self::assertNotNull($case);
        self::assertSame(4, (int) $case->revision);

        foreach ([
            ['legal', 'legal_terms_ready'],
            ['handover', 'handover_plan_ready'],
            ['post_exit', 'post_exit_obligations_recorded'],
        ] as [$type, $key]) {
            self::assertTrue($workflow->recordRequirement(
                $context['manager'],
                $context['business'],
                (string) $case->getKey(),
                4,
                $type,
                $key,
                'met',
                'Verified before Governance.',
            ));
        }

        $case = $workflow->transition(
            $context['manager'],
            $context['business'],
            (string) $case->getKey(),
            4,
            ExitCaseStatus::TermsReady,
        );

        self::assertNotNull($case);
        self::assertSame(5, (int) $case->revision);

        $submission = $workflow->submitGovernance(
            $context['manager'],
            $context['business'],
            (string) $case->getKey(),
            5,
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
                'Exit proposal reviewed against exact frozen version.',
            ),
        );

        $decision = $this->app->make(OpenGovernanceDecision::class)->execute(
            $context['manager'],
            $context['business'],
            $submission['proposal_version_id'],
            new DecisionType('partner_exit_approval'),
        );

        self::assertInstanceOf(Decision::class, $decision);
        $authoritySnapshotId = (string) $decision->authority_snapshot_id;

        self::assertNotNull(
            $this->app->make(RecordGovernanceApproval::class)->execute(
                $context['approver'],
                $context['business'],
                (string) $decision->getKey(),
                ApprovalOutcome::Approved,
                'Approve the exact frozen Exit proposal.',
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
            6,
        );

        self::assertNotNull($case);
        self::assertSame(ExitCaseStatus::Approved, $case->status);
        self::assertSame(7, (int) $case->revision);

        foreach ([
            ['legal', 'legal_documentation_complete'],
            ['operations', 'operations_handover_complete'],
            ['continuity', 'continuity_update_complete'],
            ['handover', 'business_property_returned'],
            ['handover', 'documents_handed_over'],
        ] as [$type, $key]) {
            self::assertTrue($workflow->recordRequirement(
                $context['manager'],
                $context['business'],
                (string) $case->getKey(),
                7,
                $type,
                $key,
                'met',
                'Verified before Exit effect/completion.',
            ));
        }

        $case = $workflow->prepareForEffect(
            $context['manager'],
            $context['business'],
            (string) $case->getKey(),
            7,
        );

        self::assertNotNull($case);
        self::assertSame(ExitCaseStatus::ReadyForEffect, $case->status);
        self::assertSame(8, (int) $case->revision);

        $case = $workflow->effect(
            $context['manager'],
            $context['business'],
            (string) $case->getKey(),
            8,
        );

        self::assertNotNull($case);
        self::assertSame(ExitCaseStatus::Effective, $case->status);
        self::assertSame(9, (int) $case->revision);

        $case = $workflow->complete(
            $context['manager'],
            $context['business'],
            (string) $case->getKey(),
            9,
        );

        self::assertNotNull($case);
        self::assertSame(ExitCaseStatus::Completed, $case->status);
        self::assertSame('not_applicable', $case->settlement_status);

        self::assertSame(
            'former',
            DB::table('partners')
                ->where('id', $partnerId)
                ->value('status'),
        );

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

        self::assertDatabaseHas('proposal_reviews', [
            'id' => $review->getKey(),
            'business_id' => $context['business']->getKey(),
            'proposal_version_id' => $submission['proposal_version_id'],
            'status' => 'completed',
            'outcome' => 'approved',
        ]);

        self::assertDatabaseHas('exit_governance_submissions', [
            'business_id' => $context['business']->getKey(),
            'exit_case_id' => $case->getKey(),
            'formal_record_version_id' => $submission['formal_record_version_id'],
            'proposal_version_id' => $submission['proposal_version_id'],
            'decision_id' => $decision->getKey(),
        ]);

        self::assertDatabaseHas('partner_lifecycle_transitions', [
            'business_id' => $context['business']->getKey(),
            'partner_id' => $partnerId,
            'from_status' => 'exiting',
            'to_status' => 'former',
            'source_type' => 'exit_case',
            'source_id' => $case->getKey(),
        ]);
    }

    public function test_opening_exit_captures_exact_current_effective_ownership_baseline(): void
    {
        $context = $this->governedContext();
        $partnerId = $this->seedPartner(
            $context['business'],
            'Owned Exit Partner',
        );

        $sourceRegisterId = $this->seedEffectiveOwnership(
            $context,
            $partnerId,
        );

        $sourcePosition = DB::table('ownership_register_positions')
            ->where('business_id', $context['business']->getKey())
            ->where(
                'ownership_register_version_id',
                $sourceRegisterId,
            )
            ->where('partner_id', $partnerId)
            ->sole();

        $case = $this->app->make(ExitCaseWorkflow::class)->createCase(
            $context['manager'],
            $context['business'],
            $partnerId,
            ExitTrigger::Retirement,
            'partner_exit_approval',
            'Capture exact current Effective Ownership truth.',
        );

        self::assertNotNull($case);
        self::assertSame(
            $sourceRegisterId,
            (string) $case->source_ownership_register_version_id,
        );
        self::assertSame(
            'active',
            DB::table('partners')
                ->where('id', $partnerId)
                ->value('status'),
            'Opening Exit must not change Partner lifecycle truth.',
        );

        $snapshot = DB::table('exit_share_positions')
            ->where('business_id', $context['business']->getKey())
            ->where('exit_case_id', $case->getKey())
            ->where('partner_id', $partnerId)
            ->sole();

        self::assertSame(
            $sourceRegisterId,
            (string) $snapshot->source_ownership_register_version_id,
        );
        self::assertSame(
            (string) $sourcePosition->share_class_id,
            (string) $snapshot->source_share_class_id,
        );
        self::assertSame(
            (string) $sourcePosition->shares_issued,
            (string) $snapshot->shares_issued,
        );
        self::assertSame(
            (string) $sourcePosition->shares_vested,
            (string) $snapshot->shares_vested,
        );
        self::assertSame(
            (string) $sourcePosition->voting_rights,
            (string) $snapshot->voting_rights,
        );
        self::assertSame(
            (string) $sourcePosition->profit_rights,
            (string) $snapshot->profit_rights,
        );

        $sourceAfter = DB::table('ownership_register_positions')
            ->where('id', $sourcePosition->id)
            ->sole();

        self::assertSame(
            (string) $sourcePosition->shares_issued,
            (string) $sourceAfter->shares_issued,
            'Opening Exit must not mutate Effective Ownership.',
        );
    }

    public function test_exit_history_tables_are_database_append_only(): void
    {
        $triggers = [
            'membership_access_transitions_append_only',
            'exit_case_transitions_append_only',
            'exit_share_positions_append_only',
            'exit_requirements_append_only',
            'exit_finance_links_append_only',
            'exit_record_versions_append_only',
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
    }

    /** @return array<string,mixed> */
    private function governedContext(): array
    {
        $business = Business::query()->create([
            'name' => 'F7 Exit Governance '.Str::uuid7(),
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
            'change_summary' => 'F7 Exit temporary Formation Authority.',
            'created_by_user_id' => $manager->getKey(),
            'last_changed_by_user_id' => $manager->getKey(),
            'effective_from' => now()->subMinute(),
            'effective_until' => null,
            'review_due_at' => null,
            'content_hash' => str_repeat('e', 64),
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

        foreach ([
            1 => 'ownership_approval',
            2 => 'partner_exit_approval',
        ] as $sequence => $decisionType) {
            $rule = FormationAuthorityPolicyRule::query()->create([
                'business_id' => $business->getKey(),
                'formal_record_version_id' => $version->getKey(),
                'sequence' => $sequence,
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
                'membership_id' => $approverMembership->getKey(),
                'capacity' => 'Formation Approver',
                'can_approve' => true,
                'can_vote' => false,
                'can_sign' => false,
            ]);
        }

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

    /** @param array<string,mixed> $context */
    private function seedEffectiveOwnership(
        array $context,
        string $partnerId,
    ): string {
        $business = $context['business'];
        $manager = $context['manager'];
        $managerMembership = $context['managerMembership'];
        $approver = $context['approver'];

        $scenarioId = (string) Str::uuid7();
        $classId = (string) Str::uuid7();

        DB::table('ownership_scenarios')->insert([
            'id' => $scenarioId,
            'business_id' => $business->getKey(),
            'name' => 'F7 Exit Effective Ownership',
            'currency' => 'USD',
            'share_value_minor_units' => 10_000,
            'authorized_shares' => '1000',
            'reserved_unissued_shares' => '0',
            'status' => 'draft',
            'revision' => 1,
            'frozen_at' => null,
            'created_by_membership_id' => $managerMembership->getKey(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('ownership_scenario_share_classes')->insert([
            'id' => $classId,
            'business_id' => $business->getKey(),
            'ownership_scenario_id' => $scenarioId,
            'name' => 'Ordinary',
            'voting_right_per_share' => '1',
            'profit_right_per_share' => '0.5',
            'transfer_allowed' => true,
            'restrictions' => 'Governed transfer only.',
            'special_rights' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('ownership_scenario_positions')->insert([
            'id' => (string) Str::uuid7(),
            'business_id' => $business->getKey(),
            'ownership_scenario_id' => $scenarioId,
            'partner_id' => $partnerId,
            'share_class_id' => $classId,
            'accepted_contribution_minor_units' => 0,
            'shares_issued' => '100',
            'shares_vested' => '80',
            'voting_rights' => '100',
            'profit_rights' => '50',
            'issue_date' => now()->subYear()->toDateString(),
            'vesting_start_date' => now()->subYear()->toDateString(),
            'vesting_period_months' => 24,
            'vesting_cliff_months' => 0,
            'vesting_conditions' => 'Time based.',
            'early_exit_treatment' => 'Governed by approved Exit terms.',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('ownership_scenarios')
            ->where('id', $scenarioId)
            ->where('business_id', $business->getKey())
            ->update([
                'status' => 'frozen',
                'revision' => 2,
                'frozen_at' => now(),
                'updated_at' => now(),
            ]);

        $ownership = $this->app->make(
            OwnershipGovernanceWorkflow::class,
        );

        $submission = $ownership->submitGovernance(
            $manager,
            $business,
            $scenarioId,
            CarbonImmutable::now()->subMinutes(2),
        );

        self::assertNotNull($submission);
        self::assertTrue($ownership->advanceContentReview(
            $manager,
            $business,
            $submission['id'],
            FormalRecordState::UnderReview,
        ));
        self::assertTrue($ownership->advanceContentReview(
            $manager,
            $business,
            $submission['id'],
            FormalRecordState::Approved,
        ));

        $review = $this->app->make(CreateProposalReview::class)->execute(
            $manager,
            $business,
            $submission['proposal_version_id'],
            (string) $managerMembership->getKey(),
        );

        self::assertNotNull($review);
        self::assertNotNull(
            $this->app->make(CompleteProposalReview::class)->execute(
                $manager,
                $business,
                (string) $review->getKey(),
                ProposalReviewOutcome::Approved,
                'Exact Ownership baseline reviewed before Exit capture.',
            ),
        );

        $decision = $this->app->make(OpenGovernanceDecision::class)->execute(
            $manager,
            $business,
            $submission['proposal_version_id'],
            new DecisionType('ownership_approval'),
        );

        self::assertInstanceOf(Decision::class, $decision);
        self::assertNotNull(
            $this->app->make(RecordGovernanceApproval::class)->execute(
                $approver,
                $business,
                (string) $decision->getKey(),
                ApprovalOutcome::Approved,
                'Approve exact Ownership baseline for Exit capture test.',
            ),
        );
        self::assertNotNull(
            $this->app->make(ResolveGovernanceDecision::class)->approve(
                $manager,
                $business,
                (string) $decision->getKey(),
            ),
        );
        self::assertTrue(
            $ownership->effectApprovedGovernance(
                $manager,
                $business,
                $submission['id'],
            ),
        );

        $registerId = DB::table('ownership_register_versions')
            ->where('business_id', $business->getKey())
            ->where('source_ownership_scenario_id', $scenarioId)
            ->where('status', 'effective')
            ->value('id');

        self::assertNotNull($registerId);

        return (string) $registerId;
    }

    private function seedPartner(Business $business, string $name): string
    {
        $id = (string) Str::uuid7();

        DB::table('partners')->insert([
            'id' => $id,
            'business_id' => $business->getKey(),
            'display_name' => $name,
            'legal_name' => null,
            'email' => null,
            'notes' => null,
            'status' => 'active',
            'revision' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $id;
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
