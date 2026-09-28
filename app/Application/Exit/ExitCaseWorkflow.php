<?php

declare(strict_types=1);

namespace App\Application\Exit;

use App\Application\Access\AuthorizeBusinessCapability;
use App\Application\Access\TransitionMembershipAccess;
use App\Application\Conflict\ConflictRecordVisibility;
use App\Application\Governance\MakeGovernedRecordEffective;
use App\Application\Governance\PrepareGovernedRecordForEffect;
use App\Application\Partnership\PartnerLifecycleWorkflow;
use App\Application\Partnership\PartnershipActorContext;
use App\Application\Records\CreateDraftRecordVersion;
use App\Application\Records\CreateFormalRecordFamily;
use App\Application\Records\CreateProposal;
use App\Application\Records\FreezeProposalVersion;
use App\Application\Records\SubmitRecordVersionForReview;
use App\Application\Records\TransitionFormalRecordVersion;
use App\Domain\Access\CapabilityCatalog;
use App\Domain\Access\ValueObjects\Capability;
use App\Domain\Exit\Enums\ExitCaseStatus;
use App\Domain\Exit\Enums\ExitTrigger;
use App\Domain\Exit\Enums\LeaverClassification;
use App\Domain\Exit\Services\ExitCaseStateMachine;
use App\Domain\Members\Enums\MembershipAccessStatus;
use App\Domain\Records\Enums\FormalRecordState;
use App\Domain\Records\Exceptions\StaleRevision;
use App\Domain\Records\ValueObjects\RecordScope;
use App\Infrastructure\Persistence\Eloquent\Businesses\Business;
use App\Infrastructure\Persistence\Eloquent\Exit\ExitCase;
use App\Infrastructure\Persistence\Eloquent\Identity\User;
use Carbon\CarbonImmutable;
use DateTimeInterface;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;
use RuntimeException;

final class ExitCaseWorkflow
{
    public function __construct(
        private readonly PartnershipActorContext $actor,
        private readonly AuthorizeBusinessCapability $authorize,
        private readonly ConflictRecordVisibility $conflictVisibility,
        private readonly RecordExitOccurrence $occurrence,
        private readonly ExitCaseStateMachine $stateMachine,
        private readonly CreateFormalRecordFamily $createFamily,
        private readonly CreateDraftRecordVersion $createDraft,
        private readonly SubmitRecordVersionForReview $submitForReview,
        private readonly TransitionFormalRecordVersion $transitionRecord,
        private readonly CreateProposal $createProposal,
        private readonly FreezeProposalVersion $freezeProposal,
        private readonly PrepareGovernedRecordForEffect $prepareEffect,
        private readonly MakeGovernedRecordEffective $makeEffective,
        private readonly PartnerLifecycleWorkflow $partnerLifecycle,
        private readonly TransitionMembershipAccess $membershipAccess,
    ) {}

    public function createCase(
        User $user,
        Business $business,
        string $partnerId,
        ExitTrigger $trigger,
        string $governanceDecisionType,
        ?string $triggerDetail = null,
        ?DateTimeInterface $effectiveFrom = null,
    ): ?ExitCase {
        $membership = $this->actor->membership(
            $user,
            $business,
            CapabilityCatalog::EXIT_MANAGE,
        );

        if ($membership === null) {
            return null;
        }

        $decisionType = trim($governanceDecisionType);

        if ($decisionType === '' || mb_strlen($decisionType) > 160) {
            throw new InvalidArgumentException(
                'A valid Governance Decision Type is required.',
            );
        }

        $businessId = (string) $business->getKey();

        return DB::transaction(function () use (
            $user,
            $business,
            $businessId,
            $partnerId,
            $trigger,
            $decisionType,
            $triggerDetail,
            $effectiveFrom,
            $membership,
        ): ?ExitCase {
            $partner = DB::table('partners')
                ->where('business_id', $businessId)
                ->where('id', $partnerId)
                ->lockForUpdate()
                ->first(['id', 'status']);

            if ($partner === null || (string) $partner->status !== 'active') {
                return null;
            }

            $openExists = ExitCase::query()
                ->where('business_id', $businessId)
                ->where('partner_id', $partnerId)
                ->whereNotIn('status', [
                    ExitCaseStatus::Completed->value,
                    ExitCaseStatus::Rejected->value,
                    ExitCaseStatus::Withdrawn->value,
                    ExitCaseStatus::Cancelled->value,
                ])
                ->exists();

            if ($openExists) {
                throw new InvalidArgumentException(
                    'This Partner already has an open Exit Case.',
                );
            }

            $linkedMembershipId = DB::table('partner_membership_links')
                ->where('business_id', $businessId)
                ->where('partner_id', $partnerId)
                ->value('membership_id');

            $sourceRegister = $this->currentOwnershipRegister($businessId);

            $case = ExitCase::query()->create([
                'business_id' => $businessId,
                'case_number' => sprintf(
                    'EX-%s-%s',
                    now()->format('Ymd'),
                    strtoupper(substr(
                        str_replace('-', '', (string) Str::uuid7()),
                        0,
                        8,
                    )),
                ),
                'partner_id' => $partnerId,
                'membership_id' => $linkedMembershipId,
                'trigger' => $trigger->value,
                'trigger_detail' => $this->nullableText($triggerDetail),
                'source_ownership_register_version_id' => $sourceRegister?->id,
                'currency' => $sourceRegister?->currency,
                'governance_decision_type' => $decisionType,
                'effective_from' => $effectiveFrom,
                'status' => ExitCaseStatus::Draft->value,
                'settlement_status' => 'pending',
                'created_by_membership_id' => $membership->getKey(),
                'revision' => 1,
            ]);

            $this->snapshotSharePosition(
                $businessId,
                (string) $case->getKey(),
                $partnerId,
                $sourceRegister,
            );

            DB::table('exit_case_transitions')->insert([
                'id' => (string) Str::uuid7(),
                'business_id' => $businessId,
                'exit_case_id' => $case->getKey(),
                'case_revision' => 1,
                'from_status' => null,
                'to_status' => ExitCaseStatus::Draft->value,
                'actor_membership_id' => $membership->getKey(),
                'occurred_at' => now(),
                'created_at' => now(),
            ]);

            $this->occurrence->record(
                $user,
                $business,
                'exit.case.created',
                'exit_case',
                (string) $case->getKey(),
                [
                    'partner_id' => $partnerId,
                    'trigger' => $trigger->value,
                    'source_ownership_register_version_id' => $sourceRegister?->id,
                    'revision' => 1,
                ],
            );

            return $case->fresh();
        });
    }

    public function recordNotice(
        User $user,
        Business $business,
        string $caseId,
        int $expectedRevision,
        DateTimeInterface $noticeDate,
        ?DateTimeInterface $intendedExitDate,
        ?int $requiredNoticeDays,
        string $summary,
    ): ?ExitCase {
        $membership = $this->actor->membership(
            $user,
            $business,
            CapabilityCatalog::EXIT_MANAGE,
        );

        if ($membership === null) {
            return null;
        }

        if ($requiredNoticeDays !== null && $requiredNoticeDays < 0) {
            throw new InvalidArgumentException(
                'Required notice days cannot be negative.',
            );
        }

        $notice = CarbonImmutable::instance(
            CarbonImmutable::parse($noticeDate),
        );
        $intended = $intendedExitDate === null
            ? null
            : CarbonImmutable::parse($intendedExitDate);

        if ($intended !== null && $intended->isBefore($notice)) {
            throw new InvalidArgumentException(
                'Intended Exit Date cannot be earlier than Notice Date.',
            );
        }

        $noticeSummary = trim($summary);

        if ($noticeSummary === '') {
            throw new InvalidArgumentException(
                'Exit notice summary is required.',
            );
        }

        return DB::transaction(function () use (
            $user,
            $business,
            $caseId,
            $expectedRevision,
            $notice,
            $intended,
            $requiredNoticeDays,
            $noticeSummary,
            $membership,
        ): ?ExitCase {
            $case = $this->lockCase(
                $user,
                $business,
                $caseId,
                $expectedRevision,
            );

            if ($case->status !== ExitCaseStatus::Draft) {
                throw new InvalidArgumentException(
                    'Exit Notice may only be recorded from Draft.',
                );
            }

            if (! $this->ensurePartnerExiting($user, $business, $case)) {
                return null;
            }

            $newRevision = $expectedRevision + 1;

            ExitCase::query()
                ->where('business_id', $business->getKey())
                ->whereKey($caseId)
                ->where('revision', $expectedRevision)
                ->update([
                    'notice_date' => $notice->toDateString(),
                    'intended_exit_date' => $intended?->toDateString(),
                    'required_notice_days' => $requiredNoticeDays,
                    'notice_summary' => $noticeSummary,
                    'status' => ExitCaseStatus::NoticeRecorded->value,
                    'revision' => $newRevision,
                    'updated_at' => now(),
                ]);

            $this->recordTransition(
                $business,
                $caseId,
                $newRevision,
                ExitCaseStatus::Draft,
                ExitCaseStatus::NoticeRecorded,
                (string) $membership->getKey(),
            );

            $this->occurrence->record(
                $user,
                $business,
                'exit.notice.recorded',
                'exit_case',
                $caseId,
                [
                    'notice_date' => $notice->toDateString(),
                    'intended_exit_date' => $intended?->toDateString(),
                    'revision' => $newRevision,
                ],
            );

            return ExitCase::query()->findOrFail($caseId);
        });
    }

