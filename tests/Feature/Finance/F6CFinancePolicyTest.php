<?php

declare(strict_types=1);

namespace Tests\Feature\Finance;

use App\Application\Finance\FinancePolicyWorkflow;
use App\Application\Governance\CompleteProposalReview;
use App\Application\Governance\CreateProposalReview;
use App\Application\Governance\OpenGovernanceDecision;
use App\Application\Governance\RecordGovernanceApproval;
use App\Application\Governance\ResolveGovernanceDecision;
use App\Application\Partnership\OwnershipGovernanceWorkflow;
use App\Application\Rewards\RewardPolicyWorkflow;
use App\Domain\Access\CapabilityCatalog;
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
use App\Infrastructure\Persistence\Eloquent\Governance\ProposalReview;
use App\Infrastructure\Persistence\Eloquent\Identity\User;
use App\Infrastructure\Persistence\Eloquent\Members\Membership;
use App\Infrastructure\Persistence\Eloquent\Records\FormalRecordFamily;
use App\Infrastructure\Persistence\Eloquent\Records\FormalRecordVersion;
use App\Infrastructure\Persistence\Eloquent\Records\Proposal;
use App\Infrastructure\Persistence\Eloquent\Records\ProposalVersion;
use App\Infrastructure\Persistence\Eloquent\Records\RecordVersionStateTransition;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Tests\TestCase;

trait F6CFixtureSupport
{
    /** @return array<string,mixed> */
    protected function f6cContext(): array
    {
        $business = Business::query()->create([
            'name' => 'F6C '.Str::uuid7(),
            'origin_type' => 'started_through_pbr',
            'business_stage' => 'planning',
            'setup_phase' => 'formation',
            'workspace_status' => 'active',
            'base_currency' => 'USD',
        ]);

        [$user, $membership] = $this->f6cMember($business, 'manager');
        [$payerUser, $payer] = $this->f6cMember($business, 'payer');
        [$approverUser, $approver] = $this->f6cMember(
            $business,
            'governance-approver',
        );

        foreach ([
            CapabilityCatalog::RECORDS_MANAGE,
            CapabilityCatalog::RECORDS_VIEW,
            CapabilityCatalog::FINANCE_MANAGE,
            CapabilityCatalog::FINANCE_VIEW,
            CapabilityCatalog::FINANCE_PAY,
            CapabilityCatalog::REWARDS_MANAGE,
            CapabilityCatalog::REWARDS_VIEW,
            CapabilityCatalog::GOVERNANCE_RECORDS_MANAGE,
        ] as $capability) {
            $this->f6cGrant(
                $business,
                $membership,
                $capability,
                $capability === CapabilityCatalog::RECORDS_MANAGE
                    ? [FormalRecordFamily::class, FormalRecordVersion::class, Proposal::class]
                    : [],
            );
        }

        $this->f6cGrant($business, $payer, CapabilityCatalog::FINANCE_PAY, []);
        $this->f6cGrant(
            $business,
            $membership,
            CapabilityCatalog::GOVERNANCE_RECORDS_MANAGE,
            [
                ProposalVersion::class,
                ProposalReview::class,
                Decision::class,
                FormalRecordVersion::class,
            ],
        );
        $this->f6cGrant(
            $business,
            $approver,
            CapabilityCatalog::GOVERNANCE_RECORDS_MANAGE,
            [Decision::class],
        );
        $this->f6cGrant(
            $business,
            $membership,
            CapabilityCatalog::OWNERSHIP_MANAGE,
            [],
        );

        [$operationsVersionId, $roleId, $kpiId] = $this->f6cOperations(
            $business,
            $user,
            $membership,
        );

        return [
            'business' => $business,
            'user' => $user,
            'membership' => $membership,
            'payer_user' => $payerUser,
            'payer' => $payer,
            'approver_user' => $approverUser,
            'approver' => $approver,
            'role_id' => $roleId,
            'operations_kpi_id' => $kpiId,
            'operations_version_id' => $operationsVersionId,
        ];
    }

