<?php

declare(strict_types=1);

namespace App\Application\Closure;

use App\Application\Governance\MakeGovernedRecordEffective;
use App\Application\Governance\PrepareGovernedRecordForEffect;
use App\Application\Partnership\PartnershipActorContext;
use App\Application\Records\CreateDraftRecordVersion;
use App\Application\Records\CreateFormalRecordFamily;
use App\Application\Records\CreateProposal;
use App\Application\Records\FreezeProposalVersion;
use App\Application\Records\SubmitRecordVersionForReview;
use App\Application\Records\TransitionFormalRecordVersion;
use App\Domain\Access\CapabilityCatalog;
use App\Domain\Access\ValueObjects\Capability;
use App\Domain\Businesses\Enums\WorkspaceStatus;
use App\Domain\Closure\Enums\ClosureCaseStatus;
use App\Domain\Closure\Enums\ClosureClaimStatus;
use App\Domain\Closure\Services\ClosureCaseStateMachine;
use App\Domain\Governance\Exceptions\MissingGovernanceDecision;
use App\Domain\Records\Enums\FormalRecordState;
use App\Domain\Records\Exceptions\StaleRevision;
use App\Domain\Records\ValueObjects\RecordScope;
use App\Infrastructure\Persistence\Eloquent\Businesses\Business;
use App\Infrastructure\Persistence\Eloquent\Closure\ClosureCase;
use App\Infrastructure\Persistence\Eloquent\Identity\User;
use DateTimeInterface;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;

final class ClosureWorkflow
{
    public function __construct(
        private readonly PartnershipActorContext $actor,
        private readonly RecordClosureOccurrence $occurrence,
        private readonly ClosureCaseStateMachine $stateMachine,
        private readonly CreateFormalRecordFamily $createFamily,
        private readonly CreateDraftRecordVersion $createDraft,
        private readonly SubmitRecordVersionForReview $submitForReview,
        private readonly TransitionFormalRecordVersion $transitionRecord,
        private readonly CreateProposal $createProposal,
        private readonly FreezeProposalVersion $freezeProposal,
        private readonly PrepareGovernedRecordForEffect $prepareEffect,
        private readonly MakeGovernedRecordEffective $makeEffective,
    ) {}