    public function recordShareTreatment(
        User $user,
        Business $business,
        string $caseId,
        int $expectedRevision,
        string $shareTreatment,
        LeaverClassification $leaverClassification,
        ?string $buyerPartnerId,
        ?string $partnerChangeCaseId,
        ?string $ownershipScenarioId,
        ?string $valuationMethod,
        ?int $approvedBusinessValueMinorUnits,
        ?int $leaverAdjustmentMinorUnits,
        ?int $finalBuyoutValueMinorUnits,
        ?string $currency,
        ?string $leaverRuleReference,
    ): ?ExitCase {
        $membership = $this->actor->membership(
            $user,
            $business,
            CapabilityCatalog::EXIT_MANAGE,
        );

        if ($membership === null) {
            return null;
        }

        $treatment = strtolower(trim($shareTreatment));
        $allowedTreatments = [
            'remaining_partners_buy',
            'company_buyback',
            'third_party_sale',
            'partial_buyout',
            'permitted_person_transfer',
            'cancellation',
            'retain_per_agreement',
            'no_shares',
            'other',
        ];

        if (! in_array($treatment, $allowedTreatments, true)) {
            throw new InvalidArgumentException(
                'Exit Share Treatment is invalid.',
            );
        }

        foreach ([
            'Approved business value' => $approvedBusinessValueMinorUnits,
            'Final buyout value' => $finalBuyoutValueMinorUnits,
        ] as $label => $value) {
            if ($value !== null && $value < 0) {
                throw new InvalidArgumentException(
                    "{$label} cannot be negative.",
                );
            }
        }

        $normalizedCurrency = $this->nullableCurrency($currency);
        $normalizedLeaverRule = $this->nullableText($leaverRuleReference);

        if (
            $leaverClassification !== LeaverClassification::NotApplicable
            && $normalizedLeaverRule === null
        ) {
            throw new InvalidArgumentException(
                'Classified leaver treatment requires the applicable rule/evidence reference.',
            );
        }

        return DB::transaction(function () use (
            $user,
            $business,
            $caseId,
            $expectedRevision,
            $treatment,
            $leaverClassification,
            $buyerPartnerId,
            $partnerChangeCaseId,
            $ownershipScenarioId,
            $valuationMethod,
            $approvedBusinessValueMinorUnits,
            $leaverAdjustmentMinorUnits,
            $finalBuyoutValueMinorUnits,
            $normalizedCurrency,
            $normalizedLeaverRule,
            $membership,
        ): ?ExitCase {
            $case = $this->lockCase(
                $user,
                $business,
                $caseId,
                $expectedRevision,
            );

            if ($case->status !== ExitCaseStatus::NoticeRecorded) {
                throw new InvalidArgumentException(
                    'Share Treatment may only be recorded after Exit Notice.',
                );
            }

            $capturedShares = $this->capturedShares($business, $case);

            if ($capturedShares === '0.00000000' && $treatment !== 'no_shares') {
                throw new InvalidArgumentException(
                    'An Exit with no captured Shares must use the no_shares treatment.',
                );
            }

            if ($capturedShares !== '0.00000000' && $treatment === 'no_shares') {
                throw new InvalidArgumentException(
                    'Captured Ownership exists and requires an explicit Share Treatment.',
                );
            }

            if ($buyerPartnerId !== null) {
                if ($buyerPartnerId === (string) $case->partner_id) {
                    throw new InvalidArgumentException(
                        'Exiting Partner cannot be the Exit buyer.',
                    );
                }

                $buyerExists = DB::table('partners')
                    ->where('business_id', $business->getKey())
                    ->where('id', $buyerPartnerId)
                    ->exists();

                if (! $buyerExists) {
                    return null;
                }
            }

            if ($partnerChangeCaseId !== null) {
                $partnerChange = DB::table('partner_change_cases')
                    ->where('business_id', $business->getKey())
                    ->where('id', $partnerChangeCaseId)
                    ->first([
                        'transaction_type',
                        'seller_partner_id',
                        'buyer_partner_id',
                    ]);

                if (
                    $partnerChange === null
                    || (string) $partnerChange->transaction_type !== 'transfer_existing'
                    || (string) $partnerChange->seller_partner_id
                        !== (string) $case->partner_id
                    || (
                        $buyerPartnerId !== null
                        && (string) $partnerChange->buyer_partner_id
                            !== $buyerPartnerId
                    )
                ) {
                    throw new InvalidArgumentException(
                        'Linked Partner Change must be an exact same-Business transfer from the exiting Partner.',
                    );
                }
            }

            if ($ownershipScenarioId !== null) {
                $scenario = DB::table('ownership_scenarios')
                    ->where('business_id', $business->getKey())
                    ->where('id', $ownershipScenarioId)
                    ->where('status', 'frozen')
                    ->first();

                if ($scenario === null) {
                    return null;
                }
            }

            if (in_array($treatment, [
                'remaining_partners_buy',
                'third_party_sale',
                'permitted_person_transfer',
            ], true) && $partnerChangeCaseId === null) {
                throw new InvalidArgumentException(
                    'This Share Treatment requires an exact Partner Change Case.',
                );
            }

            if (in_array($treatment, [
                'company_buyback',
                'cancellation',
            ], true) && $ownershipScenarioId === null) {
                throw new InvalidArgumentException(
                    'This Share Treatment requires a Frozen Ownership Scenario.',
                );
            }

            if (
                $treatment === 'partial_buyout'
                && $partnerChangeCaseId === null
                && $ownershipScenarioId === null
            ) {
                throw new InvalidArgumentException(
                    'Partial buyout requires a canonical Partner Change or Ownership Scenario.',
                );
            }

            if (! in_array($treatment, [
                'no_shares',
                'retain_per_agreement',
            ], true)) {
                if ($this->nullableText($valuationMethod) === null) {
                    throw new InvalidArgumentException(
                        'Ownership-changing Exit Treatment requires a valuation method.',
                    );
                }

                if (
                    $finalBuyoutValueMinorUnits === null
                    || $normalizedCurrency === null
                ) {
                    throw new InvalidArgumentException(
                        'Ownership-changing Exit Treatment requires an approved value and currency.',
                    );
                }
            }

            $newRevision = $expectedRevision + 1;

            ExitCase::query()
                ->where('business_id', $business->getKey())
                ->whereKey($caseId)
                ->where('revision', $expectedRevision)
                ->update([
                    'share_treatment' => $treatment,
                    'buyer_partner_id' => $buyerPartnerId,
                    'partner_change_case_id' => $partnerChangeCaseId,
                    'ownership_scenario_id' => $ownershipScenarioId,
                    'valuation_method' => $this->nullableText($valuationMethod),
                    'approved_business_value_minor_units' => $approvedBusinessValueMinorUnits,
                    'leaver_adjustment_minor_units' => $leaverAdjustmentMinorUnits,
                    'final_buyout_value_minor_units' => $finalBuyoutValueMinorUnits,
                    'currency' => $normalizedCurrency ?? $case->currency,
                    'leaver_classification' => $leaverClassification->value,
                    'leaver_rule_reference' => $normalizedLeaverRule,
                    'status' => ExitCaseStatus::TreatmentReady->value,
                    'revision' => $newRevision,
                    'updated_at' => now(),
                ]);

            $this->recordTransition(
                $business,
                $caseId,
                $newRevision,
                ExitCaseStatus::NoticeRecorded,
                ExitCaseStatus::TreatmentReady,
                (string) $membership->getKey(),
            );

            $this->occurrence->record(
                $user,
                $business,
                'exit.share_treatment.recorded',
                'exit_case',
                $caseId,
                [
                    'share_treatment' => $treatment,
                    'leaver_classification' => $leaverClassification->value,
                    'partner_change_case_id' => $partnerChangeCaseId,
                    'ownership_scenario_id' => $ownershipScenarioId,
                    'revision' => $newRevision,
                ],
            );

            return ExitCase::query()->findOrFail($caseId);
        });
    }