    /** @return array{User,Membership} */
    protected function f6cMember(Business $business, string $prefix): array
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
    protected function f6cGrant(
        Business $business,
        Membership $membership,
        string $capability,
        array $resourceTypes,
    ): void {
        $permission = Permission::query()->firstOrCreate(['key' => $capability]);

        PermissionGrant::query()->firstOrCreate([
            'business_id' => $business->getKey(),
            'membership_id' => $membership->getKey(),
            'permission_id' => $permission->getKey(),
        ], ['effect' => 'allow']);

        foreach ($resourceTypes as $resourceType) {
            AccessPolicy::query()->firstOrCreate([
                'business_id' => $business->getKey(),
                'membership_id' => $membership->getKey(),
                'permission_profile_id' => null,
                'permission_id' => $permission->getKey(),
                'resource_type' => $resourceType,
            ], ['effect' => 'allow']);
        }
    }

    /** @return array{string,string,string} */
    protected function f6cOperations(
        Business $business,
        User $user,
        Membership $membership,
    ): array {
        $family = FormalRecordFamily::query()->create([
            'business_id' => $business->getKey(),
            'record_type' => 'operations_register',
            'subject_type' => 'business',
            'subject_id' => (string) $business->getKey(),
        ]);

        $version = FormalRecordVersion::query()->create([
            'business_id' => $business->getKey(),
            'formal_record_family_id' => $family->getKey(),
            'version_number' => 1,
            'predecessor_version_id' => null,
            'revision' => 1,
            'change_summary' => 'F6C operations fixture',
            'created_by_user_id' => $user->getKey(),
            'last_changed_by_user_id' => $user->getKey(),
            'effective_from' => now()->subMinute(),
            'effective_until' => null,
            'review_due_at' => null,
            'content_hash' => str_repeat('a', 64),
            'frozen_at' => null,
        ]);

        DB::table('operations_register_versions')->insert([
            'id' => (string) Str::uuid7(),
            'business_id' => $business->getKey(),
            'formal_record_version_id' => $version->getKey(),
            'organization_name' => 'F6C Operations',
            'notes' => null,
            'created_at' => now(),
        ]);

        $roleId = (string) Str::uuid7();
        DB::table('operations_roles')->insert([
            'id' => $roleId,
            'business_id' => $business->getKey(),
            'formal_record_version_id' => $version->getKey(),
            'role_key' => 'operations_lead',
            'name' => 'Operations Lead',
            'function_name' => 'Operations',
            'purpose' => 'Deliver approved operations.',
            'responsibilities' => 'Request controlled payments and deliver work.',
            'operational_authority' => null,
            'reports_to_role_key' => null,
            'report_type' => null,
            'reporting_frequency' => 'Weekly',
            'meeting_frequency' => null,
            'review_frequency' => 'Quarterly',
            'status' => 'active',
            'created_at' => now(),
        ]);

        DB::table('operations_role_assignments')->insert([
            'id' => (string) Str::uuid7(),
            'business_id' => $business->getKey(),
            'formal_record_version_id' => $version->getKey(),
            'operations_role_id' => $roleId,
            'membership_id' => $membership->getKey(),
            'assignment_type' => 'primary',
            'created_at' => now(),
        ]);

        $kpiId = (string) Str::uuid7();

        DB::table('operations_kpis')->insert([
            'id' => $kpiId,
            'business_id' => $business->getKey(),
            'formal_record_version_id' => $version->getKey(),
            'operations_role_id' => $roleId,
            'name' => 'F6C approved delivery KPI',
            'target' => '>= 90%',
            'measurement_method' => 'Approved outcomes delivered on time.',
            'frequency' => 'Monthly',
            'current_status' => 'on_track',
            'created_at' => now(),
        ]);

        $version->frozen_at = now();
        $version->save();

        $this->f6cLifecycleEffective($business, $user, $version);

        DB::table('record_family_effective_heads')->insert([
            'id' => (string) Str::uuid7(),
            'business_id' => $business->getKey(),
            'formal_record_family_id' => $family->getKey(),
            'formal_record_version_id' => $version->getKey(),
            'activated_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return [(string) $version->getKey(), $roleId, $kpiId];
    }

    protected function f6cLifecycleEffective(
        Business $business,
        User $user,
        FormalRecordVersion $version,
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
            DB::table('record_version_state_transitions')->insert([
                'id' => (string) Str::uuid7(),
                'business_id' => $business->getKey(),
                'formal_record_version_id' => $version->getKey(),
                'sequence' => $index + 1,
                'from_state' => $from,
                'to_state' => $to,
                'transitioned_by_user_id' => $user->getKey(),
                'occurred_at' => now(),
                'created_at' => now(),
            ]);
        }
    }

    /** @param list<string> $decisionTypes */
    protected function f6cFormationAuthority(
        array $context,
        array $decisionTypes,
    ): void {
        $family = FormalRecordFamily::query()->create([
            'business_id' => $context['business']->getKey(),
            'record_type' => 'formation_authority_policy',
            'subject_type' => 'business',
            'subject_id' => (string) $context['business']->getKey(),
        ]);

        $version = FormalRecordVersion::query()->create([
            'business_id' => $context['business']->getKey(),
            'formal_record_family_id' => $family->getKey(),
            'version_number' => 1,
            'predecessor_version_id' => null,
            'revision' => 1,
            'change_summary' => 'F6C explicit Formation Authority fixture.',
            'created_by_user_id' => $context['user']->getKey(),
            'last_changed_by_user_id' => $context['user']->getKey(),
            'effective_from' => now()->subMinute(),
            'effective_until' => null,
            'review_due_at' => null,
            'content_hash' => hash('sha256', implode('|', $decisionTypes)),
            'frozen_at' => null,
        ]);

        RecordVersionStateTransition::query()->create([
            'business_id' => $context['business']->getKey(),
            'formal_record_version_id' => $version->getKey(),
            'sequence' => 1,
            'from_state' => null,
            'to_state' => FormalRecordState::Draft->value,
            'transitioned_by_user_id' => $context['user']->getKey(),
            'occurred_at' => now()->subSeconds(10),
        ]);

        foreach (array_values($decisionTypes) as $index => $decisionType) {
            $rule = FormationAuthorityPolicyRule::query()->create([
                'business_id' => $context['business']->getKey(),
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
                'business_id' => $context['business']->getKey(),
                'formation_authority_policy_rule_id' => $rule->getKey(),
                'membership_id' => $context['approver']->getKey(),
                'capacity' => 'F6C Formation Approver',
                'can_approve' => true,
                'can_vote' => false,
                'can_sign' => false,
            ]);
        }

        $version->frozen_at = now()->subSeconds(5);
        $version->save();

        RecordVersionStateTransition::query()->create([
            'business_id' => $context['business']->getKey(),
            'formal_record_version_id' => $version->getKey(),
            'sequence' => 2,
            'from_state' => FormalRecordState::Draft->value,
            'to_state' => FormalRecordState::ReadyForReview->value,
            'transitioned_by_user_id' => $context['user']->getKey(),
            'occurred_at' => now(),
        ]);

        FormationAuthorityEstablishment::query()->create([
            'business_id' => $context['business']->getKey(),
            'formal_record_version_id' => $version->getKey(),
            'established_by_membership_id' => $context['membership']->getKey(),
            'establishment_hash' => $version->content_hash,
            'established_at' => now(),
        ]);
    }

    protected function f6cApproveProposal(
        array $context,
        string $proposalVersionId,
        string $decisionType,
        ?string $decisionAmount = null,
    ): string {
        $review = $this->app->make(CreateProposalReview::class)->execute(
            $context['user'],
            $context['business'],
            $proposalVersionId,
            (string) $context['membership']->getKey(),
        );

        self::assertNotNull($review);

        $completed = $this->app->make(CompleteProposalReview::class)->execute(
            $context['user'],
            $context['business'],
            (string) $review->getKey(),
            ProposalReviewOutcome::Approved,
            'F6C exact frozen proposal reviewed and approved.',
        );

        self::assertNotNull($completed);

        $decision = $this->app->make(OpenGovernanceDecision::class)->execute(
            $context['user'],
            $context['business'],
            $proposalVersionId,
            new DecisionType($decisionType),
            $decisionAmount,
        );

        self::assertInstanceOf(Decision::class, $decision);

        $approval = $this->app->make(RecordGovernanceApproval::class)->execute(
            $context['approver_user'],
            $context['business'],
            (string) $decision->getKey(),
            ApprovalOutcome::Approved,
            'F6C approval of exact governed proposal.',
        );

        self::assertNotNull($approval);

        $resolved = $this->app->make(ResolveGovernanceDecision::class)->approve(
            $context['user'],
            $context['business'],
            (string) $decision->getKey(),
        );

        self::assertNotNull($resolved);

        return (string) $decision->getKey();
    }

    /** @return array<string,mixed> */
    protected function f6cFinancePayload(array $context): array
    {
        return [
            'finance_owner_membership_id' => (string) $context['membership']->getKey(),
            'control_owner_membership_id' => (string) $context['membership']->getKey(),
            'bookkeeping_owner_membership_id' => (string) $context['membership']->getKey(),
            'accounting_method' => 'Accrual',
            'fiscal_period' => 'Calendar year',
            'base_currency' => 'USD',
            'bank_accounts' => [[
                'key' => 'operating',
                'bank_name' => 'Fixture Bank',
                'account_name' => 'F6C Operating',
                'account_reference' => 'MASKED-001',
                'currency' => 'USD',
                'account_purpose' => 'Operating payments',
                'status' => 'active',
            ]],
            'bank_access' => [[
                'bank_key' => 'operating',
                'membership_id' => (string) $context['payer']->getKey(),
                'access_level' => 'payer',
                'is_signatory' => true,
                'is_backup_access' => false,
                'payment_limit_minor_units' => 10000000,
                'last_access_review_date' => now()->toDateString(),
                'status' => 'active',
            ]],
            'payment_rules' => [[
                'rule_key' => 'general',
                'transaction_type' => 'general_payment',
                'amount_min_minor_units' => 0,
                'amount_max_minor_units' => 10000000,
                'requester_operations_role_key' => 'operations_lead',
                'governance_decision_type' => 'finance_payment_approval',
                'payer_access_level' => 'payer',
                'evidence_required' => false,
                'strict_three_way_separation' => true,
                'compensating_review_allowed' => false,
                'related_party_review_required' => true,
            ], [
                'rule_key' => 'salary_service_fee',
                'transaction_type' => 'salary_service_fee',
                'amount_min_minor_units' => 0,
                'amount_max_minor_units' => 10000000,
                'requester_operations_role_key' => 'operations_lead',
                'governance_decision_type' => 'role_compensation_approval',
                'payer_access_level' => 'payer',
                'evidence_required' => false,
                'strict_three_way_separation' => true,
                'compensating_review_allowed' => false,
                'related_party_review_required' => false,
            ], [
                'rule_key' => 'reimbursement',
                'transaction_type' => 'reimbursement',
                'amount_min_minor_units' => 0,
                'amount_max_minor_units' => 10000000,
                'requester_operations_role_key' => 'operations_lead',
                'governance_decision_type' => 'reimbursement_approval',
                'payer_access_level' => 'payer',
                'evidence_required' => false,
                'strict_three_way_separation' => true,
                'compensating_review_allowed' => false,
                'related_party_review_required' => false,
            ], [
                'rule_key' => 'bonus',
                'transaction_type' => 'bonus',
                'amount_min_minor_units' => 0,
                'amount_max_minor_units' => 10000000,
                'requester_operations_role_key' => 'operations_lead',
                'governance_decision_type' => 'bonus_approval',
                'payer_access_level' => 'payer',
                'evidence_required' => false,
                'strict_three_way_separation' => true,
                'compensating_review_allowed' => false,
                'related_party_review_required' => false,
            ], [
                'rule_key' => 'loan_repayment',
                'transaction_type' => 'loan_repayment',
                'amount_min_minor_units' => 0,
                'amount_max_minor_units' => 10000000,
                'requester_operations_role_key' => 'operations_lead',
                'governance_decision_type' => 'loan_repayment_approval',
                'payer_access_level' => 'payer',
                'evidence_required' => false,
                'strict_three_way_separation' => true,
                'compensating_review_allowed' => false,
                'related_party_review_required' => false,
            ], [
                'rule_key' => 'distribution',
                'transaction_type' => 'profit_distribution',
                'amount_min_minor_units' => 0,
                'amount_max_minor_units' => 10000000,
                'requester_operations_role_key' => 'operations_lead',
                'governance_decision_type' => 'profit_distribution_approval',
                'payer_access_level' => 'payer',
                'evidence_required' => false,
                'strict_three_way_separation' => true,
                'compensating_review_allowed' => false,
                'related_party_review_required' => false,
            ]],
            'expense_procurement_rules' => [[
                'rule_key' => 'reimbursement',
                'control_type' => 'reimbursement',
                'category' => 'Business expense',
                'amount_min_minor_units' => 0,
                'amount_max_minor_units' => 1000000,
                'receipt_required' => true,
                'quotation_count' => 0,
                'supplier_approval_required' => false,
                'purchase_order_required' => false,
                'invoice_match_required' => false,
                'prohibited' => false,
                'rule_text' => 'Valid business expense.',
            ]],
        ];
    }

    protected function f6cPartner(array $context): string
    {
        $partnerId = (string) Str::uuid7();

        DB::table('partners')->insert([
            'id' => $partnerId,
            'business_id' => $context['business']->getKey(),
            'display_name' => 'F6C Partner',
            'legal_name' => 'F6C Partner Legal',
            'email' => 'partner-'.Str::uuid7().'@example.test',
            'status' => 'active',
            'notes' => null,
            'revision' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('partner_membership_links')->insert([
            'id' => (string) Str::uuid7(),
            'business_id' => $context['business']->getKey(),
            'partner_id' => $partnerId,
            'membership_id' => $context['membership']->getKey(),
            'linked_by_membership_id' => $context['membership']->getKey(),
            'linked_at' => now(),
        ]);

        return $partnerId;
    }

    protected function f6cEffectiveOwnership(
        array $context,
        string $partnerId,
    ): string {
        $scenarioId = (string) Str::uuid7();
        $classId = (string) Str::uuid7();

        DB::table('ownership_scenarios')->insert([
            'id' => $scenarioId,
            'business_id' => $context['business']->getKey(),
            'name' => 'F6C Governed Ownership Snapshot',
            'currency' => 'USD',
            'share_value_minor_units' => 10000,
            'authorized_shares' => '100.00000000',
            'reserved_unissued_shares' => '0.00000000',
            'status' => 'draft',
            'revision' => 1,
            'frozen_at' => null,
            'created_by_membership_id' => $context['membership']->getKey(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('ownership_scenario_share_classes')->insert([
            'id' => $classId,
            'business_id' => $context['business']->getKey(),
            'ownership_scenario_id' => $scenarioId,
            'name' => 'Ordinary',
            'voting_right_per_share' => '1.00000000',
            'profit_right_per_share' => '1.00000000',
            'transfer_allowed' => true,
            'restrictions' => null,
            'special_rights' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('ownership_scenario_positions')->insert([
            'id' => (string) Str::uuid7(),
            'business_id' => $context['business']->getKey(),
            'ownership_scenario_id' => $scenarioId,
            'partner_id' => $partnerId,
            'share_class_id' => $classId,
            'accepted_contribution_minor_units' => 1000000,
            'shares_issued' => '100.00000000',
            'shares_vested' => '100.00000000',
            'voting_rights' => '100.00000000',
            'profit_rights' => '100.00000000',
            'issue_date' => now()->subDay()->toDateString(),
            'vesting_start_date' => null,
            'vesting_period_months' => null,
            'vesting_cliff_months' => null,
            'vesting_conditions' => null,
            'early_exit_treatment' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('ownership_scenarios')
            ->where('id', $scenarioId)
            ->update([
                'status' => 'frozen',
                'revision' => 2,
                'frozen_at' => now(),
                'updated_at' => now(),
            ]);

        $workflow = $this->app->make(OwnershipGovernanceWorkflow::class);
        $submission = $workflow->submitGovernance(
            $context['user'],
            $context['business'],
            $scenarioId,
            now()->subMinute(),
        );

        self::assertNotNull($submission);
        self::assertTrue($workflow->advanceContentReview(
            $context['user'],
            $context['business'],
            $submission['id'],
            FormalRecordState::UnderReview,
        ));
        self::assertTrue($workflow->advanceContentReview(
            $context['user'],
            $context['business'],
            $submission['id'],
            FormalRecordState::Approved,
        ));

        $this->f6cApproveProposal(
            $context,
            $submission['proposal_version_id'],
            'ownership_approval',
        );

        self::assertTrue($workflow->effectApprovedGovernance(
            $context['user'],
            $context['business'],
            $submission['id'],
        ));

        $registerVersionId = DB::table('ownership_register_versions')
            ->where('business_id', $context['business']->getKey())
            ->where('source_ownership_scenario_id', $scenarioId)
            ->where('status', 'effective')
            ->value('id');

        self::assertNotNull($registerVersionId);

        return (string) $registerVersionId;
    }

    protected function f6cVerifiedEvidence(array $context): string
    {
        $documentId = (string) Str::uuid7();
        $documentVersionId = (string) Str::uuid7();
        $evidenceId = (string) Str::uuid7();

        DB::table('documents')->insert([
            'id' => $documentId,
            'business_id' => $context['business']->getKey(),
            'title' => 'F6C verified payment evidence',
            'category' => 'finance_tax',
            'created_by_membership_id' => $context['membership']->getKey(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('document_access_grants')->insert([
            'id' => (string) Str::uuid7(),
            'business_id' => $context['business']->getKey(),
            'membership_id' => $context['membership']->getKey(),
            'document_id' => $documentId,
            'right' => 'manage',
            'effect' => 'allow',
            'created_at' => now(),
        ]);

        DB::table('document_versions')->insert([
            'id' => $documentVersionId,
            'business_id' => $context['business']->getKey(),
            'document_id' => $documentId,
            'version_number' => 1,
            'original_filename' => 'f6c-payment-proof.txt',
            'storage_key' => 'testing/f6c/'.Str::uuid7().'.txt',
            'size_bytes' => 1,
            'mime_type' => 'text/plain',
            'content_sha256' => str_repeat('e', 64),
            'uploaded_by_membership_id' => $context['membership']->getKey(),
            'effective_from' => null,
            'supersedes_document_version_id' => null,
            'created_at' => now(),
        ]);

        DB::table('evidence')->insert([
            'id' => $evidenceId,
            'business_id' => $context['business']->getKey(),
            'document_version_id' => $documentVersionId,
            'confidentiality' => 'standard',
            'source_date' => now()->toDateString(),
            'submitted_by_membership_id' => $context['membership']->getKey(),
            'verified_at' => now(),
            'verified_by_membership_id' => $context['membership']->getKey(),
            'verification_method' => 'F6C deterministic fixture verification',
            'verification_note' => 'Verified for payment workflow coverage.',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $evidenceId;
    }

    /** @return array<string,mixed> */
    protected function f6cRewardPayload(array $context, string $partnerId): array
    {
        $expenseRuleId = (string) DB::table('finance_expense_procurement_rules')
            ->where('business_id', $context['business']->getKey())
            ->where('control_type', 'reimbursement')
            ->value('id');

        return [
            'reward_owner_membership_id' => (string) $context['membership']->getKey(),
            'currency' => 'USD',
            'payment_frequency' => 'Monthly',
            'minimum_reserve_percent' => 10,
            'target_cash_buffer_minor_units' => 10000,
            'reinvestment_percent' => 5,
            'minimum_cash_after_distribution_minor_units' => 10000,
            'distribution_governance_decision_type' => 'profit_distribution_approval',
            'manual_adjustments_allowed' => false,
            'role_compensation_rules' => [[
                'operations_role_id' => $context['role_id'],
                'partner_id' => $partnerId,
                'membership_id' => (string) $context['membership']->getKey(),
                'compensation_type' => 'salary',
                'market_rate_minor_units' => 100000,
                'amount_minor_units' => 90000,
                'frequency' => 'Monthly',
                'start_date' => now()->subDay()->toDateString(),
                'end_date' => '',
                'governance_decision_type' => 'role_compensation_approval',
                'status' => 'active',
            ]],
            'reimbursement_rules' => [[
                'rule_key' => 'standard_reimbursement',
                'finance_expense_procurement_rule_id' => $expenseRuleId,
                'reimbursement_deadline_days' => 30,
                'governance_decision_type' => 'reimbursement_approval',
                'notes' => null,
            ]],
            'bonus_rules' => [[
                'operations_role_id' => $context['role_id'],
                'operations_kpi_id' => $context['operations_kpi_id'],
                'partner_id' => $partnerId,
                'bonus_type' => 'performance',
                'trigger_description' => 'Approved performance trigger.',
                'formula_text' => null,
                'approved_amount_minor_units' => 25000,
                'cap_minor_units' => 50000,
                'governance_decision_type' => 'bonus_approval',
                'status' => 'active',
            ]],
            'loan_repayment_rules' => [[
                'partner_id' => $partnerId,
                'loan_reference' => 'LOAN-001',
                'scheduled_amount_minor_units' => 30000,
                'frequency' => 'Monthly',
                'start_date' => now()->subDay()->toDateString(),
                'end_date' => '',
                'governance_decision_type' => 'loan_repayment_approval',
                'status' => 'active',
            ]],
            'distribution_rule' => [
                'distribution_basis' => 'vested_profit_rights',
                'vested_only' => true,
                'record_date_rule' => 'Approved record date.',
                'unpaid_contribution_restriction' => false,
                'leaver_treatment' => null,
                'special_rule_text' => null,
                'manual_adjustment_allowed' => false,
                'partner_status_rules' => [],
                'share_class_rules' => [],
            ],
        ];
    }

    /** @return array{formal_record_version_id:string} */
    protected function f6cEffectiveReward(
        array $context,
        string $partnerId,
        ?array $payload = null,
    ): array {
        $created = $this->app->make(RewardPolicyWorkflow::class)->createDraft(
            $context['user'],
            $context['business'],
            $payload ?? $this->f6cRewardPayload($context, $partnerId),
            now()->subMinute(),
        );

        self::assertNotNull($created);

        $version = FormalRecordVersion::query()
            ->whereKey($created['formal_record_version_id'])
            ->sole();
        $version->frozen_at = now();
        $version->save();

        foreach ([
            ['draft', 'ready_for_review'],
            ['ready_for_review', 'under_review'],
            ['under_review', 'approved'],
            ['approved', 'ready_for_effect'],
            ['ready_for_effect', 'effective'],
        ] as $offset => [$from, $to]) {
            DB::table('record_version_state_transitions')->insert([
                'id' => (string) Str::uuid7(),
                'business_id' => $context['business']->getKey(),
                'formal_record_version_id' => $version->getKey(),
                'sequence' => $offset + 2,
                'from_state' => $from,
                'to_state' => $to,
                'transitioned_by_user_id' => $context['user']->getKey(),
                'occurred_at' => now(),
                'created_at' => now(),
            ]);
        }

        DB::table('record_family_effective_heads')->insert([
            'id' => (string) Str::uuid7(),
            'business_id' => $context['business']->getKey(),
            'formal_record_family_id' => $version->formal_record_family_id,
            'formal_record_version_id' => $version->getKey(),
            'activated_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return ['formal_record_version_id' => (string) $version->getKey()];
    }

    /** @return array{formal_record_version_id:string,bank_id:string} */
    protected function f6cEffectiveFinance(
        array $context,
        ?array $payload = null,
    ): array {
        $created = $this->app->make(FinancePolicyWorkflow::class)->createDraft(
            $context['user'],
            $context['business'],
            $payload ?? $this->f6cFinancePayload($context),
            now()->subMinute(),
        );

        self::assertNotNull($created);

        $version = FormalRecordVersion::query()
            ->whereKey($created['formal_record_version_id'])
            ->sole();
        $version->frozen_at = now();
        $version->save();

        foreach ([
            ['draft', 'ready_for_review'],
            ['ready_for_review', 'under_review'],
            ['under_review', 'approved'],
            ['approved', 'ready_for_effect'],
            ['ready_for_effect', 'effective'],
        ] as $offset => [$from, $to]) {
            DB::table('record_version_state_transitions')->insert([
                'id' => (string) Str::uuid7(),
                'business_id' => $context['business']->getKey(),
                'formal_record_version_id' => $version->getKey(),
                'sequence' => $offset + 2,
                'from_state' => $from,
                'to_state' => $to,
                'transitioned_by_user_id' => $context['user']->getKey(),
                'occurred_at' => now(),
                'created_at' => now(),
            ]);
        }

        DB::table('record_family_effective_heads')->insert([
            'id' => (string) Str::uuid7(),
            'business_id' => $context['business']->getKey(),
            'formal_record_family_id' => $version->formal_record_family_id,
            'formal_record_version_id' => $version->getKey(),
            'activated_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return [
            'formal_record_version_id' => (string) $version->getKey(),
            'bank_id' => (string) DB::table('finance_bank_account_references')
                ->where('formal_record_version_id', $version->getKey())
                ->value('id'),
        ];
    }
}

final class F6CFinancePolicyTest extends TestCase
{
    use F6CFixtureSupport;
    use RefreshDatabase;

    public function test_finance_policy_is_versioned_and_stores_no_secret_columns(): void
    {
        $context = $this->f6cContext();

        $created = $this->app->make(FinancePolicyWorkflow::class)->createDraft(
            $context['user'],
            $context['business'],
            $this->f6cFinancePayload($context),
            now()->subMinute(),
        );

        self::assertNotNull($created);
        self::assertDatabaseCount('finance_policy_versions', 1);
        self::assertDatabaseCount('finance_bank_account_references', 1);
        self::assertDatabaseCount('finance_bank_access_assignments', 1);
        self::assertDatabaseCount('finance_payment_authority_rules', 6);

        foreach (['password', 'pin', 'otp', 'token', 'secret', 'credential'] as $column) {
            self::assertFalse(
                DB::getSchemaBuilder()->hasColumn(
                    'finance_bank_account_references',
                    $column,
                ),
            );
        }

        self::assertFalse(
            DB::getSchemaBuilder()->hasColumn(
                'finance_payment_authority_rules',
                'approver_membership_id',
            ),
        );
    }

    public function test_finance_policy_rejects_secret_like_payload_keys(): void
    {
        $context = $this->f6cContext();
        $payload = $this->f6cFinancePayload($context);
        $payload['bank_accounts'][0]['pin'] = 'must-not-be-stored';

        $this->expectException(InvalidArgumentException::class);

        $this->app->make(FinancePolicyWorkflow::class)->createDraft(
            $context['user'],
            $context['business'],
            $payload,
            now()->subMinute(),
        );
    }
}
