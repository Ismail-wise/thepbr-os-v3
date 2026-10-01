<?php

declare(strict_types=1);

namespace App\Application\PartnerChanges;

use App\Application\Access\AuthorizeBusinessCapability;
use App\Application\Governance\MakeGovernedRecordEffective;
use App\Application\Governance\PrepareGovernedRecordForEffect;
use App\Application\Partnership\OwnershipTransferWorkflow;
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
use App\Domain\Governance\Exceptions\MissingGovernanceDecision;
use App\Domain\PartnerChanges\Enums\PartnerChangeEligibilityStatus;
use App\Domain\PartnerChanges\Enums\PartnerChangeStatus;
use App\Domain\PartnerChanges\Enums\PartnerChangeTransactionType;
use App\Domain\PartnerChanges\Enums\RofrResponseStatus;
use App\Domain\PartnerChanges\Services\PartnerChangeStateMachine;
use App\Domain\Partnership\ValueObjects\ShareQuantity;
use App\Domain\Records\Enums\FormalRecordState;
use App\Domain\Records\Exceptions\StaleRevision;
use App\Domain\Records\ValueObjects\RecordScope;
use App\Infrastructure\Persistence\Eloquent\Businesses\Business;
use App\Infrastructure\Persistence\Eloquent\Identity\User;
use App\Infrastructure\Persistence\Eloquent\PartnerChanges\PartnerChangeCase;
use Carbon\CarbonImmutable;
use DateTimeInterface;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;
use RuntimeException;

final class PartnerChangeWorkflow
{
    public function __construct(
        private readonly PartnershipActorContext $actor,
        private readonly AuthorizeBusinessCapability $authorize,
        private readonly RecordPartnerChangeOccurrence $occurrence,
        private readonly PartnerChangeStateMachine $stateMachine,
        private readonly CreateFormalRecordFamily $createFamily,
        private readonly CreateDraftRecordVersion $createDraft,
        private readonly SubmitRecordVersionForReview $submitForReview,
        private readonly TransitionFormalRecordVersion $transitionRecord,
        private readonly CreateProposal $createProposal,
        private readonly FreezeProposalVersion $freezeProposal,
        private readonly PrepareGovernedRecordForEffect $prepareEffect,
        private readonly MakeGovernedRecordEffective $makeEffective,
        private readonly OwnershipTransferWorkflow $ownershipTransfer,
        private readonly PartnerLifecycleWorkflow $partnerLifecycle,
    ) {}

    public function createCase(
        User $user,
        Business $business,
        PartnerChangeTransactionType $type,
        string $buyerPartnerId,
        ?string $sellerPartnerId,
        ?string $sourceShareClassId,
        ?ShareQuantity $shares,
        ?string $currency,
        ?int $considerationMinorUnits,
        ?string $valuationMethod,
        ?string $rightsImpactSummary,
        string $governanceDecisionType,
        bool $rofrRequired,
        ?DateTimeInterface $effectiveFrom,
    ): ?PartnerChangeCase {
        $membership = $this->actor->membership(
            $user,
            $business,
            CapabilityCatalog::PARTNER_CHANGES_MANAGE,
        );

        if ($membership === null) {
            return null;
        }

        $businessId = (string) $business->getKey();
        $buyer = DB::table('partners')
            ->where('business_id', $businessId)
            ->where('id', $buyerPartnerId)
            ->first(['id', 'status']);

        if ($buyer === null || (string) $buyer->status === 'former') {
            return null;
        }

        $decisionType = trim($governanceDecisionType);

        if ($decisionType === '' || mb_strlen($decisionType) > 160) {
            throw new InvalidArgumentException(
                'A valid Governance Decision Type is required.',
            );
        }

        if ($considerationMinorUnits !== null && $considerationMinorUnits < 0) {
            throw new InvalidArgumentException(
                'Consideration cannot be negative.',
            );
        }
        $sourceRegisterId = null;

        if ($type->changesOwnership()) {
            $source = DB::table('ownership_register_versions')
                ->where('business_id', $businessId)
                ->where('status', 'effective')
                ->where('effective_from', '<=', now())
                ->where(function ($query): void {
                    $query->whereNull('effective_until')
                        ->orWhere('effective_until', '>', now());
                })
                ->orderByDesc('effective_from')
                ->first();

            if ($source === null) {
                throw new InvalidArgumentException(
                    'A current Effective Ownership Register is required.',
                );
            }

            $sourceRegisterId = (string) $source->id;

            if ($sourceShareClassId === null || $shares === null) {
                throw new InvalidArgumentException(
                    'Ownership changes require a Share Class and Share quantity.',
                );
            }

            $shareClass = DB::table('ownership_register_share_classes')
                ->where('business_id', $businessId)
                ->where('ownership_register_version_id', $sourceRegisterId)
                ->where('id', $sourceShareClassId)
                ->first();

            if ($shareClass === null) {
                return null;
            }

            if ($type === PartnerChangeTransactionType::TransferExisting) {
                if (
                    $sellerPartnerId === null
                    || $sellerPartnerId === $buyerPartnerId
                ) {
                    throw new InvalidArgumentException(
                        'Existing-share transfer requires distinct Seller and Buyer Partners.',
                    );
                }

                $sellerExists = DB::table('partners')
                    ->where('business_id', $businessId)
                    ->where('id', $sellerPartnerId)
                    ->exists();

                if (! $sellerExists) {
                    return null;
                }
            } elseif ($sellerPartnerId !== null) {
                throw new InvalidArgumentException(
                    'New-share issue must not identify a Seller Partner.',
                );
            }
        } else {
            if (
                $sellerPartnerId !== null
                || $sourceShareClassId !== null
                || $shares !== null
                || $rofrRequired
            ) {
                throw new InvalidArgumentException(
                    'Admission-only cases cannot contain transfer/share fields.',
                );
            }
        }

        $case = PartnerChangeCase::query()->create([
            'business_id' => $businessId,
            'case_number' => sprintf(
                'PC-%s-%s',
                now()->format('Ymd'),
                strtoupper(substr(str_replace('-', '', (string) Str::uuid7()), 0, 8)),
            ),
            'transaction_type' => $type->value,
            'source_ownership_register_version_id' => $sourceRegisterId,
            'seller_partner_id' => $sellerPartnerId,
            'buyer_partner_id' => $buyerPartnerId,
            'source_share_class_id' => $sourceShareClassId,
            'shares' => $shares?->value(),
            'currency' => $this->currency($currency),
            'consideration_minor_units' => $considerationMinorUnits,
            'valuation_method' => $this->nullableText($valuationMethod),
            'rights_impact_summary' => $this->nullableText($rightsImpactSummary),
            'governance_decision_type' => $decisionType,
            'rofr_required' => $rofrRequired,
            'status' => PartnerChangeStatus::Draft->value,
            'effective_from' => $effectiveFrom,
            'created_by_membership_id' => $membership->getKey(),
            'revision' => 1,
        ]);

        DB::table('partner_change_case_transitions')->insert([
            'id' => (string) Str::uuid7(),
            'business_id' => $businessId,
            'partner_change_case_id' => $case->getKey(),
            'sequence' => 1,
            'from_status' => null,
            'to_status' => PartnerChangeStatus::Draft->value,
            'actor_membership_id' => $membership->getKey(),
            'occurred_at' => now(),
            'created_at' => now(),
        ]);

        $this->occurrence->record(
            $user,
            $business,
            'partner_change.case.created',
            'partner_change_case',
            (string) $case->getKey(),
            [
                'transaction_type' => $type->value,
                'status' => PartnerChangeStatus::Draft->value,
                'revision' => 1,
            ],
        );

        return $case->fresh();
    }