    public function recordPaymentTerms(
        User $user,
        Business $business,
        string $caseId,
        int $expectedRevision,
        ?int $paymentTotalMinorUnits,
        ?string $paymentTermsSummary,
        ?int $depositMinorUnits,
        ?int $installmentMinorUnits,
        ?int $installmentCount,
        ?string $paymentFrequency,
        ?DateTimeInterface $firstPaymentDate,
        ?DateTimeInterface $finalPaymentDate,
        ?string $interestTerms,
        ?string $securityTerms,
        ?string $latePaymentRule,
        string $affordabilityStatus,
        ?string $alternativePaymentStructure,
    ): ?ExitCase {
        if (! $this->actor->allows(
            $user,
            $business,
            CapabilityCatalog::EXIT_MANAGE,
        )) {
            return null;
        }

        foreach ([
            'Payment total' => $paymentTotalMinorUnits,
            'Deposit' => $depositMinorUnits,
            'Installment amount' => $installmentMinorUnits,
        ] as $label => $value) {
            if ($value !== null && $value < 0) {
                throw new InvalidArgumentException(
                    "{$label} cannot be negative.",
                );
            }
        }

        if ($installmentCount !== null && $installmentCount < 1) {
            throw new InvalidArgumentException(
                'Installment count must be positive.',
            );
        }

        $affordability = strtolower(trim($affordabilityStatus));

        if (! in_array($affordability, [
            'pending',
            'affordable',
            'not_affordable',
            'alternative_approved',
            'not_applicable',
        ], true)) {
            throw new InvalidArgumentException(
                'Affordability status is invalid.',
            );
        }

        if (
            $affordability === 'not_affordable'
            && $this->nullableText($alternativePaymentStructure) === null
        ) {
            throw new InvalidArgumentException(
                'Unaffordable Exit terms require an alternative payment structure before Governance.',
            );
        }

        return DB::transaction(function () use (
            $user,
            $business,
            $caseId,
            $expectedRevision,
            $paymentTotalMinorUnits,
            $paymentTermsSummary,
            $depositMinorUnits,
            $installmentMinorUnits,
            $installmentCount,
            $paymentFrequency,
            $firstPaymentDate,
            $finalPaymentDate,
            $interestTerms,
            $securityTerms,
            $latePaymentRule,
            $affordability,
            $alternativePaymentStructure,
        ): ?ExitCase {
            $case = $this->lockCase(
                $user,
                $business,
                $caseId,
                $expectedRevision,
            );

            if ($case->status !== ExitCaseStatus::TreatmentReady) {
                throw new InvalidArgumentException(
                    'Payment Terms may only be edited while Treatment is Ready.',
                );
            }

            if (
                $paymentTotalMinorUnits !== null
                && $paymentTotalMinorUnits > 0
                && $this->nullableText($paymentTermsSummary) === null
            ) {
                throw new InvalidArgumentException(
                    'Exit payment terms summary is required for a payable settlement.',
                );
            }

            ExitCase::query()
                ->where('business_id', $business->getKey())
                ->whereKey($caseId)
                ->where('revision', $expectedRevision)
                ->update([
                    'payment_total_minor_units' => $paymentTotalMinorUnits,
                    'payment_terms_summary' => $this->nullableText($paymentTermsSummary),
                    'deposit_minor_units' => $depositMinorUnits,
                    'installment_minor_units' => $installmentMinorUnits,
                    'installment_count' => $installmentCount,
                    'payment_frequency' => $this->nullableText($paymentFrequency),
                    'first_payment_date' => $firstPaymentDate,
                    'final_payment_date' => $finalPaymentDate,
                    'interest_terms' => $this->nullableText($interestTerms),
                    'security_terms' => $this->nullableText($securityTerms),
                    'late_payment_rule' => $this->nullableText($latePaymentRule),
                    'affordability_status' => $affordability,
                    'alternative_payment_structure' => $this->nullableText($alternativePaymentStructure),
                    'revision' => $expectedRevision + 1,
                    'updated_at' => now(),
                ]);

            $this->occurrence->record(
                $user,
                $business,
                'exit.payment_terms.recorded',
                'exit_case',
                $caseId,
                [
                    'payment_total_minor_units' => $paymentTotalMinorUnits,
                    'affordability_status' => $affordability,
                    'revision' => $expectedRevision + 1,
                ],
            );

            return ExitCase::query()->findOrFail($caseId);
        });
    }

    public function recordRequirement(
        User $user,
        Business $business,
        string $caseId,
        int $expectedRevision,
        string $requirementType,
        string $requirementKey,
        string $status,
        ?string $detail = null,
        ?string $sourceType = null,
        ?string $sourceId = null,
    ): bool {
        $membership = $this->actor->membership(
            $user,
            $business,
            CapabilityCatalog::EXIT_MANAGE,
        );

        if ($membership === null) {
            return false;
        }

        $type = strtolower(trim($requirementType));
        $key = trim($requirementKey);
        $normalizedStatus = strtolower(trim($status));

        if (! in_array($type, [
            'ownership',
            'finance',
            'handover',
            'access',
            'operations',
            'continuity',
            'post_exit',
            'legal',
            'governance',
            'conflict',
            'other',
        ], true)) {
            throw new InvalidArgumentException(
                'Exit requirement type is invalid.',
            );
        }

        if ($key === '' || mb_strlen($key) > 96) {
            throw new InvalidArgumentException(
                'Exit requirement key is invalid.',
            );
        }

        if (! in_array($normalizedStatus, [
            'pending',
            'met',
            'blocked',
            'not_applicable',
        ], true)) {
            throw new InvalidArgumentException(
                'Exit requirement status is invalid.',
            );
        }

        if ($sourceId !== null && ! Str::isUuid($sourceId)) {
            throw new InvalidArgumentException(
                'Exit requirement source ID is invalid.',
            );
        }

        return DB::transaction(function () use (
            $user,
            $business,
            $caseId,
            $expectedRevision,
            $type,
            $key,
            $normalizedStatus,
            $detail,
            $sourceType,
            $sourceId,
            $membership,
        ): bool {
            $case = $this->lockCase(
                $user,
                $business,
                $caseId,
                $expectedRevision,
            );

            if (in_array($case->status, [
                ExitCaseStatus::Completed,
                ExitCaseStatus::Rejected,
                ExitCaseStatus::Withdrawn,
                ExitCaseStatus::Cancelled,
            ], true)) {
                throw new InvalidArgumentException(
                    'Terminal Exit Cases cannot accept new requirements.',
                );
            }

            if (! $this->validateRequirementSource(
                $user,
                $business,
                $case,
                $sourceType,
                $sourceId,
            )) {
                return false;
            }

            DB::table('exit_requirements')->insert([
                'id' => (string) Str::uuid7(),
                'business_id' => $business->getKey(),
                'exit_case_id' => $caseId,
                'case_revision' => $expectedRevision,
                'requirement_type' => $type,
                'requirement_key' => $key,
                'status' => $normalizedStatus,
                'detail' => $this->nullableText($detail),
                'source_type' => $this->nullableText($sourceType),
                'source_id' => $sourceId,
                'recorded_by_membership_id' => $membership->getKey(),
                'recorded_at' => now(),
            ]);

            $this->occurrence->record(
                $user,
                $business,
                'exit.requirement.recorded',
                'exit_case',
                $caseId,
                [
                    'requirement_type' => $type,
                    'requirement_key' => $key,
                    'status' => $normalizedStatus,
                    'case_revision' => $expectedRevision,
                ],
            );

            return true;
        });
    }

    public function linkFinancePayment(
        User $user,
        Business $business,
        string $caseId,
        int $expectedRevision,
        string $financePaymentId,
        string $purpose,
        ?int $installmentSequence = null,
    ): bool {
        $membership = $this->actor->membership(
            $user,
            $business,
            CapabilityCatalog::EXIT_MANAGE,
        );

        if (
            $membership === null
            || ! $this->authorize->decide(
                $user,
                $business,
                $business,
                new Capability(CapabilityCatalog::FINANCE_VIEW),
            )->allowed
        ) {
            return false;
        }

        $normalizedPurpose = strtolower(trim($purpose));

        if (! in_array($normalizedPurpose, [
            'deposit',
            'installment',
            'final_settlement',
            'loan_settlement',
            'other',
        ], true)) {
            throw new InvalidArgumentException(
                'Exit Finance Payment purpose is invalid.',
            );
        }

        if ($installmentSequence !== null && $installmentSequence < 1) {
            throw new InvalidArgumentException(
                'Installment sequence must be positive.',
            );
        }

        return DB::transaction(function () use (
            $user,
            $business,
            $caseId,
            $expectedRevision,
            $financePaymentId,
            $normalizedPurpose,
            $installmentSequence,
            $membership,
        ): bool {
            $case = $this->lockCase(
                $user,
                $business,
                $caseId,
                $expectedRevision,
            );

            $payment = DB::table('finance_payments')
                ->where('business_id', $business->getKey())
                ->where('id', $financePaymentId)
                ->first(['id', 'currency']);

            if ($payment === null) {
                return false;
            }

            if (
                $case->currency !== null
                && (string) $payment->currency !== (string) $case->currency
            ) {
                throw new InvalidArgumentException(
                    'Exit Finance Payment currency must match the governed Exit terms.',
                );
            }

            DB::table('exit_finance_links')->insertOrIgnore([
                'id' => (string) Str::uuid7(),
                'business_id' => $business->getKey(),
                'exit_case_id' => $caseId,
                'finance_payment_id' => $financePaymentId,
                'purpose' => $normalizedPurpose,
                'installment_sequence' => $installmentSequence,
                'linked_by_membership_id' => $membership->getKey(),
                'linked_at' => now(),
            ]);

            $this->occurrence->record(
                $user,
                $business,
                'exit.finance_payment.linked',
                'exit_case',
                $caseId,
                [
                    'finance_payment_id' => $financePaymentId,
                    'purpose' => $normalizedPurpose,
                ],
            );

            return true;
        });
    }