    public function createCase(
        User $user,
        Business $business,
        string $trigger,
        string $jurisdictionReference,
        string $governanceDecisionType,
        ?string $triggerDetail = null,
        ?string $legalEntityReference = null,
        ?DateTimeInterface $intendedLegalClosureAt = null,
    ): ?ClosureCase {
        $membership = $this->actor->membership(
            $user,
            $business,
            CapabilityCatalog::CLOSURE_MANAGE,
        );

        if ($membership === null) {
            return null;
        }

        $normalizedTrigger = $this->requiredText($trigger, 'Closure trigger');
        $normalizedJurisdiction = $this->requiredText(
            $jurisdictionReference,
            'Jurisdiction reference',
        );
        $normalizedDecisionType = $this->requiredText(
            $governanceDecisionType,
            'Governance decision type',
        );

        return DB::transaction(function () use (
            $user,
            $business,
            $membership,
            $normalizedTrigger,
            $normalizedJurisdiction,
            $normalizedDecisionType,
            $triggerDetail,
            $legalEntityReference,
            $intendedLegalClosureAt,
        ): ClosureCase {
            $lockedBusiness = Business::query()
                ->whereKey($business->getKey())
                ->lockForUpdate()
                ->firstOrFail();

            if ($lockedBusiness->workspace_status === WorkspaceStatus::Closed) {
                throw new InvalidArgumentException(
                    'A Closed Business cannot open another Closure Case.',
                );
            }

            $hasOpenCase = ClosureCase::query()
                ->where('business_id', $business->getKey())
                ->whereNotIn('status', [
                    ClosureCaseStatus::Completed->value,
                    ClosureCaseStatus::Rejected->value,
                    ClosureCaseStatus::Withdrawn->value,
                    ClosureCaseStatus::Cancelled->value,
                ])
                ->exists();

            if ($hasOpenCase) {
                throw new InvalidArgumentException(
                    'This Business already has an open Closure Case.',
                );
            }

            $case = ClosureCase::query()->create([
                'business_id' => $business->getKey(),
                'case_number' => 'CLS-'.strtoupper(
                    substr(str_replace('-', '', (string) Str::uuid7()), 0, 16),
                ),
                'trigger' => $normalizedTrigger,
                'trigger_detail' => $this->nullableText($triggerDetail),
                'jurisdiction_reference' => $normalizedJurisdiction,
                'legal_entity_reference' => $this->nullableText(
                    $legalEntityReference,
                ),
                'governance_decision_type' => $normalizedDecisionType,
                'intended_legal_closure_at' => $intendedLegalClosureAt,
                'residual_distribution_status' => 'pending',
                'status' => ClosureCaseStatus::Draft,
                'created_by_membership_id' => $membership->getKey(),
                'revision' => 1,
            ]);

            $this->recordTransition(
                $business,
                $case,
                null,
                ClosureCaseStatus::Draft,
                (string) $membership->getKey(),
            );

            $this->occurrence->record(
                $user,
                $business,
                'closure.case.opened',
                'closure_case',
                (string) $case->getKey(),
                [
                    'case_number' => $case->case_number,
                    'revision' => 1,
                    'workspace_status' => $lockedBusiness->workspace_status->value,
                ],
            );

            return $case;
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
        ?string $externalSourceReference = null,
    ): bool {
        $membership = $this->actor->membership(
            $user,
            $business,
            CapabilityCatalog::CLOSURE_MANAGE,
        );

        if ($membership === null) {
            return false;
        }

        if (! in_array($status, [
            'pending',
            'met',
            'blocked',
            'not_applicable',
        ], true)) {
            throw new InvalidArgumentException(
                'Closure requirement status is invalid.',
            );
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
            $externalSourceReference,
            $membership,
        ): bool {
            $case = $this->lockCase(
                $user,
                $business,
                $caseId,
                $expectedRevision,
            );

            if (! in_array($case->status, [
                ClosureCaseStatus::Draft,
                ClosureCaseStatus::Approved,
                ClosureCaseStatus::WindDownActive,
                ClosureCaseStatus::ResidualReady,
            ], true)) {
                throw new InvalidArgumentException(
                    'Closure requirements cannot change in this lifecycle state.',
                );
            }

            $this->validateSource(
                $user,
                $business,
                $sourceType,
                $sourceId,
            );

            DB::table('closure_requirements')->insert([
                'id' => (string) Str::uuid7(),
                'business_id' => $business->getKey(),
                'closure_case_id' => $case->getKey(),
                'case_revision' => $expectedRevision,
                'requirement_type' => $this->requiredText(
                    $requirementType,
                    'Requirement type',
                ),
                'requirement_key' => $this->requiredText(
                    $requirementKey,
                    'Requirement key',
                ),
                'status' => $status,
                'detail' => $this->nullableText($detail),
                'source_type' => $sourceType,
                'source_id' => $sourceId,
                'external_source_reference' => $this->nullableText(
                    $externalSourceReference,
                ),
                'recorded_by_membership_id' => $membership->getKey(),
                'recorded_at' => now(),
            ]);

            $this->occurrence->record(
                $user,
                $business,
                'closure.requirement.recorded',
                'closure_case',
                $caseId,
                [
                    'case_revision' => $expectedRevision,
                    'requirement_key' => $requirementKey,
                    'status' => $status,
                ],
            );

            return true;
        });
    }

    public function createClaim(
        User $user,
        Business $business,
        string $caseId,
        int $expectedCaseRevision,
        string $claimReference,
        string $claimType,
        string $claimantReference,
        bool $required,
        ?int $amountMinorUnits = null,
        ?string $currency = null,
        ?string $description = null,
        ?string $legalPriorityReference = null,
        ?string $sourceType = null,
        ?string $sourceId = null,
        ?string $externalSourceReference = null,
    ): ?string {
        $membership = $this->actor->membership(
            $user,
            $business,
            CapabilityCatalog::CLOSURE_MANAGE,
        );

        if ($membership === null) {
            return null;
        }

        if ($amountMinorUnits !== null && $amountMinorUnits < 0) {
            throw new InvalidArgumentException(
                'Closure Claim amount cannot be negative.',
            );
        }

        $normalizedCurrency = $this->nullableCurrency($currency);

        return DB::transaction(function () use (
            $user,
            $business,
            $caseId,
            $expectedCaseRevision,
            $claimReference,
            $claimType,
            $claimantReference,
            $required,
            $amountMinorUnits,
            $normalizedCurrency,
            $description,
            $legalPriorityReference,
            $sourceType,
            $sourceId,
            $externalSourceReference,
            $membership,
        ): string {
            $case = $this->lockCase(
                $user,
                $business,
                $caseId,
                $expectedCaseRevision,
            );

            if (! in_array($case->status, [
                ClosureCaseStatus::Draft,
                ClosureCaseStatus::Approved,
                ClosureCaseStatus::WindDownActive,
            ], true)) {
                throw new InvalidArgumentException(
                    'Closure Claims may be identified only before residual distribution.',
                );
            }

            $this->validateSource(
                $user,
                $business,
                $sourceType,
                $sourceId,
            );

            $claimId = (string) Str::uuid7();

            DB::table('closure_claims')->insert([
                'id' => $claimId,
                'business_id' => $business->getKey(),
                'closure_case_id' => $case->getKey(),
                'claim_reference' => $this->requiredText(
                    $claimReference,
                    'Claim reference',
                ),
                'claim_type' => $this->requiredText(
                    $claimType,
                    'Claim type',
                ),
                'claimant_reference' => $this->requiredText(
                    $claimantReference,
                    'Claimant reference',
                ),
                'description' => $this->nullableText($description),
                'amount_minor_units' => $amountMinorUnits,
                'currency' => $normalizedCurrency,
                'required' => $required,
                'legal_priority_reference' => $this->nullableText(
                    $legalPriorityReference,
                ),
                'source_type' => $sourceType,
                'source_id' => $sourceId,
                'external_source_reference' => $this->nullableText(
                    $externalSourceReference,
                ),
                'status' => ClosureClaimStatus::Identified->value,
                'created_by_membership_id' => $membership->getKey(),
                'revision' => 1,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            DB::table('closure_claim_transitions')->insert([
                'id' => (string) Str::uuid7(),
                'business_id' => $business->getKey(),
                'closure_case_id' => $case->getKey(),
                'closure_claim_id' => $claimId,
                'claim_revision' => 1,
                'from_status' => null,
                'to_status' => ClosureClaimStatus::Identified->value,
                'note' => 'Claim identified for wind-down inventory.',
                'actor_membership_id' => $membership->getKey(),
                'occurred_at' => now(),
            ]);

            $this->occurrence->record(
                $user,
                $business,
                'closure.claim.identified',
                'closure_case',
                $caseId,
                [
                    'claim_id' => $claimId,
                    'claim_reference' => $claimReference,
                    'required' => $required,
                ],
            );

            return $claimId;
        });
    }

    public function transitionClaim(
        User $user,
        Business $business,
        string $caseId,
        int $expectedCaseRevision,
        string $claimId,
        int $expectedClaimRevision,
        ClosureClaimStatus $target,
        ?string $note = null,
    ): bool {
        $membership = $this->actor->membership(
            $user,
            $business,
            CapabilityCatalog::CLOSURE_MANAGE,
        );

        if ($membership === null) {
            return false;
        }

        return DB::transaction(function () use (
            $user,
            $business,
            $caseId,
            $expectedCaseRevision,
            $claimId,
            $expectedClaimRevision,
            $target,
            $note,
            $membership,
        ): bool {
            $case = $this->lockCase(
                $user,
                $business,
                $caseId,
                $expectedCaseRevision,
            );

            if (! in_array($case->status, [
                ClosureCaseStatus::Draft,
                ClosureCaseStatus::Approved,
                ClosureCaseStatus::WindDownActive,
            ], true)) {
                throw new InvalidArgumentException(
                    'Closure Claim status cannot change after residual readiness.',
                );
            }

            $claim = DB::table('closure_claims')
                ->where('business_id', $business->getKey())
                ->where('closure_case_id', $caseId)
                ->where('id', $claimId)
                ->lockForUpdate()
                ->first();

            if ($claim === null) {
                return false;
            }

            if ((int) $claim->revision !== $expectedClaimRevision) {
                throw new StaleRevision(
                    expectedRevision: $expectedClaimRevision,
                    actualRevision: (int) $claim->revision,
                );
            }

            $from = ClosureClaimStatus::from((string) $claim->status);

            if (! $this->claimTransitionAllowed($from, $target)) {
                throw new InvalidArgumentException(
                    'Invalid Closure Claim status transition.',
                );
            }

            if (
                $target === ClosureClaimStatus::Settled
                && (int) ($claim->amount_minor_units ?? 0) > 0
                && ! $this->claimHasCompletedFinancePayment(
                    $business,
                    $caseId,
                    $claimId,
                )
            ) {
                throw new InvalidArgumentException(
                    'A monetary Closure Claim requires linked paid/completed F6C Finance payment evidence before settlement.',
                );
            }

            $newRevision = $expectedClaimRevision + 1;

            DB::table('closure_claims')
                ->where('business_id', $business->getKey())
                ->where('id', $claimId)
                ->where('revision', $expectedClaimRevision)
                ->update([
                    'status' => $target->value,
                    'revision' => $newRevision,
                    'updated_at' => now(),
                ]);

            DB::table('closure_claim_transitions')->insert([
                'id' => (string) Str::uuid7(),
                'business_id' => $business->getKey(),
                'closure_case_id' => $caseId,
                'closure_claim_id' => $claimId,
                'claim_revision' => $newRevision,
                'from_status' => $from->value,
                'to_status' => $target->value,
                'note' => $this->nullableText($note),
                'actor_membership_id' => $membership->getKey(),
                'occurred_at' => now(),
            ]);

            $this->occurrence->record(
                $user,
                $business,
                'closure.claim.transitioned',
                'closure_case',
                $caseId,
                [
                    'claim_id' => $claimId,
                    'from_status' => $from->value,
                    'to_status' => $target->value,
                    'claim_revision' => $newRevision,
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
        ?string $claimId = null,
    ): bool {
        $membership = $this->actor->membership(
            $user,
            $business,
            CapabilityCatalog::CLOSURE_MANAGE,
        );

        if (
            $membership === null
            || ! $this->actor->allows(
                $user,
                $business,
                CapabilityCatalog::FINANCE_VIEW,
            )
        ) {
            return false;
        }

        if (! in_array($purpose, [
            'claim_settlement',
            'tax',
            'residual_distribution',
            'other',
        ], true)) {
            throw new InvalidArgumentException(
                'Closure Finance-link purpose is invalid.',
            );
        }

        return DB::transaction(function () use (
            $user,
            $business,
            $caseId,
            $expectedRevision,
            $financePaymentId,
            $purpose,
            $claimId,
            $membership,
        ): bool {
            $case = $this->lockCase(
                $user,
                $business,
                $caseId,
                $expectedRevision,
            );

            if (in_array($case->status, [
                ClosureCaseStatus::LegallyClosed,
                ClosureCaseStatus::Completed,
                ClosureCaseStatus::Rejected,
                ClosureCaseStatus::Withdrawn,
                ClosureCaseStatus::Cancelled,
            ], true)) {
                throw new InvalidArgumentException(
                    'Finance evidence cannot be linked after Closure is terminal.',
                );
            }

            $payment = DB::table('finance_payments')
                ->where('business_id', $business->getKey())
                ->where('id', $financePaymentId)
                ->first();

            if ($payment === null) {
                return false;
            }

            if ($claimId !== null) {
                $claimExists = DB::table('closure_claims')
                    ->where('business_id', $business->getKey())
                    ->where('closure_case_id', $caseId)
                    ->where('id', $claimId)
                    ->exists();

                if (! $claimExists) {
                    return false;
                }
            }

            DB::table('closure_finance_links')->insertOrIgnore([
                'id' => (string) Str::uuid7(),
                'business_id' => $business->getKey(),
                'closure_case_id' => $caseId,
                'closure_claim_id' => $claimId,
                'finance_payment_id' => $financePaymentId,
                'purpose' => $purpose,
                'linked_by_membership_id' => $membership->getKey(),
                'linked_at' => now(),
            ]);

            $this->occurrence->record(
                $user,
                $business,
                'closure.finance.linked',
                'closure_case',
                $caseId,
                [
                    'finance_payment_id' => $financePaymentId,
                    'purpose' => $purpose,
                    'claim_id' => $claimId,
                ],
            );

            return true;
        });
    }

    public function submitGovernance(
        User $user,
        Business $business,
        string $caseId,
        int $expectedRevision,
    ): ?array {
        $membership = $this->actor->membership(
            $user,
            $business,
            CapabilityCatalog::CLOSURE_MANAGE,
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

            if ($case->status !== ClosureCaseStatus::Draft) {
                throw new InvalidArgumentException(
                    'Only a Draft Closure Case may enter Governance.',
                );
            }

            $this->assertRequirements(
                $business,
                $caseId,
                [
                    'assets_protected',
                    'asset_inventory_complete',
                    'liability_inventory_complete',
                ],
            );

            $payload = $this->snapshotPayload($business, $case);
            $packageHash = $this->contentHash($payload);
            $capability = new Capability(CapabilityCatalog::RECORDS_MANAGE);

            $family = $this->createFamily->execute(
                $user,
                $business,
                $capability,
                new RecordScope(
                    'closure_case',
                    'closure_case',
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
                    'Closure Case %s revision %d.',
                    $case->case_number,
                    $expectedRevision,
                ),
                $case->intended_legal_closure_at,
            );

            if ($recordVersion === null) {
                return null;
            }

            DB::table('closure_record_versions')->insert([
                'id' => (string) Str::uuid7(),
                'business_id' => $business->getKey(),
                'closure_case_id' => $case->getKey(),
                'formal_record_version_id' => $recordVersion->getKey(),
                'source_case_revision' => $expectedRevision,
                'trigger' => $case->trigger,
                'jurisdiction_reference' => $case->jurisdiction_reference,
                'governance_decision_type' => $case->governance_decision_type,
                'intended_legal_closure_at' => $case->intended_legal_closure_at,
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

            DB::table('closure_governance_submissions')->insert([
                'id' => (string) Str::uuid7(),
                'business_id' => $business->getKey(),
                'closure_case_id' => $case->getKey(),
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
                ClosureCaseStatus::UnderGovernance,
                (string) $membership->getKey(),
            );

            $this->occurrence->record(
                $user,
                $business,
                'closure.governance.submitted',
                'closure_case',
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
                'Closure content-review target is invalid.',
            );
        }

        if (! $this->actor->allows(
            $user,
            $business,
            CapabilityCatalog::CLOSURE_MANAGE,
        )) {
            return false;
        }

        $bound = DB::table('closure_record_versions')
            ->where('business_id', $business->getKey())
            ->where('closure_case_id', $caseId)
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
    ): ?ClosureCase {
        $membership = $this->actor->membership(
            $user,
            $business,
            CapabilityCatalog::CLOSURE_MANAGE,
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
        ): ?ClosureCase {
            $case = $this->lockCase(
                $user,
                $business,
                $caseId,
                $expectedRevision,
            );

            if ($case->status !== ClosureCaseStatus::UnderGovernance) {
                throw new InvalidArgumentException(
                    'Closure Case is not awaiting Governance.',
                );
            }

            $submission = $this->governanceSubmission(
                $business,
                $caseId,
                true,
            );

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
                throw new MissingGovernanceDecision;
            }

            $decision = $decisions->first();
            $approved = (string) $decision->outcome === 'approved';

            DB::table('closure_governance_submissions')
                ->where('id', $submission->id)
                ->where('business_id', $business->getKey())
                ->update([
                    'decision_id' => $decision->id,
                    'authorized_at' => $approved ? now() : null,
                ]);

            $target = $approved
                ? ClosureCaseStatus::Approved
                : ClosureCaseStatus::Rejected;

            return $this->applyTransition(
                $user,
                $business,
                $case,
                $target,
                (string) $membership->getKey(),
            );
        });
    }

    public function activateWindDown(
        User $user,
        Business $business,
        string $caseId,
        int $expectedRevision,
    ): ?ClosureCase {
        $membership = $this->actor->membership(
            $user,
            $business,
            CapabilityCatalog::CLOSURE_MANAGE,
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
        ): ClosureCase {
            $case = $this->lockCase(
                $user,
                $business,
                $caseId,
                $expectedRevision,
            );

            if ($case->status !== ClosureCaseStatus::Approved) {
                throw new InvalidArgumentException(
                    'Only an Approved Closure Case may enter wind-down.',
                );
            }

            return $this->applyTransition(
                $user,
                $business,
                $case,
                ClosureCaseStatus::WindDownActive,
                (string) $membership->getKey(),
            );
        });
    }

    public function prepareResidualDistribution(
        User $user,
        Business $business,
        string $caseId,
        int $expectedRevision,
    ): ?ClosureCase {
        $membership = $this->actor->membership(
            $user,
            $business,
            CapabilityCatalog::CLOSURE_MANAGE,
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
        ): ClosureCase {
            $case = $this->lockCase(
                $user,
                $business,
                $caseId,
                $expectedRevision,
            );

            if ($case->status !== ClosureCaseStatus::WindDownActive) {
                throw new InvalidArgumentException(
                    'Residual distribution readiness requires active wind-down.',
                );
            }

            $unresolvedRequiredClaims = DB::table('closure_claims')
                ->where('business_id', $business->getKey())
                ->where('closure_case_id', $caseId)
                ->where('required', true)
                ->whereNotIn('status', [
                    ClosureClaimStatus::Settled->value,
                    ClosureClaimStatus::Waived->value,
                ])
                ->exists();

            if ($unresolvedRequiredClaims) {
                throw new InvalidArgumentException(
                    'Required Closure Claims must be settled or waived before residual distribution.',
                );
            }

            $this->assertRequirements(
                $business,
                $caseId,
                [
                    'finance_reconciled',
                    'operations_reconciled',
                    'risk_continuity_reconciled',
                    'conflict_reconciled',
                ],
            );

            return $this->applyTransition(
                $user,
                $business,
                $case,
                ClosureCaseStatus::ResidualReady,
                (string) $membership->getKey(),
            );
        });
    }

    public function recordResidualDistribution(
        User $user,
        Business $business,
        string $caseId,
        int $expectedRevision,
        string $status,
        ?int $amountMinorUnits = null,
        ?string $currency = null,
        ?string $basis = null,
    ): ?ClosureCase {
        $membership = $this->actor->membership(
            $user,
            $business,
            CapabilityCatalog::CLOSURE_MANAGE,
        );

        if ($membership === null) {
            return null;
        }

        if (! in_array($status, [
            'not_applicable',
            'planned',
            'completed',
        ], true)) {
            throw new InvalidArgumentException(
                'Residual distribution status is invalid.',
            );
        }

        if ($amountMinorUnits !== null && $amountMinorUnits < 0) {
            throw new InvalidArgumentException(
                'Residual distribution amount cannot be negative.',
            );
        }

        $normalizedCurrency = $this->nullableCurrency($currency);

        return DB::transaction(function () use (
            $user,
            $business,
            $caseId,
            $expectedRevision,
            $status,
            $amountMinorUnits,
            $normalizedCurrency,
            $basis,
        ): ClosureCase {
            $case = $this->lockCase(
                $user,
                $business,
                $caseId,
                $expectedRevision,
            );

            if ($case->status !== ClosureCaseStatus::ResidualReady) {
                throw new InvalidArgumentException(
                    'Residual distribution may be recorded only after claims are resolved.',
                );
            }

            if (
                $status === 'completed'
                && $amountMinorUnits !== null
                && $amountMinorUnits > 0
                && ! $this->residualFinanceCompleted(
                    $business,
                    $caseId,
                    $amountMinorUnits,
                    $normalizedCurrency,
                )
            ) {
                throw new InvalidArgumentException(
                    'Completed residual distribution requires sufficient linked paid/completed F6C Finance payment evidence.',
                );
            }

            $case->residual_distribution_minor_units = $amountMinorUnits;
            $case->currency = $normalizedCurrency;
            $case->residual_distribution_basis = $this->nullableText($basis);
            $case->residual_distribution_status = $status;
            $case->revision = $expectedRevision + 1;
            $case->save();

            $this->occurrence->record(
                $user,
                $business,
                'closure.residual.recorded',
                'closure_case',
                $caseId,
                [
                    'case_revision' => (int) $case->revision,
                    'status' => $status,
                    'amount_minor_units' => $amountMinorUnits,
                    'currency' => $normalizedCurrency,
                ],
            );

            return $case->fresh();
        });
    }

    public function prepareLegalClosure(
        User $user,
        Business $business,
        string $caseId,
        int $expectedRevision,
    ): ?ClosureCase {
        $membership = $this->actor->membership(
            $user,
            $business,
            CapabilityCatalog::CLOSURE_MANAGE,
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
        ): ?ClosureCase {
            $case = $this->lockCase(
                $user,
                $business,
                $caseId,
                $expectedRevision,
            );

            if ($case->status !== ClosureCaseStatus::ResidualReady) {
                throw new InvalidArgumentException(
                    'Legal Closure preparation requires residual readiness.',
                );
            }

            if (! in_array($case->residual_distribution_status, [
                'completed',
                'not_applicable',
            ], true)) {
                throw new InvalidArgumentException(
                    'Residual distribution must be completed or not applicable before legal Closure.',
                );
            }

            $this->assertRequirements(
                $business,
                $caseId,
                [
                    'legal_conditions_satisfied',
                    'tax_requirements_resolved',
                    'records_retention_ready',
                    'post_closure_duties_recorded',
                ],
            );

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
                ClosureCaseStatus::LegalClosureReady,
                (string) $membership->getKey(),
            );
        });
    }

    public function effectLegalClosure(
        User $user,
        Business $business,
        string $caseId,
        int $expectedRevision,
    ): ?ClosureCase {
        $membership = $this->actor->membership(
            $user,
            $business,
            CapabilityCatalog::CLOSURE_MANAGE,
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
        ): ?ClosureCase {
            $case = $this->lockCase(
                $user,
                $business,
                $caseId,
                $expectedRevision,
            );

            if ($case->status !== ClosureCaseStatus::LegalClosureReady) {
                throw new InvalidArgumentException(
                    'Closure Case is not ready for legal effect.',
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

            DB::table('closure_governance_submissions')
                ->where('business_id', $business->getKey())
                ->where('id', $submission->id)
                ->whereNull('effected_at')
                ->update(['effected_at' => now()]);

            $case->legal_closed_at = now();
            $case->revision = $expectedRevision + 1;
            $case->status = ClosureCaseStatus::LegallyClosed;
            $case->save();

            $this->recordTransition(
                $business,
                $case,
                ClosureCaseStatus::LegalClosureReady,
                ClosureCaseStatus::LegallyClosed,
                (string) $membership->getKey(),
            );

            $this->occurrence->record(
                $user,
                $business,
                'closure.legal.effective',
                'closure_case',
                $caseId,
                [
                    'case_revision' => (int) $case->revision,
                    'formal_record_version_id' => (string) $submission->formal_record_version_id,
                    'decision_id' => (string) $submission->decision_id,
                ],
            );

            return $case->fresh();
        });
    }

    public function closeWorkspace(
        User $user,
        Business $business,
        string $caseId,
        int $expectedRevision,
        string $confirmation,
    ): ?ClosureCase {
        $membership = $this->actor->membership(
            $user,
            $business,
            CapabilityCatalog::CLOSURE_MANAGE,
        );

        if ($membership === null) {
            return null;
        }

        if (! hash_equals($business->name, trim($confirmation))) {
            throw new InvalidArgumentException(
                'Workspace Closure confirmation must exactly match the Business name.',
            );
        }

        return DB::transaction(function () use (
            $user,
            $business,
            $caseId,
            $expectedRevision,
            $membership,
        ): ?ClosureCase {
            $lockedBusiness = Business::query()
                ->whereKey($business->getKey())
                ->lockForUpdate()
                ->firstOrFail();

            $case = $this->lockCase(
                $user,
                $lockedBusiness,
                $caseId,
                $expectedRevision,
            );

            if ($case->status !== ClosureCaseStatus::LegallyClosed) {
                throw new InvalidArgumentException(
                    'Workspace may close only after legal Closure becomes Effective.',
                );
            }

            $submission = $this->governanceSubmission(
                $lockedBusiness,
                $caseId,
            );

            if ($submission === null || $submission->effected_at === null) {
                return null;
            }

            if ($lockedBusiness->workspace_status === WorkspaceStatus::Closed) {
                throw new InvalidArgumentException(
                    'Business workspace is already Closed.',
                );
            }

            $lockedBusiness->workspace_status = WorkspaceStatus::Closed;
            $lockedBusiness->save();

            $case->workspace_closed_at = now();
            $case->status = ClosureCaseStatus::Completed;
            $case->revision = $expectedRevision + 1;
            $case->save();

            $this->recordTransition(
                $lockedBusiness,
                $case,
                ClosureCaseStatus::LegallyClosed,
                ClosureCaseStatus::Completed,
                (string) $membership->getKey(),
            );

            $this->occurrence->record(
                $user,
                $lockedBusiness,
                'closure.workspace.closed',
                'closure_case',
                $caseId,
                [
                    'case_revision' => (int) $case->revision,
                    'workspace_status' => WorkspaceStatus::Closed->value,
                ],
            );

            return $case->fresh();
        });
    }

    public function transition(
        User $user,
        Business $business,
        string $caseId,
        int $expectedRevision,
        ClosureCaseStatus $target,
    ): ?ClosureCase {
        $membership = $this->actor->membership(
            $user,
            $business,
            CapabilityCatalog::CLOSURE_MANAGE,
        );

        if ($membership === null) {
            return null;
        }

        if (! in_array($target, [
            ClosureCaseStatus::Withdrawn,
            ClosureCaseStatus::Cancelled,
        ], true)) {
            throw new InvalidArgumentException(
                'Direct Closure transition is not allowed.',
            );
        }

        return DB::transaction(function () use (
            $user,
            $business,
            $caseId,
            $expectedRevision,
            $target,
            $membership,
        ): ClosureCase {
            $case = $this->lockCase(
                $user,
                $business,
                $caseId,
                $expectedRevision,
            );

            return $this->applyTransition(
                $user,
                $business,
                $case,
                $target,
                (string) $membership->getKey(),
            );
        });
    }

    private function lockCase(
        User $user,
        Business $business,
        string $caseId,
        int $expectedRevision,
    ): ClosureCase {
        if (! $this->actor->allows(
            $user,
            $business,
            CapabilityCatalog::CLOSURE_MANAGE,
        )) {
            throw new InvalidArgumentException(
                'Closure management is not authorized.',
            );
        }

        $case = ClosureCase::query()
            ->where('business_id', $business->getKey())
            ->whereKey($caseId)
            ->lockForUpdate()
            ->first();

        if ($case === null) {
            throw new InvalidArgumentException(
                'Closure Case was not found in the current Business.',
            );
        }

        if ((int) $case->revision !== $expectedRevision) {
            throw new StaleRevision(
                expectedRevision: $expectedRevision,
                actualRevision: (int) $case->revision,
            );
        }

        return $case;
    }

    private function applyTransition(
        User $user,
        Business $business,
        ClosureCase $case,
        ClosureCaseStatus $target,
        string $membershipId,
    ): ClosureCase {
        $from = $case->status;

        if (! $this->stateMachine->allows($from, $target)) {
            throw new InvalidArgumentException(
                'Invalid Closure Case status transition.',
            );
        }

        if ($from === $target) {
            return $case;
        }

        $case->status = $target;
        $case->revision = (int) $case->revision + 1;
        $case->save();

        $this->recordTransition(
            $business,
            $case,
            $from,
            $target,
            $membershipId,
        );

        $this->occurrence->record(
            $user,
            $business,
            'closure.case.transitioned',
            'closure_case',
            (string) $case->getKey(),
            [
                'from_status' => $from->value,
                'to_status' => $target->value,
                'case_revision' => (int) $case->revision,
            ],
        );

        return $case->fresh();
    }

    private function recordTransition(
        Business $business,
        ClosureCase $case,
        ?ClosureCaseStatus $from,
        ClosureCaseStatus $to,
        string $membershipId,
    ): void {
        DB::table('closure_case_transitions')->insert([
            'id' => (string) Str::uuid7(),
            'business_id' => $business->getKey(),
            'closure_case_id' => $case->getKey(),
            'case_revision' => (int) $case->revision,
            'from_status' => $from?->value,
            'to_status' => $to->value,
            'actor_membership_id' => $membershipId,
            'occurred_at' => now(),
        ]);
    }

    /** @param list<string> $keys */
    private function assertRequirements(
        Business $business,
        string $caseId,
        array $keys,
    ): void {
        foreach ($keys as $key) {
            $latest = DB::table('closure_requirements')
                ->where('business_id', $business->getKey())
                ->where('closure_case_id', $caseId)
                ->where('requirement_key', $key)
                ->orderByDesc('recorded_at')
                ->orderByDesc('id')
                ->value('status');

            if (! in_array($latest, ['met', 'not_applicable'], true)) {
                throw new InvalidArgumentException(
                    sprintf(
                        'Closure requirement "%s" must be met or not applicable.',
                        $key,
                    ),
                );
            }
        }
    }

    private function validateSource(
        User $user,
        Business $business,
        ?string $sourceType,
        ?string $sourceId,
    ): void {
        if ($sourceType === null && $sourceId === null) {
            return;
        }

        if ($sourceType === null || $sourceId === null || ! Str::isUuid($sourceId)) {
            throw new InvalidArgumentException(
                'Closure source type and UUID must be supplied together.',
            );
        }

        if ($sourceType === 'finance_payment') {
            if (! $this->actor->allows(
                $user,
                $business,
                CapabilityCatalog::FINANCE_VIEW,
            )) {
                throw new InvalidArgumentException(
                    'Finance visibility is required for this Closure source.',
                );
            }

            $exists = DB::table('finance_payments')
                ->where('business_id', $business->getKey())
                ->where('id', $sourceId)
                ->exists();

            if (! $exists) {
                throw new InvalidArgumentException(
                    'Finance source does not belong to the current Business.',
                );
            }

            return;
        }

        if ($sourceType === 'formal_record_version') {
            if (! $this->actor->allows(
                $user,
                $business,
                CapabilityCatalog::RECORDS_VIEW,
            )) {
                throw new InvalidArgumentException(
                    'Record visibility is required for this Closure source.',
                );
            }

            $exists = DB::table('formal_record_versions')
                ->where('business_id', $business->getKey())
                ->where('id', $sourceId)
                ->exists();

            if (! $exists) {
                throw new InvalidArgumentException(
                    'Formal-record source does not belong to the current Business.',
                );
            }

            return;
        }

        throw new InvalidArgumentException(
            'Unsupported Closure source type.',
        );
    }

    private function governanceSubmission(
        Business $business,
        string $caseId,
        bool $lock = false,
    ): ?object {
        $query = DB::table('closure_governance_submissions')
            ->where('business_id', $business->getKey())
            ->where('closure_case_id', $caseId);

        if ($lock) {
            $query->lockForUpdate();
        }

        return $query->first();
    }

    private function claimTransitionAllowed(
        ClosureClaimStatus $from,
        ClosureClaimStatus $to,
    ): bool {
        if ($from === $to) {
            return true;
        }

        return match ($from) {
            ClosureClaimStatus::Identified => in_array($to, [
                ClosureClaimStatus::Verified,
                ClosureClaimStatus::Disputed,
                ClosureClaimStatus::Waived,
            ], true),
            ClosureClaimStatus::Verified => in_array($to, [
                ClosureClaimStatus::Disputed,
                ClosureClaimStatus::Settled,
                ClosureClaimStatus::Waived,
            ], true),
            ClosureClaimStatus::Disputed => in_array($to, [
                ClosureClaimStatus::Verified,
                ClosureClaimStatus::Settled,
                ClosureClaimStatus::Waived,
            ], true),
            ClosureClaimStatus::Settled,
            ClosureClaimStatus::Waived => false,
        };
    }

    private function claimHasCompletedFinancePayment(
        Business $business,
        string $caseId,
        string $claimId,
    ): bool {
        return DB::table('closure_finance_links as link')
            ->join(
                'finance_payments as payment',
                function ($join): void {
                    $join->on(
                        'payment.id',
                        '=',
                        'link.finance_payment_id',
                    )->on(
                        'payment.business_id',
                        '=',
                        'link.business_id',
                    );
                },
            )
            ->where('link.business_id', $business->getKey())
            ->where('link.closure_case_id', $caseId)
            ->where('link.closure_claim_id', $claimId)
            ->where('link.purpose', 'claim_settlement')
            ->whereIn('payment.status', ['paid', 'completed'])
            ->exists();
    }

    private function residualFinanceCompleted(
        Business $business,
        string $caseId,
        int $amountMinorUnits,
        ?string $currency,
    ): bool {
        if ($currency === null) {
            return false;
        }

        $paid = (int) DB::table('closure_finance_links as link')
            ->join(
                'finance_payments as payment',
                function ($join): void {
                    $join->on(
                        'payment.id',
                        '=',
                        'link.finance_payment_id',
                    )->on(
                        'payment.business_id',
                        '=',
                        'link.business_id',
                    );
                },
            )
            ->where('link.business_id', $business->getKey())
            ->where('link.closure_case_id', $caseId)
            ->where('link.purpose', 'residual_distribution')
            ->where('payment.currency', $currency)
            ->whereIn('payment.status', ['paid', 'completed'])
            ->sum('payment.amount_minor_units');

        return $paid >= $amountMinorUnits;
    }

    /** @return array<string, mixed> */
    private function snapshotPayload(
        Business $business,
        ClosureCase $case,
    ): array {
        $claims = DB::table('closure_claims')
            ->where('business_id', $business->getKey())
            ->where('closure_case_id', $case->getKey())
            ->orderBy('claim_reference')
            ->get([
                'id',
                'claim_reference',
                'claim_type',
                'claimant_reference',
                'amount_minor_units',
                'currency',
                'required',
                'legal_priority_reference',
                'source_type',
                'source_id',
                'external_source_reference',
                'status',
                'revision',
            ])
            ->map(static fn (object $claim): array => (array) $claim)
            ->all();

        $requirements = DB::table('closure_requirements')
            ->where('business_id', $business->getKey())
            ->where('closure_case_id', $case->getKey())
            ->orderBy('recorded_at')
            ->get([
                'requirement_type',
                'requirement_key',
                'status',
                'detail',
                'source_type',
                'source_id',
                'external_source_reference',
                'recorded_at',
            ])
            ->map(static fn (object $requirement): array => (array) $requirement)
            ->all();

        return [
            'business_id' => (string) $business->getKey(),
            'closure_case_id' => (string) $case->getKey(),
            'case_number' => $case->case_number,
            'revision' => (int) $case->revision,
            'trigger' => $case->trigger,
            'trigger_detail' => $case->trigger_detail,
            'jurisdiction_reference' => $case->jurisdiction_reference,
            'legal_entity_reference' => $case->legal_entity_reference,
            'governance_decision_type' => $case->governance_decision_type,
            'intended_legal_closure_at' => $case->intended_legal_closure_at?->format(DATE_ATOM),
            'claims' => $claims,
            'requirements' => $requirements,
        ];
    }

    /** @param array<string, mixed> $payload */
    private function contentHash(array $payload): string
    {
        $encoded = json_encode(
            $payload,
            JSON_THROW_ON_ERROR
            | JSON_UNESCAPED_SLASHES
            | JSON_UNESCAPED_UNICODE
            | JSON_PRESERVE_ZERO_FRACTION,
        );

        return hash('sha256', $encoded);
    }

    private function requiredText(string $value, string $label): string
    {
        $normalized = trim($value);

        if ($normalized === '') {
            throw new InvalidArgumentException(
                $label.' is required.',
            );
        }

        return $normalized;
    }

    private function nullableText(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $normalized = trim($value);

        return $normalized === '' ? null : $normalized;
    }

    private function nullableCurrency(?string $value): ?string
    {
        $normalized = $this->nullableText($value);

        if ($normalized === null) {
            return null;
        }

        if (
            strlen($normalized) !== 3
            || strtoupper($normalized) !== $normalized
            || preg_match('/^[A-Z]{3}$/', $normalized) !== 1
        ) {
            throw new InvalidArgumentException(
                'Currency must be an uppercase ISO-style three-letter code.',
            );
        }

        return $normalized;
    }
}