    public function recordEligibility(
        User $user,
        Business $business,
        string $caseId,
        int $expectedRevision,
        string $checkKey,
        PartnerChangeEligibilityStatus $result,
        ?string $detail = null,
        ?string $sourceType = null,
        ?string $sourceId = null,
    ): bool {
        $membership = $this->actor->membership(
            $user,
            $business,
            CapabilityCatalog::PARTNER_CHANGES_MANAGE,
        );

        if ($membership === null) {
            return false;
        }

        return DB::transaction(function () use (
            $user,
            $business,
            $caseId,
            $expectedRevision,
            $checkKey,
            $result,
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

            if ($case->status !== PartnerChangeStatus::EligibilityReview) {
                throw new InvalidArgumentException(
                    'Eligibility checks may only be recorded during Eligibility Review.',
                );
            }

            $key = trim($checkKey);

            if ($key === '' || mb_strlen($key) > 80) {
                throw new InvalidArgumentException(
                    'Eligibility check key is invalid.',
                );
            }

            $normalizedDetail = $this->nullableText($detail);
            $normalizedSourceType = $this->nullableText($sourceType);

            $duplicate = DB::table(
                'partner_change_eligibility_checks',
            )
                ->where(
                    'business_id',
                    $business->getKey(),
                )
                ->where(
                    'partner_change_case_id',
                    $caseId,
                )
                ->where(
                    'case_revision',
                    $expectedRevision,
                )
                ->where('check_key', $key)
                ->where('result', $result->value)
                ->where(
                    'checked_by_membership_id',
                    $membership->getKey(),
                )
                ->when(
                    $normalizedDetail === null,
                    fn ($query) => $query->whereNull('detail'),
                    fn ($query) => $query->where(
                        'detail',
                        $normalizedDetail,
                    ),
                )
                ->when(
                    $normalizedSourceType === null,
                    fn ($query) => $query->whereNull('source_type'),
                    fn ($query) => $query->where(
                        'source_type',
                        $normalizedSourceType,
                    ),
                )
                ->when(
                    $sourceId === null,
                    fn ($query) => $query->whereNull('source_id'),
                    fn ($query) => $query->where(
                        'source_id',
                        $sourceId,
                    ),
                )
                ->exists();

            if ($duplicate) {
                return true;
            }

            DB::table('partner_change_eligibility_checks')->insert([
                'id' => (string) Str::uuid7(),
                'business_id' => $business->getKey(),
                'partner_change_case_id' => $caseId,
                'case_revision' => $expectedRevision,
                'check_key' => $key,
                'result' => $result->value,
                'detail' => $normalizedDetail,
                'source_type' => $normalizedSourceType,
                'source_id' => $sourceId,
                'checked_by_membership_id' => $membership->getKey(),
                'checked_at' => now(),
            ]);

            $this->occurrence->record(
                $user,
                $business,
                'partner_change.eligibility.recorded',
                'partner_change_case',
                $caseId,
                [
                    'check_key' => $key,
                    'result' => $result->value,
                    'case_revision' => $expectedRevision,
                ],
            );

            return true;
        });
    }

    public function recordRequirement(
        User $user,
        Business $business,
        string $caseId,
        int $expectedRevision,
        string $requirementType,
        string $requirementKey,
        PartnerChangeEligibilityStatus $status,
        ?string $detail = null,
        ?string $sourceType = null,
        ?string $sourceId = null,
    ): bool {
        $membership = $this->actor->membership(
            $user,
            $business,
            CapabilityCatalog::PARTNER_CHANGES_MANAGE,
        );

        if ($membership === null) {
            return false;
        }

        return DB::transaction(function () use (
            $user,
            $business,
            $caseId,
            $expectedRevision,
            $requirementType,
            $requirementKey,
            $status,
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
                PartnerChangeStatus::Effective,
                PartnerChangeStatus::Completed,
                PartnerChangeStatus::Rejected,
                PartnerChangeStatus::Withdrawn,
            ], true)) {
                throw new InvalidArgumentException(
                    'Terminal Partner Change cases cannot accept new requirements.',
                );
            }