    public function transition(
        User $user,
        Business $business,
        string $caseId,
        int $expectedRevision,
        ExitCaseStatus $target,
    ): ?ExitCase {
        $membership = $this->actor->membership(
            $user,
            $business,
            CapabilityCatalog::EXIT_MANAGE,
        );

        if ($membership === null) {
            return null;
        }

        return DB::transaction(function () use (
            $user,
            $business,
            $caseId,
            $expectedRevision,
            $target,
            $membership,
        ): ?ExitCase {
            $case = $this->lockCase(
                $user,
                $business,
                $caseId,
                $expectedRevision,
            );

            if (in_array($target, [
                ExitCaseStatus::NoticeRecorded,
                ExitCaseStatus::TreatmentReady,
                ExitCaseStatus::UnderGovernance,
                ExitCaseStatus::Approved,
                ExitCaseStatus::ReadyForEffect,
                ExitCaseStatus::Effective,
                ExitCaseStatus::Completed,
            ], true)) {
                throw new InvalidArgumentException(
                    'This Exit transition requires its dedicated controlled workflow action.',
                );
            }

            if (! $this->stateMachine->allows($case->status, $target)) {
                throw new InvalidArgumentException(
                    "Exit transition {$case->status->value} -> {$target->value} is not allowed.",
                );
            }

            if ($target === ExitCaseStatus::TermsReady) {
                $this->assertTermsReady($business, $case);
            }

            if (in_array($target, [
                ExitCaseStatus::Withdrawn,
                ExitCaseStatus::Cancelled,
            ], true)) {
                $this->restorePartnerIfOwnedByCase(
                    $user,
                    $business,
                    $case,
                    $target === ExitCaseStatus::Withdrawn
                        ? 'exit_withdrawn'
                        : 'exit_cancelled',
                );
            }

            return $this->applyTransition(
                $user,
                $business,
                $case,
                $target,
                (string) $membership->getKey(),
            );
        });
    }

    /** @return array{formal_record_version_id:string,proposal_version_id:string}|null */
    public function submitGovernance(
        User $user,
        Business $business,
        string $caseId,
        int $expectedRevision,
    ): ?array {
        $membership = $this->actor->membership(
            $user,
            $business,
            CapabilityCatalog::EXIT_MANAGE,
        );

        if (
            $membership === null
            || ! $this->actor->allows(
                $user,
                $business,
                CapabilityCatalog::RECORDS_MANAGE,
            )
        ) {
            return null;
        }

        return DB::transaction(function () use (
            $user,
            $business,
            $caseId,
            $expectedRevision,
            $membership,
        ): ?array {
            $case = $this->lockCase(
                $user,
                $business,
                $caseId,
                $expectedRevision,
            );

            if ($case->status !== ExitCaseStatus::TermsReady) {
                throw new InvalidArgumentException(
                    'Only Terms Ready Exit Cases may enter Governance.',
                );
            }

            $this->assertTermsReady($business, $case);
            $payload = $this->snapshotPayload($business, $case);
            $packageHash = $this->contentHash($payload);
            $capability = new Capability(CapabilityCatalog::RECORDS_MANAGE);

            $family = $this->createFamily->execute(
                $user,
                $business,
                $capability,
                new RecordScope(
                    'exit_case',
                    'exit_case',
                    (string) $case->getKey(),
                ),
            );

            if ($family === null) {
                return null;
            }

            $recordVersion = $this->createDraft->execute(
                $user,
                $business,
                $capability,
                (string) $family->getKey(),
                $packageHash,
                sprintf(
                    'Exit Case %s revision %d.',
                    $case->case_number,
                    $expectedRevision,
                ),
                $case->effective_from,
            );

            if ($recordVersion === null) {
                return null;
            }

            DB::table('exit_record_versions')->insert([
                'id' => (string) Str::uuid7(),
                'business_id' => $business->getKey(),
                'exit_case_id' => $case->getKey(),
                'formal_record_version_id' => $recordVersion->getKey(),
                'source_case_revision' => $expectedRevision,
                'partner_id' => $case->partner_id,
                'trigger' => $case->trigger->value,
                'notice_date' => $case->notice_date,
                'intended_exit_date' => $case->intended_exit_date,
                'source_ownership_register_version_id' => $case->source_ownership_register_version_id,
                'share_treatment' => $case->share_treatment,
                'buyer_partner_id' => $case->buyer_partner_id,
                'partner_change_case_id' => $case->partner_change_case_id,
                'ownership_scenario_id' => $case->ownership_scenario_id,
                'valuation_method' => $case->valuation_method,
                'final_buyout_value_minor_units' => $case->final_buyout_value_minor_units,
                'currency' => $case->currency,
                'leaver_classification' => $case->leaver_classification?->value,
                'payment_total_minor_units' => $case->payment_total_minor_units,
                'affordability_status' => $case->affordability_status,
                'governance_decision_type' => $case->governance_decision_type,
                'effective_from' => $case->effective_from,
                'package_hash' => $packageHash,
                'created_at' => now(),
            ]);

            $submitted = $this->submitForReview->execute(
                $user,
                $business,
                $capability,
                (string) $recordVersion->getKey(),
                1,
            );

            if ($submitted === null) {
                return null;
            }

            $proposal = $this->createProposal->execute(
                $user,
                $business,
                $capability,
                $packageHash,
            );

            if ($proposal === null) {
                return null;
            }

            $proposalVersion = $this->freezeProposal->execute(
                $user,
                $business,
                $capability,
                (string) $proposal->getKey(),
                1,
                [(string) $recordVersion->getKey()],
            );

            if ($proposalVersion === null) {
                return null;
            }

            DB::table('exit_governance_submissions')->insert([
                'id' => (string) Str::uuid7(),
                'business_id' => $business->getKey(),
                'exit_case_id' => $case->getKey(),
                'formal_record_version_id' => $recordVersion->getKey(),
                'proposal_id' => $proposal->getKey(),
                'proposal_version_id' => $proposalVersion->getKey(),
                'decision_type' => $case->governance_decision_type,
                'source_case_revision' => $expectedRevision,
                'package_hash' => $packageHash,
                'decision_id' => null,
                'authorized_at' => null,
                'effected_at' => null,
                'created_at' => now(),
            ]);

            $updated = $this->applyTransition(
                $user,
                $business,
                $case,
                ExitCaseStatus::UnderGovernance,
                (string) $membership->getKey(),
            );

            $this->occurrence->record(
                $user,
                $business,
                'exit.governance.submitted',
                'exit_case',
                $caseId,
                [
                    'formal_record_version_id' => (string) $recordVersion->getKey(),
                    'proposal_version_id' => (string) $proposalVersion->getKey(),
                    'source_case_revision' => $expectedRevision,
                    'case_revision' => (int) $updated->revision,
                ],
            );

            return [
                'formal_record_version_id' => (string) $recordVersion->getKey(),
                'proposal_version_id' => (string) $proposalVersion->getKey(),
            ];
        });
    }

    public function advanceContentReview(
        User $user,
        Business $business,
        string $caseId,
        string $formalRecordVersionId,
        FormalRecordState $target,
    ): bool {
        if (! in_array($target, [
            FormalRecordState::UnderReview,
            FormalRecordState::Approved,
            FormalRecordState::ChangesRequested,
        ], true)) {
            throw new InvalidArgumentException(
                'Exit content-review target is invalid.',
            );
        }

        if (! $this->actor->allows(
            $user,
            $business,
            CapabilityCatalog::EXIT_MANAGE,
        )) {
            return false;
        }

        $bound = DB::table('exit_record_versions')
            ->where('business_id', $business->getKey())
            ->where('exit_case_id', $caseId)
            ->where('formal_record_version_id', $formalRecordVersionId)
            ->exists();

        if (! $bound) {
            return false;
        }

        return $this->transitionRecord->execute(
            $user,
            $business,
            new Capability(CapabilityCatalog::RECORDS_MANAGE),
            $formalRecordVersionId,
            $target,
        ) !== null;
    }

