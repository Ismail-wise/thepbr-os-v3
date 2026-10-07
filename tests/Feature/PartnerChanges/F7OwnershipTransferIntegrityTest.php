<?php

declare(strict_types=1);

namespace Tests\Feature\PartnerChanges;

use App\Application\Access\ProvisionStandardAccessProfiles;
use App\Application\Governance\CompleteProposalReview;
use App\Application\Governance\CreateProposalReview;
use App\Application\Governance\OpenGovernanceDecision;
use App\Application\Governance\RecordGovernanceApproval;
use App\Application\Governance\ResolveGovernanceDecision;
use App\Application\PartnerChanges\PartnerChangeWorkflow;
use App\Application\Partnership\OwnershipGovernanceWorkflow;
use App\Application\Partnership\OwnershipWorkflow;
use App\Domain\Access\CapabilityCatalog;
use App\Domain\Governance\Enums\ApprovalOutcome;
use App\Domain\Governance\Enums\DecisionMethod;
use App\Domain\Governance\Enums\ProposalReviewOutcome;
use App\Domain\Governance\ValueObjects\DecisionType;
use App\Domain\PartnerChanges\Enums\PartnerChangeEligibilityStatus;
use App\Domain\PartnerChanges\Enums\PartnerChangeStatus;
use App\Domain\PartnerChanges\Enums\PartnerChangeTransactionType;
use App\Domain\PartnerChanges\Enums\RofrResponseStatus;
use App\Domain\Partnership\ValueObjects\ShareQuantity;
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
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Tests\TestCase;

final class F7OwnershipTransferIntegrityTest extends TestCase
{
    use RefreshDatabase;