            $type = trim($requirementType);
            $key = trim($requirementKey);

            if (! in_array($type, [
                'due_diligence',
                'contribution',
                'legal_document',
                'onboarding',
                'governance',
                'ownership',
                'other',
            ], true)) {
                throw new InvalidArgumentException(
                    'Requirement type is invalid.',
                );
            }

            if ($key === '' || mb_strlen($key) > 80) {
                throw new InvalidArgumentException(
                    'Requirement key is invalid.',
                );
            }

            DB::table('partner_change_requirements')->insert([
                'id' => (string) Str::uuid7(),
                'business_id' => $business->getKey(),
                'partner_change_case_id' => $caseId,
                'case_revision' => $expectedRevision,
                'requirement_type' => $type,
                'requirement_key' => $key,
                'status' => $status->value,
                'detail' => $this->nullableText($detail),
                'source_type' => $this->nullableText($sourceType),
                'source_id' => $sourceId,
                'recorded_by_membership_id' => $membership->getKey(),
                'recorded_at' => now(),
            ]);

            return true;
        });
    }

    public function transition(
        User $user,
        Business $business,
        string $caseId,
        int $expectedRevision,
        PartnerChangeStatus $target,
    ): ?PartnerChangeCase {
        if (! $this->actor->allows(
            $user,
            $business,
            CapabilityCatalog::PARTNER_CHANGES_MANAGE,
        )) {
            return null;
        }

        return DB::transaction(function () use (
            $user,
            $business,
            $caseId,
            $expectedRevision,
            $target,
        ): PartnerChangeCase {
            $case = $this->lockCase($user, $business, $caseId, $expectedRevision);
            $from = $case->status;

            if (! $this->stateMachine->allows($from, $target)) {
                throw new InvalidArgumentException(
                    "Partner Change transition {$from->value} -> {$target->value} is not allowed.",
                );
            }

            if ($target === PartnerChangeStatus::Eligible) {
                $this->assertEligibilityReady($case);
            }

            if ($target === PartnerChangeStatus::Rofr && ! $case->rofr_required) {
                throw new InvalidArgumentException(
                    'ROFR stage is not applicable to this Partner Change.',
                );
            }

            if ($target === PartnerChangeStatus::TermsReady) {
                $this->assertTermsReady($case);
            }

            return $this->applyTransition(
                $user,
                $business,
                $case,
                $target,
            );
        });
    }

    public function openRofrRound(
        User $user,
        Business $business,
        string $caseId,
        int $expectedRevision,
        string $termsSummary,
        DateTimeInterface $deadlineAt,
    ): ?string {
        $membership = $this->actor->membership(
            $user,
            $business,
            CapabilityCatalog::PARTNER_CHANGES_MANAGE,
        );

        if ($membership === null) {
            return null;
        }

        return DB::transaction(function () use (
            $user,
            $business,
            $caseId,
            $expectedRevision,
            $termsSummary,
            $deadlineAt,
            $membership,
        ): string {
            $case = $this->lockCase(
                $user,
                $business,
                $caseId,
                $expectedRevision,
            );

            if (
                $case->status !== PartnerChangeStatus::Rofr
                || ! $case->rofr_required
            ) {
                throw new InvalidArgumentException(
                    'ROFR round requires a Partner Change currently in ROFR.',
                );
            }

            if ($deadlineAt <= now()) {
                throw new InvalidArgumentException(
                    'ROFR deadline must be in the future.',
                );
            }

            $terms = trim($termsSummary);

            if ($terms === '') {
                throw new InvalidArgumentException('ROFR terms are required.');
            }

            $openExists = DB::table('partner_change_rofr_rounds')
                ->where('business_id', $business->getKey())
                ->where('partner_change_case_id', $caseId)
                ->where('status', 'open')
                ->exists();

            if ($openExists) {
                throw new InvalidArgumentException(
                    'Only one ROFR round may be open for a Partner Change.',
                );
            }

            $sequence = ((int) DB::table('partner_change_rofr_rounds')
                ->where('business_id', $business->getKey())
                ->where('partner_change_case_id', $caseId)
                ->max('sequence')) + 1;

            $id = (string) Str::uuid7();

            DB::table('partner_change_rofr_rounds')->insert([
                'id' => $id,
                'business_id' => $business->getKey(),
                'partner_change_case_id' => $caseId,
                'sequence' => $sequence,
                'terms_summary' => $terms,
                'opened_at' => now(),
                'deadline_at' => $deadlineAt,
                'status' => 'open',
                'created_by_membership_id' => $membership->getKey(),
                'created_at' => now(),
            ]);

            return $id;
        });
    }

    public function recordRofrResponse(
        User $user,
        Business $business,
        string $caseId,
        int $expectedRevision,
        string $roundId,
        string $eligiblePartnerId,
        RofrResponseStatus $response,
        ?string $note = null,
    ): bool {
        if (! $this->actor->allows(
            $user,
            $business,
            CapabilityCatalog::PARTNER_CHANGES_MANAGE,
        )) {
            return false;
        }

        return DB::transaction(function () use (
            $user,
            $business,
            $caseId,
            $expectedRevision,
            $roundId,
            $eligiblePartnerId,
            $response,
            $note,
        ): bool {
            $case = $this->lockCase(
                $user,
                $business,
                $caseId,
                $expectedRevision,
            );

            if ($case->status !== PartnerChangeStatus::Rofr) {
                throw new InvalidArgumentException(
                    'ROFR response requires a case currently in ROFR.',
                );
            }

            $round = DB::table('partner_change_rofr_rounds')
                ->where('business_id', $business->getKey())
                ->where('partner_change_case_id', $caseId)
                ->where('id', $roundId)
                ->where('status', 'open')
                ->lockForUpdate()
                ->first();

            $eligible = DB::table('partners')
                ->where('business_id', $business->getKey())
                ->where('id', $eligiblePartnerId)
                ->where('status', 'active')
                ->exists();

            if ($round === null || ! $eligible) {
                return false;
            }

            DB::table('partner_change_rofr_responses')->insert([
                'id' => (string) Str::uuid7(),
                'business_id' => $business->getKey(),
                'rofr_round_id' => $roundId,
                'eligible_partner_id' => $eligiblePartnerId,
                'response' => $response->value,
                'note' => $this->nullableText($note),
                'responded_at' => now(),
                'created_at' => now(),
            ]);

            return true;
        });
    }

    public function completeRofrRound(
        User $user,
        Business $business,
        string $caseId,
        int $expectedRevision,
        string $roundId,
        bool $waived = false,
    ): bool {
        if (! $this->actor->allows(
            $user,
            $business,
            CapabilityCatalog::PARTNER_CHANGES_MANAGE,
        )) {
            return false;
        }

        return DB::transaction(function () use (
            $user,
            $business,
            $caseId,
            $expectedRevision,
            $roundId,
            $waived,
        ): bool {
            $case = $this->lockCase(
                $user,
                $business,
                $caseId,
                $expectedRevision,
            );

            if ($case->status !== PartnerChangeStatus::Rofr) {
                throw new InvalidArgumentException(
                    'ROFR completion requires a case currently in ROFR.',
                );
            }

            $round = DB::table('partner_change_rofr_rounds')
                ->where('business_id', $business->getKey())
                ->where('partner_change_case_id', $caseId)
                ->where('id', $roundId)
                ->where('status', 'open')
                ->lockForUpdate()
                ->first();

            if ($round === null) {
                return false;
            }

            $responses = DB::table('partner_change_rofr_responses')
                ->where('business_id', $business->getKey())
                ->where('rofr_round_id', $roundId)
                ->get(['response']);

            if (! $waived) {
                if ($responses->isEmpty()) {
                    throw new InvalidArgumentException(
                        'ROFR cannot complete without recorded responses or an explicit waiver.',
                    );
                }

                if ($responses->contains(
                    fn (object $row): bool => (string) $row->response === RofrResponseStatus::Accept->value,
                )) {
                    throw new InvalidArgumentException(
                        'An accepted ROFR requires a new buyer/transaction case; the current transfer cannot proceed.',
                    );
                }

                if ($responses->contains(
                    fn (object $row): bool => (string) $row->response === RofrResponseStatus::Pending->value,
                )) {
                    throw new InvalidArgumentException(
                        'Pending ROFR responses must be resolved before completion.',
                    );
                }
            }

            DB::table('partner_change_rofr_rounds')
                ->where('id', $roundId)
                ->where('business_id', $business->getKey())
                ->update([
                    'status' => $waived ? 'waived' : 'completed',
                ]);

            return true;
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
            CapabilityCatalog::PARTNER_CHANGES_MANAGE,
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
        ): ?array {
            $case = $this->lockCase($user, $business, $caseId, $expectedRevision);

            if ($case->status !== PartnerChangeStatus::TermsReady) {
                throw new InvalidArgumentException(
                    'Only Terms Ready Partner Changes may enter Governance.',
                );
            }

            $this->assertTermsReady($case);
            $this->moveIncomingPartnerToAdmissionPending(
                $user,
                $business,
                $case,
            );
            $payload = $this->snapshotPayload($business, $case);
            $packageHash = $this->contentHash($payload);
            $capability = new Capability(CapabilityCatalog::RECORDS_MANAGE);

            $family = $this->createFamily->execute(
                $user,
                $business,
                $capability,
                new RecordScope(
                    'partner_change',
                    'partner_change_case',
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
                    'Partner Change %s revision %d.',
                    $case->case_number,
                    $expectedRevision,
                ),
                $case->effective_from,
            );

            if ($recordVersion === null) {
                return null;
            }

            DB::table('partner_change_record_versions')->insert([
                'id' => (string) Str::uuid7(),
                'business_id' => $business->getKey(),
                'partner_change_case_id' => $case->getKey(),
                'formal_record_version_id' => $recordVersion->getKey(),
                'source_case_revision' => $expectedRevision,
                'transaction_type' => $case->transaction_type->value,
                'source_ownership_register_version_id' => $case->source_ownership_register_version_id,
                'seller_partner_id' => $case->seller_partner_id,
                'buyer_partner_id' => $case->buyer_partner_id,
                'source_share_class_id' => $case->source_share_class_id,
                'shares' => $case->shares,
                'currency' => $case->currency,
                'consideration_minor_units' => $case->consideration_minor_units,
                'valuation_method' => $case->valuation_method,
                'rights_impact_summary' => $case->rights_impact_summary,
                'governance_decision_type' => $case->governance_decision_type,
                'rofr_required' => $case->rofr_required,
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
            DB::table('partner_change_governance_submissions')->insert([
                'id' => (string) Str::uuid7(),
                'business_id' => $business->getKey(),
                'partner_change_case_id' => $case->getKey(),
                'formal_record_version_id' => $recordVersion->getKey(),
                'proposal_id' => $proposal->getKey(),
                'proposal_version_id' => $proposalVersion->getKey(),
                'decision_type' => $case->governance_decision_type,
                'source_case_revision' => $expectedRevision,
                'package_hash' => $packageHash,
                'decision_id' => null,
                'effective_register_version_id' => null,
                'created_at' => now(),
                'effected_at' => null,
            ]);

            $this->applyTransition(
                $user,
                $business,
                $case,
                PartnerChangeStatus::UnderGovernance,
            );

            $this->occurrence->record(
                $user,
                $business,
                'partner_change.governance.submitted',
                'partner_change_case',
                $caseId,
                [
                    'proposal_version_id' => (string) $proposalVersion->getKey(),
                    'formal_record_version_id' => (string) $recordVersion->getKey(),
                    'source_case_revision' => $expectedRevision,
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
                'Partner Change content-review target is invalid.',
            );
        }

        if (! $this->actor->allows(
            $user,
            $business,
            CapabilityCatalog::PARTNER_CHANGES_MANAGE,
        )) {
            return false;
        }

        $bound = DB::table('partner_change_record_versions')
            ->where('business_id', $business->getKey())
            ->where('partner_change_case_id', $caseId)
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
    ): ?PartnerChangeCase {
        if (! $this->actor->allows(
            $user,
            $business,
            CapabilityCatalog::PARTNER_CHANGES_MANAGE,
        )) {
            return null;
        }

        return DB::transaction(function () use (
            $user,
            $business,
            $caseId,
            $expectedRevision,
        ): ?PartnerChangeCase {
            $case = $this->lockCase($user, $business, $caseId, $expectedRevision);

            if ($case->status !== PartnerChangeStatus::UnderGovernance) {
                throw new InvalidArgumentException(
                    'Partner Change is not awaiting Governance.',
                );
            }

            $submission = DB::table('partner_change_governance_submissions')
                ->where('business_id', $business->getKey())
                ->where('partner_change_case_id', $caseId)
                ->where('source_case_revision', $expectedRevision - 1)
                ->lockForUpdate()
                ->first();

            if ($submission === null) {
                return null;
            }

            $decisions = DB::table('decisions')
                ->where('business_id', $business->getKey())
                ->where('proposal_version_id', $submission->proposal_version_id)
                ->where('decision_type', $submission->decision_type)
                ->where('status', 'decided')
                ->get();

            if ($decisions->count() !== 1) {
                throw new MissingGovernanceDecision;
            }

            $decision = $decisions->first();

            DB::table('partner_change_governance_submissions')
                ->where('id', $submission->id)
                ->where('business_id', $business->getKey())
                ->update(['decision_id' => $decision->id]);

            $target = (string) $decision->outcome === 'approved'
                ? PartnerChangeStatus::Approved
                : PartnerChangeStatus::Rejected;

            if ($target === PartnerChangeStatus::Rejected) {
                $this->restoreIncomingPartnerAfterRejection(
                    $user,
                    $business,
                    $case,
                );
            }

            return $this->applyTransition($user, $business, $case, $target);
        });
    }

    public function prepareForEffect(
        User $user,
        Business $business,
        string $caseId,
        int $expectedRevision,
    ): ?PartnerChangeCase {
        if (! $this->actor->allows(
            $user,
            $business,
            CapabilityCatalog::PARTNER_CHANGES_MANAGE,
        )) {
            return null;
        }

        return DB::transaction(function () use (
            $user,
            $business,
            $caseId,
            $expectedRevision,
        ): ?PartnerChangeCase {
            $case = $this->lockCase($user, $business, $caseId, $expectedRevision);

            if ($case->status !== PartnerChangeStatus::Approved) {
                throw new InvalidArgumentException(
                    'Only an Approved Partner Change may prepare for Effect.',
                );
            }

            $this->assertEffectRequirements($business, $case);

            $submission = DB::table('partner_change_governance_submissions')
                ->where('business_id', $business->getKey())
                ->where('partner_change_case_id', $caseId)
                ->whereNotNull('decision_id')
                ->lockForUpdate()
                ->first();

            if ($submission === null) {
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
                PartnerChangeStatus::ReadyForEffect,
            );
        });
    }

    public function effect(
        User $user,
        Business $business,
        string $caseId,
        int $expectedRevision,
    ): ?PartnerChangeCase {
        if (! $this->actor->allows(
            $user,
            $business,
            CapabilityCatalog::PARTNER_CHANGES_MANAGE,
        )) {
            return null;
        }

        return DB::transaction(function () use (
            $user,
            $business,
            $caseId,
            $expectedRevision,
        ): ?PartnerChangeCase {
            $case = $this->lockCase($user, $business, $caseId, $expectedRevision);

            if ($case->status !== PartnerChangeStatus::ReadyForEffect) {
                throw new InvalidArgumentException(
                    'Partner Change is not Ready for Effect.',
                );
            }

            if (
                $case->effective_from !== null
                && $case->effective_from->isFuture()
            ) {
                throw new InvalidArgumentException(
                    'Partner Change cannot become Effective before Effective From.',
                );
            }

            $this->assertEffectRequirements($business, $case);

            $submission = DB::table('partner_change_governance_submissions')
                ->where('business_id', $business->getKey())
                ->where('partner_change_case_id', $caseId)
                ->whereNotNull('decision_id')
                ->lockForUpdate()
                ->first();

            if ($submission === null) {
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

            $type = $case->transaction_type;

            if ($type === PartnerChangeTransactionType::TransferExisting) {
                $registerId = $this->ownershipTransfer->effectExistingShareTransfer(
                    $user,
                    $business,
                    $caseId,
                    (string) $case->source_ownership_register_version_id,
                    (string) $case->seller_partner_id,
                    (string) $case->buyer_partner_id,
                    (string) $case->source_share_class_id,
                    new ShareQuantity((string) $case->shares),
                    (string) $submission->proposal_version_id,
                    (string) $submission->decision_id,
                    CarbonImmutable::parse((string) $case->effective_from),
                );

                if ($registerId === null) {
                    return null;
                }
            } elseif ($type === PartnerChangeTransactionType::IssueNew) {
                $registerId = $this->ownershipTransfer->effectNewShareIssue(
                    $user,
                    $business,
                    $caseId,
                    (string) $case->source_ownership_register_version_id,
                    (string) $case->buyer_partner_id,
                    (string) $case->source_share_class_id,
                    new ShareQuantity((string) $case->shares),
                    (string) $submission->proposal_version_id,
                    (string) $submission->decision_id,
                    CarbonImmutable::parse((string) $case->effective_from),
                );

                if ($registerId === null) {
                    return null;
                }
            }

            $buyer = DB::table('partners')
                ->where('business_id', $business->getKey())
                ->where('id', $case->buyer_partner_id)
                ->lockForUpdate()
                ->first(['status']);

            if ($buyer === null) {
                return null;
            }

            if ((string) $buyer->status === 'admission_pending') {
                if (! $this->partnerLifecycle->transition(
                    $user,
                    $business,
                    (string) $case->buyer_partner_id,
                    'admission_pending',
                    'active',
                    'partner_change_effective',
                    'partner_change_case',
                    $caseId,
                )) {
                    throw new RuntimeException(
                        'Partner admission lifecycle could not become Active.',
                    );
                }
            }

            $effective = $this->applyTransition(
                $user,
                $business,
                $case,
                PartnerChangeStatus::Effective,
            );

            return $this->applyTransition(
                $user,
                $business,
                $effective,
                PartnerChangeStatus::Completed,
            );
        });
    }

    private function assertEligibilityReady(PartnerChangeCase $case): void
    {
        $required = $case->transaction_type === PartnerChangeTransactionType::Admission
            ? ['buyer_eligible']
            : [
                'vesting_and_restrictions',
                'obligations_clear',
                'pledge_lien_clear',
                'agreement_conflict_clear',
                'buyer_eligible',
            ];

        foreach ($required as $key) {
            $status = $this->latestEligibilityStatus($case, $key);

            if (! in_array($status, [
                PartnerChangeEligibilityStatus::Met->value,
                PartnerChangeEligibilityStatus::NotApplicable->value,
            ], true)) {
                throw new InvalidArgumentException(
                    "Eligibility requirement {$key} is not satisfied.",
                );
            }
        }

        if ($case->transaction_type === PartnerChangeTransactionType::TransferExisting) {
            $class = DB::table('ownership_register_share_classes')
                ->where('business_id', $case->business_id)
                ->where(
                    'ownership_register_version_id',
                    $case->source_ownership_register_version_id,
                )
                ->where('id', $case->source_share_class_id)
                ->first(['transfer_allowed']);

            if ($class === null || ! (bool) $class->transfer_allowed) {
                throw new InvalidArgumentException(
                    'The captured Share Class does not permit transfer.',
                );
            }

            $capacity = DB::selectOne(
                <<<'SQL'
SELECT
    CAST(? AS numeric) <= shares_issued AS issued_ok,
    CAST(? AS numeric) <= shares_vested AS vested_ok
FROM ownership_register_positions
WHERE business_id = ?
  AND ownership_register_version_id = ?
  AND partner_id = ?
  AND share_class_id = ?
SQL,
                [
                    $case->shares,
                    $case->shares,
                    $case->business_id,
                    $case->source_ownership_register_version_id,
                    $case->seller_partner_id,
                    $case->source_share_class_id,
                ],
            );

            if (
                $capacity === null
                || ! (bool) $capacity->issued_ok
                || ! (bool) $capacity->vested_ok
            ) {
                throw new InvalidArgumentException(
                    'Seller no longer has enough issued and vested Shares.',
                );
            }
        }
    }

    private function assertTermsReady(PartnerChangeCase $case): void
    {
        if ($case->effective_from === null) {
            throw new InvalidArgumentException(
                'Partner Change Effective From is required before Terms Ready.',
            );
        }

        $buyerStatus = DB::table('partners')
            ->where('business_id', $case->business_id)
            ->where('id', $case->buyer_partner_id)
            ->value('status');

        if ($buyerStatus === null || $buyerStatus === 'former') {
            throw new InvalidArgumentException(
                'Buyer/Incoming Partner is not eligible to proceed.',
            );
        }

        if ((string) $buyerStatus !== 'active') {
            if (! in_array((string) $buyerStatus, [
                'prospective',
                'due_diligence',
                'admission_pending',
            ], true)) {
                throw new InvalidArgumentException(
                    'Incoming Partner lifecycle is not ready for admission.',
                );
            }

            $ddCompleted = DB::table('partner_due_diligence_cases')
                ->where('business_id', $case->business_id)
                ->where('partner_id', $case->buyer_partner_id)
                ->where('status', 'completed')
                ->exists();

            if (! $ddCompleted) {
                throw new InvalidArgumentException(
                    'Incoming Partner requires completed Due Diligence before Governance submission.',
                );
            }

            $contributionTerms = $this->latestRequirementStatus(
                $case,
                'contribution_terms_resolved',
            );

            if (! in_array($contributionTerms, [
                PartnerChangeEligibilityStatus::Met->value,
                PartnerChangeEligibilityStatus::NotApplicable->value,
            ], true)) {
                throw new InvalidArgumentException(
                    'Incoming Partner contribution terms must be resolved or explicitly marked not applicable.',
                );
            }
        }

        if ($case->transaction_type->changesOwnership()) {
            if ($this->nullableText($case->valuation_method) === null) {
                throw new InvalidArgumentException(
                    'Ownership-changing Partner Changes require a valuation method.',
                );
            }

            if ($this->nullableText($case->rights_impact_summary) === null) {
                throw new InvalidArgumentException(
                    'Ownership-changing Partner Changes require an explicit rights impact summary.',
                );
            }
        }

        if ($case->transaction_type === PartnerChangeTransactionType::IssueNew) {
            $ownershipScenario = $this->latestRequirementStatus(
                $case,
                'ownership_scenario_frozen',
            );

            if ($ownershipScenario !== PartnerChangeEligibilityStatus::Met->value) {
                throw new InvalidArgumentException(
                    'New-share issue requires a captured Frozen Ownership Scenario before Governance submission.',
                );
            }
        }

        if ($case->rofr_required) {
            $round = DB::table('partner_change_rofr_rounds')
                ->where('business_id', $case->business_id)
                ->where('partner_change_case_id', $case->getKey())
                ->orderByDesc('sequence')
                ->first(['status']);

            if (
                $round === null
                || ! in_array((string) $round->status, ['completed', 'waived'], true)
            ) {
                throw new InvalidArgumentException(
                    'Required ROFR must be completed or explicitly waived.',
                );
            }
        }

        if ($case->transaction_type !== PartnerChangeTransactionType::Admission) {
            $sourceStillCurrent = DB::table('ownership_register_versions')
                ->where('business_id', $case->business_id)
                ->where('id', $case->source_ownership_register_version_id)
                ->where('status', 'effective')
                ->exists();

            if (! $sourceStillCurrent) {
                throw new InvalidArgumentException(
                    'Captured Ownership Register is no longer current; create a new Partner Change case.',
                );
            }
        }
    }

    private function moveIncomingPartnerToAdmissionPending(
        User $user,
        Business $business,
        PartnerChangeCase $case,
    ): void {
        $partner = DB::table('partners')
            ->where('business_id', $business->getKey())
            ->where('id', $case->buyer_partner_id)
            ->lockForUpdate()
            ->first(['status']);

        if ($partner === null) {
            throw new InvalidArgumentException(
                'Incoming Partner no longer exists in the current Business.',
            );
        }

        $status = (string) $partner->status;

        if ($status === 'active') {
            return;
        }

        if ($status === 'admission_pending') {
            $latest = DB::table('partner_lifecycle_transitions')
                ->where('business_id', $business->getKey())
                ->where('partner_id', $case->buyer_partner_id)
                ->where('to_status', 'admission_pending')
                ->orderByDesc('occurred_at')
                ->orderByDesc('id')
                ->first(['source_type', 'source_id']);

            if (
                $latest === null
                || (string) $latest->source_type !== 'partner_change_case'
                || (string) $latest->source_id !== (string) $case->getKey()
            ) {
                throw new InvalidArgumentException(
                    'Incoming Partner is already pending admission under another lifecycle source.',
                );
            }

            return;
        }

        if (! in_array($status, ['prospective', 'due_diligence'], true)) {
            throw new InvalidArgumentException(
                'Incoming Partner lifecycle cannot enter Admission Pending.',
            );
        }

        if (! $this->partnerLifecycle->transition(
            $user,
            $business,
            (string) $case->buyer_partner_id,
            $status,
            'admission_pending',
            'partner_change_governance_submitted',
            'partner_change_case',
            (string) $case->getKey(),
        )) {
            throw new RuntimeException(
                'Incoming Partner could not enter Admission Pending.',
            );
        }
    }

    private function restoreIncomingPartnerAfterRejection(
        User $user,
        Business $business,
        PartnerChangeCase $case,
    ): void {
        $partner = DB::table('partners')
            ->where('business_id', $business->getKey())
            ->where('id', $case->buyer_partner_id)
            ->lockForUpdate()
            ->first(['status']);

        if ($partner === null || (string) $partner->status !== 'admission_pending') {
            return;
        }

        $latest = DB::table('partner_lifecycle_transitions')
            ->where('business_id', $business->getKey())
            ->where('partner_id', $case->buyer_partner_id)
            ->where('to_status', 'admission_pending')
            ->orderByDesc('occurred_at')
            ->orderByDesc('id')
            ->first(['source_type', 'source_id']);

        if (
            $latest === null
            || (string) $latest->source_type !== 'partner_change_case'
            || (string) $latest->source_id !== (string) $case->getKey()
        ) {
            return;
        }

        if (! $this->partnerLifecycle->transition(
            $user,
            $business,
            (string) $case->buyer_partner_id,
            'admission_pending',
            'prospective',
            'partner_change_governance_rejected',
            'partner_change_case',
            (string) $case->getKey(),
        )) {
            throw new RuntimeException(
                'Rejected admission could not restore the prospective Partner lifecycle.',
            );
        }
    }

    private function assertEffectRequirements(
        Business $business,
        PartnerChangeCase $case,
    ): void {
        if ($case->effective_from === null) {
            throw new InvalidArgumentException(
                'Partner Change Effective From is required before effectivity.',
            );
        }

        $buyerStatus = DB::table('partners')
            ->where('business_id', $business->getKey())
            ->where('id', $case->buyer_partner_id)
            ->value('status');

        if ($buyerStatus === null || $buyerStatus === 'former') {
            throw new InvalidArgumentException(
                'Buyer/Incoming Partner is not eligible for effectivity.',
            );
        }

        $required = ['legal_documentation_complete'];

        if ((string) $buyerStatus !== 'active') {
            $ddCompleted = DB::table('partner_due_diligence_cases')
                ->where('business_id', $business->getKey())
                ->where('partner_id', $case->buyer_partner_id)
                ->where('status', 'completed')
                ->exists();

            if (! $ddCompleted) {
                throw new InvalidArgumentException(
                    'Incoming Partner requires completed Due Diligence before effectivity.',
                );
            }

            $required[] = 'onboarding_ready';
        }

        if ($case->transaction_type === PartnerChangeTransactionType::IssueNew) {
            $required[] = 'ownership_scenario_frozen';
        }

        foreach ($required as $key) {
            $status = $this->latestRequirementStatus($case, $key);

            if (! in_array($status, [
                PartnerChangeEligibilityStatus::Met->value,
                PartnerChangeEligibilityStatus::NotApplicable->value,
            ], true)) {
                throw new InvalidArgumentException(
                    "Effectivity requirement {$key} is not satisfied.",
                );
            }
        }
    }

    /** @return array<string,mixed> */
    private function snapshotPayload(
        Business $business,
        PartnerChangeCase $case,
    ): array {
        $caseId = (string) $case->getKey();

        return [
            'business_id' => (string) $business->getKey(),
            'case_id' => $caseId,
            'case_number' => (string) $case->case_number,
            'case_revision' => (int) $case->revision,
            'transaction_type' => $case->transaction_type->value,
            'source_ownership_register_version_id' => $case->source_ownership_register_version_id,
            'seller_partner_id' => $case->seller_partner_id,
            'buyer_partner_id' => (string) $case->buyer_partner_id,
            'source_share_class_id' => $case->source_share_class_id,
            'shares' => $case->shares === null ? null : (string) $case->shares,
            'currency' => $case->currency,
            'consideration_minor_units' => $case->consideration_minor_units,
            'valuation_method' => $case->valuation_method,
            'rights_impact_summary' => $case->rights_impact_summary,
            'governance_decision_type' => (string) $case->governance_decision_type,
            'rofr_required' => (bool) $case->rofr_required,
            'effective_from' => $case->effective_from?->toAtomString(),
            'eligibility' => DB::table('partner_change_eligibility_checks')
                ->where('business_id', $business->getKey())
                ->where('partner_change_case_id', $caseId)
                ->orderBy('checked_at')
                ->orderBy('id')
                ->get([
                    'case_revision',
                    'check_key',
                    'result',
                    'source_type',
                    'source_id',
                ])
                ->map(static fn (object $row): array => (array) $row)
                ->all(),
            'requirements' => DB::table('partner_change_requirements')
                ->where('business_id', $business->getKey())
                ->where('partner_change_case_id', $caseId)
                ->orderBy('recorded_at')
                ->orderBy('id')
                ->get([
                    'case_revision',
                    'requirement_type',
                    'requirement_key',
                    'status',
                    'source_type',
                    'source_id',
                ])
                ->map(static fn (object $row): array => (array) $row)
                ->all(),
            'rofr_rounds' => DB::table('partner_change_rofr_rounds')
                ->where('business_id', $business->getKey())
                ->where('partner_change_case_id', $caseId)
                ->orderBy('sequence')
                ->get([
                    'id',
                    'sequence',
                    'opened_at',
                    'deadline_at',
                    'status',
                ])
                ->map(static fn (object $row): array => (array) $row)
                ->all(),
        ];
    }

    private function latestEligibilityStatus(
        PartnerChangeCase $case,
        string $key,
    ): ?string {
        return DB::table('partner_change_eligibility_checks')
            ->where('business_id', $case->business_id)
            ->where('partner_change_case_id', $case->getKey())
            ->where('check_key', $key)
            ->orderByDesc('checked_at')
            ->orderByDesc('id')
            ->value('result');
    }

    private function latestRequirementStatus(
        PartnerChangeCase $case,
        string $key,
    ): ?string {
        return DB::table('partner_change_requirements')
            ->where('business_id', $case->business_id)
            ->where('partner_change_case_id', $case->getKey())
            ->where('requirement_key', $key)
            ->orderByDesc('recorded_at')
            ->orderByDesc('id')
            ->value('status');
    }

    private function lockCase(
        User $user,
        Business $business,
        string $caseId,
        int $expectedRevision,
    ): PartnerChangeCase {
        $case = PartnerChangeCase::query()
            ->where('business_id', $business->getKey())
            ->whereKey($caseId)
            ->lockForUpdate()
            ->first();

        if ($case === null) {
            throw new InvalidArgumentException(
                'Partner Change Case was not found in the current Business.',
            );
        }

        $decision = $this->authorize->decide(
            $user,
            $business,
            $business,
            new Capability(CapabilityCatalog::PARTNER_CHANGES_MANAGE),
            PartnerChangeCase::class,
            (string) $case->getKey(),
        );

        if (! $decision->allowed) {
            throw new InvalidArgumentException(
                'Partner Change Case is not available to this Membership.',
            );
        }

        if ((int) $case->revision !== $expectedRevision) {
            throw new StaleRevision($expectedRevision, (int) $case->revision);
        }

        return $case;
    }

    private function applyTransition(
        User $user,
        Business $business,
        PartnerChangeCase $case,
        PartnerChangeStatus $target,
    ): PartnerChangeCase {
        $from = $case->status;

        if (! $this->stateMachine->allows($from, $target)) {
            throw new InvalidArgumentException(
                "Partner Change transition {$from->value} -> {$target->value} is not allowed.",
            );
        }

        $membership = $this->actor->membership(
            $user,
            $business,
            CapabilityCatalog::PARTNER_CHANGES_MANAGE,
        );

        if ($membership === null) {
            throw new RuntimeException(
                'Partner Change transition lost its authorized Membership context.',
            );
        }

        $case->status = $target;
        $case->revision = ((int) $case->revision) + 1;
        $case->save();

        $sequence = ((int) DB::table('partner_change_case_transitions')
            ->where('business_id', $business->getKey())
            ->where('partner_change_case_id', $case->getKey())
            ->max('sequence')) + 1;

        DB::table('partner_change_case_transitions')->insert([
            'id' => (string) Str::uuid7(),
            'business_id' => $business->getKey(),
            'partner_change_case_id' => $case->getKey(),
            'sequence' => $sequence,
            'from_status' => $from->value,
            'to_status' => $target->value,
            'actor_membership_id' => $membership->getKey(),
            'occurred_at' => now(),
            'created_at' => now(),
        ]);

        $this->occurrence->record(
            $user,
            $business,
            'partner_change.case.transitioned',
            'partner_change_case',
            (string) $case->getKey(),
            [
                'from_status' => $from->value,
                'to_status' => $target->value,
                'revision' => (int) $case->revision,
            ],
        );

        return $case->fresh();
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

    private function nullableText(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $value = trim($value);

        return $value === '' ? null : $value;
    }

    private function currency(?string $value): ?string
    {
        if ($value === null || trim($value) === '') {
            return null;
        }

        $currency = strtoupper(trim($value));

        if (preg_match('/\A[A-Z]{3}\z/', $currency) !== 1) {
            throw new InvalidArgumentException(
                'Currency must be an ISO-style three-letter code.',
            );
        }

        return $currency;
    }
}