    public function syncDecision(
        User $user,
        Business $business,
        string $caseId,
        int $expectedRevision,
    ): ?ExitCase {
        $membership = $this->actor->membership(
            $user,
            $business,
            CapabilityCatalog::EXIT_MANAGE,
        );

        if ($membership === null) {
            return null;
        }

        return DB::transaction(function () use (
            $user,
            $business,
            $caseId,
            $expectedRevision,
            $membership,
        ): ?ExitCase {
            $case = $this->lockCase(
                $user,
                $business,
                $caseId,
                $expectedRevision,
            );

            if ($case->status !== ExitCaseStatus::UnderGovernance) {
                throw new InvalidArgumentException(
                    'Exit Case is not awaiting Governance.',
                );
            }

            $submission = DB::table('exit_governance_submissions')
                ->where('business_id', $business->getKey())
                ->where('exit_case_id', $caseId)
                ->where('source_case_revision', $expectedRevision - 1)
                ->lockForUpdate()
                ->first();

            if ($submission === null) {
                return null;
            }

            $decisions = DB::table('decisions')
                ->where('business_id', $business->getKey())
                ->where(
                    'proposal_version_id',
                    $submission->proposal_version_id,
                )
                ->where('decision_type', $submission->decision_type)
                ->where('status', 'decided')
                ->get();

            if ($decisions->count() !== 1) {
                return null;
            }

            $decision = $decisions->first();
            $approved = (string) $decision->outcome === 'approved';

            DB::table('exit_governance_submissions')
                ->where('id', $submission->id)
                ->where('business_id', $business->getKey())
                ->update([
                    'decision_id' => $decision->id,
                    'authorized_at' => $approved ? now() : null,
                ]);

            $target = $approved
                ? ExitCaseStatus::Approved
                : ExitCaseStatus::Rejected;

            if (! $approved) {
                $this->restorePartnerIfOwnedByCase(
                    $user,
                    $business,
                    $case,
                    'exit_governance_rejected',
                );
            }

            return $this->applyTransition(
                $user,
                $business,
                $case,
                $target,
                (string) $membership->getKey(),
            );
        });
    }

    public function prepareForEffect(
        User $user,
        Business $business,
        string $caseId,
        int $expectedRevision,
    ): ?ExitCase {
        $membership = $this->actor->membership(
            $user,
            $business,
            CapabilityCatalog::EXIT_MANAGE,
        );

        if ($membership === null) {
            return null;
        }

        return DB::transaction(function () use (
            $user,
            $business,
            $caseId,
            $expectedRevision,
            $membership,
        ): ?ExitCase {
            $case = $this->lockCase(
                $user,
                $business,
                $caseId,
                $expectedRevision,
            );

            if ($case->status !== ExitCaseStatus::Approved) {
                throw new InvalidArgumentException(
                    'Only an Approved Exit Case may prepare for Effect.',
                );
            }

            $this->assertEffectPreparation($case);

            $submission = $this->governanceSubmission($business, $caseId);

            if (
                $submission === null
                || $submission->decision_id === null
                || $submission->authorized_at === null
            ) {
                return null;
            }

            if (! $this->prepareEffect->execute(
                $user,
                $business,
                (string) $submission->decision_id,
                (string) $submission->formal_record_version_id,
            )) {
                return null;
            }

            return $this->applyTransition(
                $user,
                $business,
                $case,
                ExitCaseStatus::ReadyForEffect,
                (string) $membership->getKey(),
            );
        });
    }

    public function effect(
        User $user,
        Business $business,
        string $caseId,
        int $expectedRevision,
    ): ?ExitCase {
        $membership = $this->actor->membership(
            $user,
            $business,
            CapabilityCatalog::EXIT_MANAGE,
        );

        if ($membership === null) {
            return null;
        }

        return DB::transaction(function () use (
            $user,
            $business,
            $caseId,
            $expectedRevision,
            $membership,
        ): ?ExitCase {
            $case = $this->lockCase(
                $user,
                $business,
                $caseId,
                $expectedRevision,
            );

            if ($case->status !== ExitCaseStatus::ReadyForEffect) {
                throw new InvalidArgumentException(
                    'Exit Case is not Ready for Effect.',
                );
            }

            $submission = $this->governanceSubmission($business, $caseId);

            if (
                $submission === null
                || $submission->decision_id === null
                || $submission->authorized_at === null
            ) {
                return null;
            }

            if (! $this->makeEffective->execute(
                $user,
                $business,
                (string) $submission->decision_id,
                (string) $submission->formal_record_version_id,
            )) {
                return null;
            }

            DB::table('exit_governance_submissions')
                ->where('id', $submission->id)
                ->where('business_id', $business->getKey())
                ->whereNull('effected_at')
                ->update(['effected_at' => now()]);

            return $this->applyTransition(
                $user,
                $business,
                $case,
                ExitCaseStatus::Effective,
                (string) $membership->getKey(),
            );
        });
    }

    public function transitionMembershipAccess(
        User $user,
        Business $business,
        string $caseId,
        int $expectedRevision,
        MembershipAccessStatus $expected,
        MembershipAccessStatus $target,
    ): bool {
        $case = $this->lockCase(
            $user,
            $business,
            $caseId,
            $expectedRevision,
        );

        if (! in_array($case->status, [
            ExitCaseStatus::Effective,
            ExitCaseStatus::SettlementPending,
        ], true)) {
            throw new InvalidArgumentException(
                'Exit-related Membership access may change only after Exit becomes Effective.',
            );
        }

        if ($case->membership_id === null) {
            throw new InvalidArgumentException(
                'This Partner has no linked Membership access to transition.',
            );
        }

        return $this->membershipAccess->execute(
            $user,
            $business,
            (string) $case->membership_id,
            $expected,
            $target,
            'exit_access_transition',
            'exit_case',
            $caseId,
        );
    }

    public function refreshSettlement(
        User $user,
        Business $business,
        string $caseId,
        int $expectedRevision,
    ): ?ExitCase {
        $membership = $this->actor->membership(
            $user,
            $business,
            CapabilityCatalog::EXIT_MANAGE,
        );

        if ($membership === null) {
            return null;
        }

        return DB::transaction(function () use (
            $user,
            $business,
            $caseId,
            $expectedRevision,
            $membership,
        ): ?ExitCase {
            $case = $this->lockCase(
                $user,
                $business,
                $caseId,
                $expectedRevision,
            );

            if (! in_array($case->status, [
                ExitCaseStatus::Effective,
                ExitCaseStatus::SettlementPending,
            ], true)) {
                throw new InvalidArgumentException(
                    'Settlement may be refreshed only after Exit becomes Effective.',
                );
            }

            $settlement = $this->deriveSettlement($business, $case);
            $nextStatus = $case->status;

            if (
                $case->status === ExitCaseStatus::Effective
                && in_array($settlement['status'], ['pending', 'partial'], true)
            ) {
                $nextStatus = ExitCaseStatus::SettlementPending;
            }

            $newRevision = $expectedRevision + 1;

            ExitCase::query()
                ->where('business_id', $business->getKey())
                ->whereKey($caseId)
                ->where('revision', $expectedRevision)
                ->update([
                    'status' => $nextStatus->value,
                    'settlement_status' => $settlement['status'],
                    'settled_at' => $settlement['settled_at'],
                    'revision' => $newRevision,
                    'updated_at' => now(),
                ]);

            if ($nextStatus !== $case->status) {
                $this->recordTransition(
                    $business,
                    $caseId,
                    $newRevision,
                    $case->status,
                    $nextStatus,
                    (string) $membership->getKey(),
                );
            }

            $this->occurrence->record(
                $user,
                $business,
                'exit.settlement.refreshed',
                'exit_case',
                $caseId,
                [
                    'settlement_status' => $settlement['status'],
                    'revision' => $newRevision,
                ],
            );

            return ExitCase::query()->findOrFail($caseId);
        });
    }