    public function test_governed_transfer_creates_new_register_and_preserves_prior_history(): void
    {
        $context = $this->ownershipContext();

        $workflow = $this->app->make(PartnerChangeWorkflow::class);
        $source = $this->app
            ->make(OwnershipWorkflow::class)
            ->currentEffectiveRegisterVersion(
                $context['manager'],
                $context['business'],
                CarbonImmutable::now(),
            );

        self::assertNotNull($source);

        $sourceClass = DB::table('ownership_register_share_classes')
            ->where('business_id', $context['business']->getKey())
            ->where('ownership_register_version_id', $source->id)
            ->where('name', 'Ordinary')
            ->sole();

        $sourcePosition = DB::table('ownership_register_positions')
            ->where('business_id', $context['business']->getKey())
            ->where('ownership_register_version_id', $source->id)
            ->where('partner_id', $context['sellerId'])
            ->where('share_class_id', $sourceClass->id)
            ->sole();

        self::assertSame('100.00000000', (string) $sourcePosition->shares_issued);
        self::assertSame('100.00000000', (string) $sourcePosition->shares_vested);
        self::assertSame('100.00000000', (string) $sourcePosition->voting_rights);
        self::assertSame('50.00000000', (string) $sourcePosition->profit_rights);

        $case = $workflow->createCase(
            $context['manager'],
            $context['business'],
            PartnerChangeTransactionType::TransferExisting,
            $context['buyerId'],
            $context['sellerId'],
            (string) $sourceClass->id,
            new ShareQuantity('25'),
            'USD',
            2_500_00,
            'Agreed transfer valuation',
            'Voting and profit rights move pro-rata with the transferred Ordinary shares.',
            'partner_change_approval',
            true,
            CarbonImmutable::now()->subMinute(),
        );

        self::assertNotNull($case);
        self::assertSame(
            (string) $source->id,
            (string) $case->source_ownership_register_version_id,
        );

        $case = $workflow->transition(
            $context['manager'],
            $context['business'],
            (string) $case->getKey(),
            1,
            PartnerChangeStatus::EligibilityReview,
        );

        self::assertNotNull($case);

        foreach ([
            'vesting_and_restrictions',
            'obligations_clear',
            'pledge_lien_clear',
            'agreement_conflict_clear',
            'buyer_eligible',
        ] as $check) {
            self::assertTrue(
                $workflow->recordEligibility(
                    $context['manager'],
                    $context['business'],
                    (string) $case->getKey(),
                    2,
                    $check,
                    PartnerChangeEligibilityStatus::Met,
                    'Verified for governed transfer.',
                ),
            );
        }

        $case = $workflow->transition(
            $context['manager'],
            $context['business'],
            (string) $case->getKey(),
            2,
            PartnerChangeStatus::Eligible,
        );

        self::assertNotNull($case);

        $case = $workflow->transition(
            $context['manager'],
            $context['business'],
            (string) $case->getKey(),
            3,
            PartnerChangeStatus::Rofr,
        );

        self::assertNotNull($case);

        try {
            $workflow->transition(
                $context['manager'],
                $context['business'],
                (string) $case->getKey(),
                4,
                PartnerChangeStatus::TermsReady,
            );
            self::fail('Required ROFR must not be bypassed.');
        } catch (InvalidArgumentException $exception) {
            self::assertStringContainsString(
                'ROFR',
                $exception->getMessage(),
            );
        }

        $roundId = $workflow->openRofrRound(
            $context['manager'],
            $context['business'],
            (string) $case->getKey(),
            4,
            'Existing partners may match the exact transfer terms.',
            CarbonImmutable::now()->addDay(),
        );

        self::assertNotNull($roundId);

        self::assertTrue(
            $workflow->recordRofrResponse(
                $context['manager'],
                $context['business'],
                (string) $case->getKey(),
                4,
                $roundId,
                $context['rofrHolderId'],
                RofrResponseStatus::Decline,
                'Declined exact offered terms.',
            ),
        );

        self::assertTrue(
            $workflow->completeRofrRound(
                $context['manager'],
                $context['business'],
                (string) $case->getKey(),
                4,
                $roundId,
            ),
        );

        $case = $workflow->transition(
            $context['manager'],
            $context['business'],
            (string) $case->getKey(),
            4,
            PartnerChangeStatus::TermsReady,
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

        self::assertTrue(
            $workflow->advanceContentReview(
                $context['manager'],
                $context['business'],
                (string) $case->getKey(),
                $submission['formal_record_version_id'],
                FormalRecordState::UnderReview,
            ),
        );

        self::assertTrue(
            $workflow->advanceContentReview(
                $context['manager'],
                $context['business'],
                (string) $case->getKey(),
                $submission['formal_record_version_id'],
                FormalRecordState::Approved,
            ),
        );

        $review = $this->app
            ->make(CreateProposalReview::class)
            ->execute(
                $context['manager'],
                $context['business'],
                $submission['proposal_version_id'],
                (string) $context['managerMembership']->getKey(),
            );

        self::assertNotNull($review);

        $completedReview = $this->app
            ->make(CompleteProposalReview::class)
            ->execute(
                $context['manager'],
                $context['business'],
                (string) $review->getKey(),
                ProposalReviewOutcome::Approved,
                'Exact Partner Change proposal reviewed.',
            );

        self::assertNotNull($completedReview);

        $decision = $this->app
            ->make(OpenGovernanceDecision::class)
            ->execute(
                $context['manager'],
                $context['business'],
                $submission['proposal_version_id'],
                new DecisionType('partner_change_approval'),
            );

        self::assertInstanceOf(Decision::class, $decision);

        $approval = $this->app
            ->make(RecordGovernanceApproval::class)
            ->execute(
                $context['approver'],
                $context['business'],
                (string) $decision->getKey(),
                ApprovalOutcome::Approved,
                'Approved exact frozen Partner Change proposal.',
            );

        self::assertNotNull($approval);

        $resolved = $this->app
            ->make(ResolveGovernanceDecision::class)
            ->approve(
                $context['manager'],
                $context['business'],
                (string) $decision->getKey(),
            );

        self::assertNotNull($resolved);

        $case = $workflow->syncDecision(
            $context['manager'],
            $context['business'],
            (string) $case->getKey(),
            6,
        );

        self::assertNotNull($case);
        self::assertSame(PartnerChangeStatus::Approved, $case->status);
        self::assertSame(7, (int) $case->revision);

        self::assertTrue(
            $workflow->recordRequirement(
                $context['manager'],
                $context['business'],
                (string) $case->getKey(),
                7,
                'legal_document',
                'legal_documentation_complete',
                PartnerChangeEligibilityStatus::Met,
                'Exact transfer documentation completed.',
            ),
        );

        $case = $workflow->prepareForEffect(
            $context['manager'],
            $context['business'],
            (string) $case->getKey(),
            7,
        );

        self::assertNotNull($case);
        self::assertSame(
            PartnerChangeStatus::ReadyForEffect,
            $case->status,
        );
        self::assertSame(8, (int) $case->revision);

        $case = $workflow->effect(
            $context['manager'],
            $context['business'],
            (string) $case->getKey(),
            8,
        );

        self::assertNotNull($case);
        self::assertSame(PartnerChangeStatus::Completed, $case->status);
        self::assertSame(10, (int) $case->revision);

        $old = DB::table('ownership_register_versions')
            ->where('id', $source->id)
            ->sole();

        self::assertSame('superseded', (string) $old->status);
        self::assertNotNull($old->effective_until);

        $new = DB::table('ownership_register_versions')
            ->where('business_id', $context['business']->getKey())
            ->where('status', 'effective')
            ->sole();

        self::assertNotSame((string) $source->id, (string) $new->id);
        self::assertSame(
            ((int) $source->version_number) + 1,
            (int) $new->version_number,
        );
        self::assertSame(
            $submission['proposal_version_id'],
            (string) $new->proposal_version_id,
        );
        self::assertSame(
            (string) $decision->getKey(),
            (string) $new->governance_decision_id,
        );
        self::assertSame(
            (string) $decision->authority_snapshot_id,
            (string) $new->authority_snapshot_id,
        );

        $newClass = DB::table('ownership_register_share_classes')
            ->where('business_id', $context['business']->getKey())
            ->where('ownership_register_version_id', $new->id)
            ->where('name', 'Ordinary')
            ->sole();

        $seller = DB::table('ownership_register_positions')
            ->where('ownership_register_version_id', $new->id)
            ->where('partner_id', $context['sellerId'])
            ->where('share_class_id', $newClass->id)
            ->sole();

        $buyer = DB::table('ownership_register_positions')
            ->where('ownership_register_version_id', $new->id)
            ->where('partner_id', $context['buyerId'])
            ->where('share_class_id', $newClass->id)
            ->sole();

        self::assertSame('75.00000000', (string) $seller->shares_issued);
        self::assertSame('75.00000000', (string) $seller->shares_vested);
        self::assertFalse((bool) $seller->vesting_applies);
        self::assertSame('75.00000000', (string) $seller->voting_rights);
        self::assertSame('37.50000000', (string) $seller->profit_rights);

        self::assertSame('25.00000000', (string) $buyer->shares_issued);
        self::assertSame('25.00000000', (string) $buyer->shares_vested);
        self::assertFalse((bool) $buyer->vesting_applies);
        self::assertSame('25.00000000', (string) $buyer->voting_rights);
        self::assertSame('12.50000000', (string) $buyer->profit_rights);

        $carriedIssuanceRule = DB::table('ownership_register_issuance_rules')
            ->where('business_id', $context['business']->getKey())
            ->where('ownership_register_version_id', $new->id)
            ->sole();

        self::assertSame(
            '75.00',
            (string) $carriedIssuanceRule->approval_threshold_percent,
        );
        self::assertTrue((bool) $carriedIssuanceRule->preemption_right);
        self::assertTrue((bool) $carriedIssuanceRule->dilution_acknowledged);
        $oldPositionAfter = DB::table('ownership_register_positions')
            ->where('id', $sourcePosition->id)
            ->sole();

        self::assertSame(
            '100.00000000',
            (string) $oldPositionAfter->shares_issued,
        );
        self::assertSame(
            '50.00000000',
            (string) $oldPositionAfter->profit_rights,
        );

        self::assertDatabaseHas('ownership_register_transfer_sources', [
            'business_id' => $context['business']->getKey(),
            'ownership_register_version_id' => $new->id,
            'partner_change_case_id' => $case->getKey(),
            'source_ownership_register_version_id' => $source->id,
            'seller_partner_id' => $context['sellerId'],
            'buyer_partner_id' => $context['buyerId'],
            'source_share_class_id' => $sourceClass->id,
            'shares_transferred' => '25.00000000',
        ]);

        $current = $this->app
            ->make(OwnershipWorkflow::class)
            ->currentEffectiveRegisterVersion(
                $context['manager'],
                $context['business'],
                CarbonImmutable::now(),
            );

        self::assertNotNull($current);
        self::assertSame((string) $new->id, (string) $current->id);

        try {
            DB::table('ownership_register_positions')
                ->where('id', $sourcePosition->id)
                ->update(['shares_issued' => '99']);
            self::fail('Prior Effective Ownership snapshot must be immutable.');
        } catch (QueryException $exception) {
            self::assertStringContainsString(
                'Ownership Register snapshot content is immutable',
                $exception->getMessage(),
            );
        }
    }

    public function test_ownership_change_draft_can_recover_required_terms_before_eligibility(): void
    {
        $context = $this->ownershipContext();

        $workflow = $this->app->make(PartnerChangeWorkflow::class);

        $source = $this->app
            ->make(OwnershipWorkflow::class)
            ->currentEffectiveRegisterVersion(
                $context['manager'],
                $context['business'],
                CarbonImmutable::now(),
            );

        self::assertNotNull($source);

        $sourceClass = DB::table('ownership_register_share_classes')
            ->where('business_id', $context['business']->getKey())
            ->where('ownership_register_version_id', $source->id)
            ->where('name', 'Ordinary')
            ->sole();

        $case = $workflow->createCase(
            $context['manager'],
            $context['business'],
            PartnerChangeTransactionType::TransferExisting,
            $context['buyerId'],
            $context['sellerId'],
            (string) $sourceClass->id,
            new ShareQuantity('10'),
            'USD',
            null,
            null,
            null,
            'partner_change_approval',
            false,
            CarbonImmutable::now()->subMinute(),
        );

        self::assertNotNull($case);
        self::assertSame(1, (int) $case->revision);

        try {
            $workflow->transition(
                $context['manager'],
                $context['business'],
                (string) $case->getKey(),
                1,
                PartnerChangeStatus::EligibilityReview,
            );

            self::fail(
                'Incomplete ownership-changing Draft must not enter Eligibility Review.',
            );
        } catch (InvalidArgumentException $exception) {
            self::assertStringContainsString(
                'valuation method',
                $exception->getMessage(),
            );
        }

        $case->refresh();

        self::assertSame(PartnerChangeStatus::Draft, $case->status);
        self::assertSame(1, (int) $case->revision);

        $updated = $workflow->updateDraftTerms(
            $context['manager'],
            $context['business'],
            (string) $case->getKey(),
            1,
            'USD',
            null,
            'Recovered Draft valuation basis',
            'Recovered Draft rights impact summary.',
            false,
            CarbonImmutable::now()->subMinute(),
        );

        self::assertNotNull($updated);
        self::assertSame(PartnerChangeStatus::Draft, $updated->status);
        self::assertSame(2, (int) $updated->revision);
        self::assertSame(
            'Recovered Draft valuation basis',
            $updated->valuation_method,
        );
        self::assertSame(
            'Recovered Draft rights impact summary.',
            $updated->rights_impact_summary,
        );

        $review = $workflow->transition(
            $context['manager'],
            $context['business'],
            (string) $case->getKey(),
            2,
            PartnerChangeStatus::EligibilityReview,
        );

        self::assertNotNull($review);
        self::assertSame(
            PartnerChangeStatus::EligibilityReview,
            $review->status,
        );
        self::assertSame(3, (int) $review->revision);

        try {
            $workflow->updateDraftTerms(
                $context['manager'],
                $context['business'],
                (string) $case->getKey(),
                3,
                'USD',
                null,
                'Late edit',
                'Late edit',
                false,
                CarbonImmutable::now()->subMinute(),
            );

            self::fail(
                'Non-Draft Partner Change must not accept Draft edits.',
            );
        } catch (InvalidArgumentException $exception) {
            self::assertSame(
                'Only a Draft Partner Change may edit draft terms.',
                $exception->getMessage(),
            );
        }
    }

    public function test_partner_change_history_guards_exist(): void
    {
        $count = (int) DB::selectOne(
            <<<'SQL'
SELECT COUNT(*)::int AS count
FROM pg_trigger
WHERE tgname IN (
    'partner_lifecycle_transitions_append_only',
    'partner_change_eligibility_append_only',
    'partner_change_rofr_response_append_only',
    'partner_change_requirements_append_only',
    'partner_change_record_versions_append_only',
    'ownership_transfer_sources_append_only'
)
AND NOT tgisinternal
SQL
        )->count;

        self::assertSame(6, $count);
    }

    /** @return array<string,mixed> */
    private function ownershipContext(): array
    {
        $business = Business::query()->create([
            'name' => 'F7 Ownership Transfer '.Str::uuid7(),
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

        $sellerId = $this->seedPartner(
            $business,
            'Seller Partner',
            'active',
        );
        $buyerId = $this->seedPartner(
            $business,
            'Buyer Partner',
            'active',
        );
        $rofrHolderId = $this->seedPartner(
            $business,
            'ROFR Holder',
            'active',
        );

        $scenarioId = $this->seedFrozenOwnershipScenario(
            $business,
            $managerMembership,
            $sellerId,
        );

        $submission = $this->app
            ->make(OwnershipGovernanceWorkflow::class)
            ->submitGovernance(
                $manager,
                $business,
                $scenarioId,
                CarbonImmutable::now()->subMinutes(2),
            );

        self::assertNotNull($submission);

        $ownershipGovernance = $this->app
            ->make(OwnershipGovernanceWorkflow::class);

        self::assertTrue(
            $ownershipGovernance->advanceContentReview(
                $manager,
                $business,
                $submission['id'],
                FormalRecordState::UnderReview,
            ),
        );

        self::assertTrue(
            $ownershipGovernance->advanceContentReview(
                $manager,
                $business,
                $submission['id'],
                FormalRecordState::Approved,
            ),
        );

        $review = $this->app
            ->make(CreateProposalReview::class)
            ->execute(
                $manager,
                $business,
                $submission['proposal_version_id'],
                (string) $managerMembership->getKey(),
            );

        self::assertNotNull($review);

        self::assertNotNull(
            $this->app
                ->make(CompleteProposalReview::class)
                ->execute(
                    $manager,
                    $business,
                    (string) $review->getKey(),
                    ProposalReviewOutcome::Approved,
                    'Initial Ownership register reviewed.',
                ),
        );
        $decision = $this->app
            ->make(OpenGovernanceDecision::class)
            ->execute(
                $manager,
                $business,
                $submission['proposal_version_id'],
                new DecisionType('ownership_approval'),
            );

        self::assertInstanceOf(Decision::class, $decision);

        self::assertNotNull(
            $this->app
                ->make(RecordGovernanceApproval::class)
                ->execute(
                    $approver,
                    $business,
                    (string) $decision->getKey(),
                    ApprovalOutcome::Approved,
                    'Approve initial Ownership.',
                ),
        );

        self::assertNotNull(
            $this->app
                ->make(ResolveGovernanceDecision::class)
                ->approve(
                    $manager,
                    $business,
                    (string) $decision->getKey(),
                ),
        );

        self::assertTrue(
            $ownershipGovernance->effectApprovedGovernance(
                $manager,
                $business,
                $submission['id'],
            ),
        );

        return [
            'business' => $business,
            'manager' => $manager,
            'managerMembership' => $managerMembership,
            'approver' => $approver,
            'approverMembership' => $approverMembership,
            'sellerId' => $sellerId,
            'buyerId' => $buyerId,
            'rofrHolderId' => $rofrHolderId,
            'scenarioId' => $scenarioId,
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
            'change_summary' => 'F7 temporary Formation Authority.',
            'created_by_user_id' => $manager->getKey(),
            'last_changed_by_user_id' => $manager->getKey(),
            'effective_from' => now()->subMinute(),
            'effective_until' => null,
            'review_due_at' => null,
            'content_hash' => str_repeat('7', 64),
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
            2 => 'partner_change_approval',
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

    private function seedFrozenOwnershipScenario(
        Business $business,
        Membership $managerMembership,
        string $sellerId,
    ): string {
        $scenarioId = (string) Str::uuid7();
        $classId = (string) Str::uuid7();

        DB::table('ownership_scenarios')->insert([
            'id' => $scenarioId,
            'business_id' => $business->getKey(),
            'name' => 'F7 Initial Ownership',
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
            'restrictions' => 'ROFR where the governed transfer case requires it.',
            'special_rights' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('ownership_scenario_positions')->insert([
            'id' => (string) Str::uuid7(),
            'business_id' => $business->getKey(),
            'ownership_scenario_id' => $scenarioId,
            'partner_id' => $sellerId,
            'share_class_id' => $classId,
            'accepted_contribution_minor_units' => 0,
            'shares_issued' => '100',
            'shares_vested' => '100',
            'vesting_applies' => false,
            'voting_rights' => '100',
            'profit_rights' => '50',
            'issue_date' => now()->subYear()->toDateString(),
            'vesting_start_date' => null,
            'vesting_period_months' => null,
            'vesting_cliff_months' => null,
            'vesting_conditions' => null,
            'early_exit_treatment' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('ownership_scenario_issuance_rules')->insert([
            'id' => (string) Str::uuid7(),
            'business_id' => $business->getKey(),
            'ownership_scenario_id' => $scenarioId,
            'approval_rule' => 'Governed approval required for new shares.',
            'approval_threshold_percent' => '75',
            'preemption_right' => true,
            'valuation_method' => 'Independent agreed valuation.',
            'dilution_acknowledged' => true,
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

        return $scenarioId;
    }

    private function seedPartner(
        Business $business,
        string $name,
        string $status,
    ): string {
        $id = (string) Str::uuid7();

        DB::table('partners')->insert([
            'id' => $id,
            'business_id' => $business->getKey(),
            'display_name' => $name,
            'legal_name' => null,
            'email' => null,
            'notes' => null,
            'status' => $status,
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