    public function complete(
        User $user,
        Business $business,
        string $caseId,
        int $expectedRevision,
    ): ?ExitCase {
        $membership = $this->actor->membership(
            $user,
            $business,
            CapabilityCatalog::EXIT_MANAGE,
        );

        if ($membership === null) {
            return null;
        }

        return DB::transaction(function () use (
            $user,
            $business,
            $caseId,
            $expectedRevision,
            $membership,
        ): ?ExitCase {
            $case = $this->lockCase(
                $user,
                $business,
                $caseId,
                $expectedRevision,
            );

            if (! in_array($case->status, [
                ExitCaseStatus::Effective,
                ExitCaseStatus::SettlementPending,
            ], true)) {
                throw new InvalidArgumentException(
                    'Only an Effective Exit may complete.',
                );
            }

            $settlement = $this->deriveSettlement($business, $case);

            $this->assertCompletion(
                $business,
                $case,
                $settlement['status'],
            );

            if (! $this->partnerLifecycle->transition(
                $user,
                $business,
                (string) $case->partner_id,
                'exiting',
                'former',
                'exit_completed',
                'exit_case',
                $caseId,
                CapabilityCatalog::EXIT_MANAGE,
            )) {
                throw new RuntimeException(
                    'Partner lifecycle could not transition to Former.',
                );
            }

            $newRevision = $expectedRevision + 1;

            ExitCase::query()
                ->where('business_id', $business->getKey())
                ->whereKey($caseId)
                ->where('revision', $expectedRevision)
                ->update([
                    'status' => ExitCaseStatus::Completed->value,
                    'settlement_status' => $settlement['status'],
                    'settled_at' => $settlement['settled_at'],
                    'completed_at' => now(),
                    'revision' => $newRevision,
                    'updated_at' => now(),
                ]);

            $this->recordTransition(
                $business,
                $caseId,
                $newRevision,
                $case->status,
                ExitCaseStatus::Completed,
                (string) $membership->getKey(),
            );

            $this->occurrence->record(
                $user,
                $business,
                'exit.case.completed',
                'exit_case',
                $caseId,
                [
                    'partner_id' => (string) $case->partner_id,
                    'settlement_status' => $settlement['status'],
                    'revision' => $newRevision,
                ],
            );

            return ExitCase::query()->findOrFail($caseId);
        });
    }

    private function assertTermsReady(
        Business $business,
        ExitCase $case,
    ): void {
        if (
            $case->notice_date === null
            || $this->nullableText($case->notice_summary) === null
        ) {
            throw new InvalidArgumentException(
                'Exit Notice must be recorded before Terms Ready.',
            );
        }

        if ($case->share_treatment === null) {
            throw new InvalidArgumentException(
                'Exit Share Treatment must be recorded before Terms Ready.',
            );
        }

        if ($case->leaver_classification === null) {
            throw new InvalidArgumentException(
                'Leaver classification must be explicit before Governance.',
            );
        }

        if (
            ($case->payment_total_minor_units ?? 0) > 0
            && $this->nullableText($case->payment_terms_summary) === null
        ) {
            throw new InvalidArgumentException(
                'Payment terms must be recorded before Governance.',
            );
        }

        if ($case->affordability_status === 'pending') {
            throw new InvalidArgumentException(
                'Affordability must be assessed before Governance.',
            );
        }

        if ($case->affordability_status === 'not_affordable') {
            throw new InvalidArgumentException(
                'Unaffordable Exit terms require an approved alternative structure before Governance.',
            );
        }

        foreach ([
            'legal_terms_ready',
            'handover_plan_ready',
            'post_exit_obligations_recorded',
        ] as $requirement) {
            $this->assertRequirementReady($case, $requirement);
        }

        if (
            $case->source_ownership_register_version_id !== null
            && ! DB::table('ownership_register_versions')
                ->where('business_id', $business->getKey())
                ->where('id', $case->source_ownership_register_version_id)
                ->exists()
        ) {
            throw new InvalidArgumentException(
                'Captured Ownership Register is unavailable.',
            );
        }
    }

    private function assertEffectPreparation(ExitCase $case): void
    {
        foreach ([
            'legal_documentation_complete',
            'handover_plan_ready',
            'post_exit_obligations_recorded',
        ] as $requirement) {
            $this->assertRequirementReady($case, $requirement);
        }
    }

    private function assertCompletion(
        Business $business,
        ExitCase $case,
        string $settlementStatus,
    ): void {
        $this->assertShareTreatmentEffective($business, $case);

        foreach ([
            'operations_handover_complete',
            'continuity_update_complete',
            'business_property_returned',
            'documents_handed_over',
            'post_exit_obligations_recorded',
        ] as $requirement) {
            $this->assertRequirementReady($case, $requirement);
        }

        if ($case->membership_id !== null) {
            $accessStatus = DB::table('memberships')
                ->where('business_id', $business->getKey())
                ->where('id', $case->membership_id)
                ->value('access_status');

            if ($accessStatus === MembershipAccessStatus::Active->value) {
                throw new InvalidArgumentException(
                    'Partner Membership access must be explicitly suspended or revoked before Former status.',
                );
            }
        }

        if (in_array($settlementStatus, ['pending', 'partial'], true)) {
            $this->assertRequirementReady(
                $case,
                'post_exit_payment_obligation_recorded',
            );

            $hasAuthorizedFinance = DB::table('exit_finance_links as link')
                ->join(
                    'finance_payments as payment',
                    function ($join): void {
                        $join
                            ->on(
                                'payment.id',
                                '=',
                                'link.finance_payment_id',
                            )
                            ->on(
                                'payment.business_id',
                                '=',
                                'link.business_id',
                            );
                    },
                )
                ->where('link.business_id', $business->getKey())
                ->where('link.exit_case_id', $case->getKey())
                ->whereIn('payment.status', [
                    'authorized',
                    'paid',
                    'completed',
                ])
                ->exists();

            if (! $hasAuthorizedFinance) {
                throw new InvalidArgumentException(
                    'Outstanding post-Exit payment obligations require an authorized F6C Finance Payment reference.',
                );
            }
        }
    }

    private function assertShareTreatmentEffective(
        Business $business,
        ExitCase $case,
    ): void {
        $treatment = (string) $case->share_treatment;
        $currentShares = $this->currentSharesForPartner($business, $case);
        $capturedShares = $this->capturedShares($business, $case);

        if ($treatment === 'no_shares') {
            if ($capturedShares !== '0.00000000' || $currentShares !== '0.00000000') {
                throw new InvalidArgumentException(
                    'no_shares treatment is valid only when the Partner has no Ownership Shares.',
                );
            }

            return;
        }

        if ($treatment === 'retain_per_agreement') {
            $this->assertRequirementReady(
                $case,
                'ownership_retention_authorized',
            );

            return;
        }

        $canonicalEffect = false;

        if ($case->partner_change_case_id !== null) {
            $partnerChange = DB::table('partner_change_cases')
                ->where('business_id', $business->getKey())
                ->where('id', $case->partner_change_case_id)
                ->where('seller_partner_id', $case->partner_id)
                ->where('status', 'completed')
                ->first();

            $canonicalEffect = $partnerChange !== null
                && DB::table('ownership_register_transfer_sources')
                    ->where('business_id', $business->getKey())
                    ->where(
                        'partner_change_case_id',
                        $case->partner_change_case_id,
                    )
                    ->exists();
        }

        if (! $canonicalEffect && $case->ownership_scenario_id !== null) {
            $canonicalEffect = DB::table('ownership_register_versions')
                ->where('business_id', $business->getKey())
                ->where(
                    'source_ownership_scenario_id',
                    $case->ownership_scenario_id,
                )
                ->whereIn('status', ['effective', 'superseded'])
                ->exists();
        }

        if ($treatment === 'other') {
            $this->assertRequirementReady(
                $case,
                'ownership_treatment_effective',
            );
            $canonicalEffect = true;
        }

        if (! $canonicalEffect) {
            throw new InvalidArgumentException(
                'Exit Share Treatment has not become Effective through canonical PartnerChanges/Ownership truth.',
            );
        }

        if ($treatment === 'partial_buyout') {
            $less = DB::selectOne(
                'SELECT CAST(? AS numeric) < CAST(? AS numeric) AS reduced',
                [$currentShares, $capturedShares],
            );

            if ($less === null || ! (bool) $less->reduced) {
                throw new InvalidArgumentException(
                    'Partial buyout must reduce the exiting Partner Shares.',
                );
            }

            return;
        }

        if ($currentShares !== '0.00000000') {
            throw new InvalidArgumentException(
                'Full Exit Share Treatment must leave no current Shares for the exiting Partner.',
            );
        }
    }

    /** @return array{status:string,settled_at:?string} */
    private function deriveSettlement(
        Business $business,
        ExitCase $case,
    ): array {
        $total = (int) ($case->payment_total_minor_units ?? 0);

        if ($total === 0) {
            return [
                'status' => 'not_applicable',
                'settled_at' => null,
            ];
        }

        $row = DB::table('exit_finance_links as link')
            ->join(
                'finance_payments as payment',
                function ($join): void {
                    $join
                        ->on(
                            'payment.id',
                            '=',
                            'link.finance_payment_id',
                        )
                        ->on(
                            'payment.business_id',
                            '=',
                            'link.business_id',
                        );
                },
            )
            ->where('link.business_id', $business->getKey())
            ->where('link.exit_case_id', $case->getKey())
            ->selectRaw(
                <<<'SQL'
COALESCE(SUM(
    CASE
        WHEN payment.status IN ('paid','completed')
        THEN payment.amount_minor_units
        ELSE 0
    END
), 0)::bigint AS paid_total,
MAX(
    CASE
        WHEN payment.status IN ('paid','completed')
        THEN COALESCE(payment.completed_at, payment.paid_at)
        ELSE NULL
    END
) AS last_paid_at
SQL,
            )
            ->first();

        $paid = (int) ($row?->paid_total ?? 0);

        if ($paid >= $total) {
            return [
                'status' => 'settled',
                'settled_at' => $row?->last_paid_at === null
                    ? now()->toAtomString()
                    : CarbonImmutable::parse(
                        (string) $row->last_paid_at,
                    )->toAtomString(),
            ];
        }

        return [
            'status' => $paid > 0 ? 'partial' : 'pending',
            'settled_at' => null,
        ];
    }

    private function validateRequirementSource(
        User $user,
        Business $business,
        ExitCase $case,
        ?string $sourceType,
        ?string $sourceId,
    ): bool {
        $type = $this->nullableText($sourceType);

        if ($type === null && $sourceId === null) {
            return true;
        }

        if ($type === null || $sourceId === null) {
            throw new InvalidArgumentException(
                'Exit requirement source type and ID must be provided together.',
            );
        }

        return match ($type) {
            'operations_role_assignment' => $this->isCurrentOperationsAssignment(
                $business,
                $case,
                $sourceId,
            ),
            'continuity_plan' => $this->isCurrentEffectiveRecord(
                $business,
                'continuity_plan',
                $sourceId,
            ),
            'partner_change_case' => DB::table('partner_change_cases')
                ->where('business_id', $business->getKey())
                ->where('id', $sourceId)
                ->where('seller_partner_id', $case->partner_id)
                ->exists(),
            'finance_payment' => DB::table('finance_payments')
                ->where('business_id', $business->getKey())
                ->where('id', $sourceId)
                ->exists(),
            'formal_record_version' => DB::table('formal_record_versions')
                ->where('business_id', $business->getKey())
                ->where('id', $sourceId)
                ->exists(),
            'conflict_case' => $this->conflictVisibility->canView(
                $user,
                $business,
                $sourceId,
            ),
            default => throw new InvalidArgumentException(
                'Exit requirement source type is invalid.',
            ),
        };
    }

    private function isCurrentOperationsAssignment(
        Business $business,
        ExitCase $case,
        string $assignmentId,
    ): bool {
        if ($case->membership_id === null) {
            return false;
        }

        $versionId = $this->currentEffectiveRecordVersionId(
            $business,
            'operations_register',
        );

        if ($versionId === null) {
            return false;
        }

        return DB::table('operations_role_assignments')
            ->where('business_id', $business->getKey())
            ->where('id', $assignmentId)
            ->where('formal_record_version_id', $versionId)
            ->where('membership_id', $case->membership_id)
            ->exists();
    }

    private function isCurrentEffectiveRecord(
        Business $business,
        string $recordType,
        string $versionId,
    ): bool {
        return $this->currentEffectiveRecordVersionId(
            $business,
            $recordType,
        ) === $versionId;
    }

    private function currentEffectiveRecordVersionId(
        Business $business,
        string $recordType,
    ): ?string {
        $id = DB::table('record_family_effective_heads as head')
            ->join(
                'formal_record_versions as version',
                function ($join): void {
                    $join
                        ->on(
                            'version.id',
                            '=',
                            'head.formal_record_version_id',
                        )
                        ->on(
                            'version.business_id',
                            '=',
                            'head.business_id',
                        );
                },
            )
            ->join(
                'formal_record_families as family',
                function ($join): void {
                    $join
                        ->on(
                            'family.id',
                            '=',
                            'version.formal_record_family_id',
                        )
                        ->on(
                            'family.business_id',
                            '=',
                            'version.business_id',
                        );
                },
            )
            ->where('head.business_id', $business->getKey())
            ->where('family.record_type', $recordType)
            ->value('version.id');

        return $id === null ? null : (string) $id;
    }

    private function ensurePartnerExiting(
        User $user,
        Business $business,
        ExitCase $case,
    ): bool {
        $status = DB::table('partners')
            ->where('business_id', $business->getKey())
            ->where('id', $case->partner_id)
            ->value('status');

        if ($status === 'exiting') {
            $latest = DB::table('partner_lifecycle_transitions')
                ->where('business_id', $business->getKey())
                ->where('partner_id', $case->partner_id)
                ->where('to_status', 'exiting')
                ->orderByDesc('occurred_at')
                ->orderByDesc('id')
                ->first(['source_type', 'source_id']);

            return $latest !== null
                && (string) $latest->source_type === 'exit_case'
                && (string) $latest->source_id === (string) $case->getKey();
        }

        if ($status !== 'active') {
            return false;
        }

        return $this->partnerLifecycle->transition(
            $user,
            $business,
            (string) $case->partner_id,
            'active',
            'exiting',
            'exit_notice_recorded',
            'exit_case',
            (string) $case->getKey(),
            CapabilityCatalog::EXIT_MANAGE,
        );
    }

    private function restorePartnerIfOwnedByCase(
        User $user,
        Business $business,
        ExitCase $case,
        string $reason,
    ): void {
        $status = DB::table('partners')
            ->where('business_id', $business->getKey())
            ->where('id', $case->partner_id)
            ->value('status');

        if ($status !== 'exiting') {
            return;
        }

        $latest = DB::table('partner_lifecycle_transitions')
            ->where('business_id', $business->getKey())
            ->where('partner_id', $case->partner_id)
            ->where('to_status', 'exiting')
            ->orderByDesc('occurred_at')
            ->orderByDesc('id')
            ->first(['source_type', 'source_id']);

        if (
            $latest === null
            || (string) $latest->source_type !== 'exit_case'
            || (string) $latest->source_id !== (string) $case->getKey()
        ) {
            return;
        }

        if (! $this->partnerLifecycle->transition(
            $user,
            $business,
            (string) $case->partner_id,
            'exiting',
            'active',
            $reason,
            'exit_case',
            (string) $case->getKey(),
            CapabilityCatalog::EXIT_MANAGE,
        )) {
            throw new RuntimeException(
                'Partner lifecycle could not be restored after Exit termination.',
            );
        }
    }

    private function lockCase(
        User $user,
        Business $business,
        string $caseId,
        int $expectedRevision,
    ): ExitCase {
        $case = ExitCase::query()
            ->where('business_id', $business->getKey())
            ->whereKey($caseId)
            ->lockForUpdate()
            ->first();

        if ($case === null) {
            throw new InvalidArgumentException(
                'Exit Case was not found in the current Business.',
            );
        }

        $decision = $this->authorize->decide(
            $user,
            $business,
            $business,
            new Capability(CapabilityCatalog::EXIT_MANAGE),
            ExitCase::class,
            (string) $case->getKey(),
        );

        if (! $decision->allowed) {
            throw new InvalidArgumentException(
                'Exit Case is not available to this Membership.',
            );
        }

        if ((int) $case->revision !== $expectedRevision) {
            throw new StaleRevision(
                $expectedRevision,
                (int) $case->revision,
            );
        }

        return $case;
    }

    private function applyTransition(
        User $user,
        Business $business,
        ExitCase $case,
        ExitCaseStatus $target,
        string $actorMembershipId,
    ): ExitCase {
        $from = $case->status;

        if (! $this->stateMachine->allows($from, $target)) {
            throw new InvalidArgumentException(
                "Exit transition {$from->value} -> {$target->value} is not allowed.",
            );
        }

        $newRevision = ((int) $case->revision) + 1;

        ExitCase::query()
            ->where('business_id', $business->getKey())
            ->whereKey($case->getKey())
            ->where('revision', $case->revision)
            ->update([
                'status' => $target->value,
                'revision' => $newRevision,
                'updated_at' => now(),
            ]);

        $this->recordTransition(
            $business,
            (string) $case->getKey(),
            $newRevision,
            $from,
            $target,
            $actorMembershipId,
        );

        $this->occurrence->record(
            $user,
            $business,
            'exit.case.transitioned',
            'exit_case',
            (string) $case->getKey(),
            [
                'from_status' => $from->value,
                'to_status' => $target->value,
                'revision' => $newRevision,
            ],
        );

        return ExitCase::query()->findOrFail($case->getKey());
    }

    private function recordTransition(
        Business $business,
        string $caseId,
        int $caseRevision,
        ?ExitCaseStatus $from,
        ExitCaseStatus $to,
        string $actorMembershipId,
    ): void {
        DB::table('exit_case_transitions')->insert([
            'id' => (string) Str::uuid7(),
            'business_id' => $business->getKey(),
            'exit_case_id' => $caseId,
            'case_revision' => $caseRevision,
            'from_status' => $from?->value,
            'to_status' => $to->value,
            'actor_membership_id' => $actorMembershipId,
            'occurred_at' => now(),
            'created_at' => now(),
        ]);
    }

    private function currentOwnershipRegister(string $businessId): ?object
    {
        return DB::table('ownership_register_versions')
            ->where('business_id', $businessId)
            ->where('status', 'effective')
            ->where('effective_from', '<=', now())
            ->where(function ($query): void {
                $query->whereNull('effective_until')
                    ->orWhere('effective_until', '>', now());
            })
            ->orderByDesc('effective_from')
            ->first();
    }

    private function snapshotSharePosition(
        string $businessId,
        string $caseId,
        string $partnerId,
        ?object $register,
    ): void {
        if ($register === null) {
            return;
        }

        $positions = DB::table('ownership_register_positions as position')
            ->join(
                'ownership_register_share_classes as class',
                function ($join): void {
                    $join
                        ->on(
                            'class.id',
                            '=',
                            'position.share_class_id',
                        )
                        ->on(
                            'class.business_id',
                            '=',
                            'position.business_id',
                        )
                        ->on(
                            'class.ownership_register_version_id',
                            '=',
                            'position.ownership_register_version_id',
                        );
                },
            )
            ->where('position.business_id', $businessId)
            ->where(
                'position.ownership_register_version_id',
                $register->id,
            )
            ->where('position.partner_id', $partnerId)
            ->get([
                'position.share_class_id',
                'position.shares_issued',
                'position.shares_vested',
                'position.voting_rights',
                'position.profit_rights',
                'class.name',
                'class.voting_right_per_share',
                'class.profit_right_per_share',
            ]);

        foreach ($positions as $position) {
            $unvested = DB::selectOne(
                'SELECT GREATEST(CAST(? AS numeric) - CAST(? AS numeric), 0)::text AS value',
                [$position->shares_issued, $position->shares_vested],
            );

            DB::table('exit_share_positions')->insert([
                'id' => (string) Str::uuid7(),
                'business_id' => $businessId,
                'exit_case_id' => $caseId,
                'source_ownership_register_version_id' => $register->id,
                'partner_id' => $partnerId,
                'source_share_class_id' => $position->share_class_id,
                'share_class_name' => $position->name,
                'voting_right_per_share' => $position->voting_right_per_share,
                'profit_right_per_share' => $position->profit_right_per_share,
                'shares_issued' => $position->shares_issued,
                'shares_vested' => $position->shares_vested,
                'shares_unvested' => $unvested?->value ?? '0',
                'voting_rights' => $position->voting_rights,
                'profit_rights' => $position->profit_rights,
                'outstanding_obligations_summary' => null,
                'captured_at' => now(),
            ]);
        }
    }

    private function capturedShares(
        Business $business,
        ExitCase $case,
    ): string {
        $row = DB::selectOne(
            <<<'SQL'
SELECT COALESCE(SUM(shares_issued), 0)::numeric(28,8)::text AS value
FROM exit_share_positions
WHERE business_id = ?
  AND exit_case_id = ?
SQL,
            [$business->getKey(), $case->getKey()],
        );

        return (string) ($row?->value ?? '0.00000000');
    }

    private function currentSharesForPartner(
        Business $business,
        ExitCase $case,
    ): string {
        $register = $this->currentOwnershipRegister(
            (string) $business->getKey(),
        );

        if ($register === null) {
            return '0.00000000';
        }

        $row = DB::selectOne(
            <<<'SQL'
SELECT COALESCE(SUM(shares_issued), 0)::numeric(28,8)::text AS value
FROM ownership_register_positions
WHERE business_id = ?
  AND ownership_register_version_id = ?
  AND partner_id = ?
SQL,
            [
                $business->getKey(),
                $register->id,
                $case->partner_id,
            ],
        );

        return (string) ($row?->value ?? '0.00000000');
    }

    private function assertRequirementReady(
        ExitCase $case,
        string $key,
    ): void {
        $status = DB::table('exit_requirements')
            ->where('business_id', $case->business_id)
            ->where('exit_case_id', $case->getKey())
            ->where('requirement_key', $key)
            ->orderByDesc('recorded_at')
            ->orderByDesc('id')
            ->value('status');

        if (! in_array($status, ['met', 'not_applicable'], true)) {
            throw new InvalidArgumentException(
                "Exit requirement {$key} is not satisfied.",
            );
        }
    }

    private function governanceSubmission(
        Business $business,
        string $caseId,
    ): ?object {
        return DB::table('exit_governance_submissions')
            ->where('business_id', $business->getKey())
            ->where('exit_case_id', $caseId)
            ->orderByDesc('created_at')
            ->first();
    }

    /** @return array<string,mixed> */
    private function snapshotPayload(
        Business $business,
        ExitCase $case,
    ): array {
        $caseId = (string) $case->getKey();

        return [
            'business_id' => (string) $business->getKey(),
            'case_id' => $caseId,
            'case_number' => (string) $case->case_number,
            'case_revision' => (int) $case->revision,
            'partner_id' => (string) $case->partner_id,
            'membership_id' => $case->membership_id,
            'trigger' => $case->trigger->value,
            'trigger_detail' => $case->trigger_detail,
            'notice_date' => $case->notice_date?->toDateString(),
            'intended_exit_date' => $case->intended_exit_date?->toDateString(),
            'required_notice_days' => $case->required_notice_days,
            'notice_summary' => $case->notice_summary,
            'source_ownership_register_version_id' => $case->source_ownership_register_version_id,
            'share_treatment' => $case->share_treatment,
            'buyer_partner_id' => $case->buyer_partner_id,
            'partner_change_case_id' => $case->partner_change_case_id,
            'ownership_scenario_id' => $case->ownership_scenario_id,
            'valuation_method' => $case->valuation_method,
            'approved_business_value_minor_units' => $case->approved_business_value_minor_units,
            'leaver_adjustment_minor_units' => $case->leaver_adjustment_minor_units,
            'final_buyout_value_minor_units' => $case->final_buyout_value_minor_units,
            'currency' => $case->currency,
            'leaver_classification' => $case->leaver_classification?->value,
            'leaver_rule_reference' => $case->leaver_rule_reference,
            'payment_total_minor_units' => $case->payment_total_minor_units,
            'payment_terms_summary' => $case->payment_terms_summary,
            'deposit_minor_units' => $case->deposit_minor_units,
            'installment_minor_units' => $case->installment_minor_units,
            'installment_count' => $case->installment_count,
            'payment_frequency' => $case->payment_frequency,
            'first_payment_date' => $case->first_payment_date?->toDateString(),
            'final_payment_date' => $case->final_payment_date?->toDateString(),
            'interest_terms' => $case->interest_terms,
            'security_terms' => $case->security_terms,
            'late_payment_rule' => $case->late_payment_rule,
            'affordability_status' => $case->affordability_status,
            'alternative_payment_structure' => $case->alternative_payment_structure,
            'governance_decision_type' => $case->governance_decision_type,
            'effective_from' => $case->effective_from?->toAtomString(),
            'share_position_snapshot' => DB::table('exit_share_positions')
                ->where('business_id', $business->getKey())
                ->where('exit_case_id', $caseId)
                ->orderBy('share_class_name')
                ->get()
                ->map(static fn (object $row): array => (array) $row)
                ->all(),
            'requirements' => DB::table('exit_requirements')
                ->where('business_id', $business->getKey())
                ->where('exit_case_id', $caseId)
                ->orderBy('recorded_at')
                ->orderBy('id')
                ->get()
                ->map(static fn (object $row): array => (array) $row)
                ->all(),
            'finance_links' => DB::table('exit_finance_links')
                ->where('business_id', $business->getKey())
                ->where('exit_case_id', $caseId)
                ->orderBy('linked_at')
                ->get()
                ->map(static fn (object $row): array => (array) $row)
                ->all(),
        ];
    }

    /** @param array<string,mixed> $payload */
    private function contentHash(array $payload): string
    {
        return hash(
            'sha256',
            json_encode(
                $payload,
                JSON_THROW_ON_ERROR
                    | JSON_UNESCAPED_SLASHES
                    | JSON_UNESCAPED_UNICODE,
            ),
        );
    }

    private function nullableText(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $value = trim((string) $value);

        return $value === '' ? null : $value;
    }

    private function nullableCurrency(?string $value): ?string
    {
        $currency = $this->nullableText($value);

        if ($currency === null) {
            return null;
        }

        $currency = strtoupper($currency);

        if (preg_match('/\A[A-Z]{3}\z/', $currency) !== 1) {
            throw new InvalidArgumentException(
                'Currency must be an ISO-style three-letter code.',
            );
        }

        return $currency;
    }
}
