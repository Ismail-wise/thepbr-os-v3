<?php

declare(strict_types=1);

namespace App\Application\Partnership;

use App\Application\Records\CreateDraftRecordVersion;
use App\Application\Records\CreateFormalRecordFamily;
use App\Application\Records\CreateProposal;
use App\Application\Records\FreezeProposalVersion;
use App\Application\Records\SubmitRecordVersionForReview;
use App\Application\Records\TransitionFormalRecordVersion;
use App\Domain\Access\CapabilityCatalog;
use App\Domain\Access\ValueObjects\Capability;
use App\Domain\Partnership\Enums\ContributionStatus;
use App\Domain\Partnership\Enums\ContributionType;
use App\Domain\Partnership\ValueObjects\ContributionValue;
use App\Domain\Records\Enums\FormalRecordState;
use App\Domain\Records\Exceptions\StaleRevision;
use App\Domain\Records\ValueObjects\RecordScope;
use App\Infrastructure\Persistence\Eloquent\Businesses\Business;
use App\Infrastructure\Persistence\Eloquent\Identity\User;
use DateTimeInterface;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;
use RuntimeException;

final class ContributionWorkflow
{
    public function __construct(
        private readonly PartnershipActorContext $actor,
        private readonly PartnershipOccurrence $occurrence,
        private readonly CreateFormalRecordFamily $createFamily,
        private readonly CreateDraftRecordVersion $createDraft,
        private readonly SubmitRecordVersionForReview $submitForReview,
        private readonly CreateProposal $createProposal,
        private readonly FreezeProposalVersion $freezeProposal,
        private readonly TransitionFormalRecordVersion $transitionRecord,
    ) {}

    /**
     * @param  array<string, mixed>  $details
     * @return array{id:string, revision:int}|null
     */
    public function create(
        User $user,
        Business $business,
        string $partnerId,
        ContributionType $type,
        string $currency,
        string $description,
        ContributionValue $proposedValue,
        ?string $conditions,
        ?string $committedDate,
        ?string $dueDate,
        array $details,
    ): ?array {
        $membership = $this->actor->membership(
            $user,
            $business,
            CapabilityCatalog::CONTRIBUTIONS_MANAGE,
        );

        if ($membership === null) {
            return null;
        }

        if (
            ! DB::table('partners')
                ->where('business_id', $business->getKey())
                ->where('id', $partnerId)
                ->exists()
        ) {
            return null;
        }

        if (preg_match('/\A[A-Z]{3}\z/', $currency) !== 1) {
            throw new InvalidArgumentException(
                'Contribution currency must be a three-letter uppercase code.',
            );
        }

        $description = trim($description);

        if ($description === '' || mb_strlen($description) > 300) {
            throw new InvalidArgumentException(
                'Contribution description is required.',
            );
        }

        $id = (string) Str::uuid7();

        DB::transaction(function () use (
            $user,
            $business,
            $partnerId,
            $type,
            $currency,
            $description,
            $proposedValue,
            $conditions,
            $committedDate,
            $dueDate,
            $details,
            $membership,
            $id,
        ): void {
            DB::table('contributions')->insert([
                'id' => $id,
                'business_id' => $business->getKey(),
                'partner_id' => $partnerId,
                'contribution_type' => $type->value,
                'status' => ContributionStatus::Proposed->value,
                'currency' => $currency,
                'description' => $description,
                'proposed_value' => $proposedValue->amount,
                'reviewed_value' => null,
                'approved_value' => null,
                'accepted_value' => null,
                'valuation_method' => null,
                'conditions' => $this->nullableTrim($conditions),
                'committed_date' => $committedDate,
                'due_date' => $dueDate,
                'approval_decision_id' => null,
                'acceptance_decision_id' => null,
                'revision' => 1,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            $this->insertTypeDetails(
                $business,
                $id,
                $type,
                $details,
            );

            $this->appendStatusTransition(
                $business,
                $id,
                $membership->getKey(),
                null,
                ContributionStatus::Proposed,
                'Contribution proposed.',
            );

            $this->occurrence->record(
                $user,
                $business,
                'partnership.contribution.proposed',
                'contribution',
                $id,
                [
                    'partner_id' => $partnerId,
                    'contribution_type' => $type->value,
                    'proposed_value' => $proposedValue->amount,
                ],
            );
        });

        return [
            'id' => $id,
            'revision' => 1,
        ];
    }

    public function review(
        User $user,
        Business $business,
        string $contributionId,
        int $expectedRevision,
        ContributionValue $reviewedValue,
        string $valuationMethod,
        ?string $note = null,
    ): ?bool {
        $membership = $this->actor->membership(
            $user,
            $business,
            CapabilityCatalog::CONTRIBUTIONS_MANAGE,
        );

        if ($membership === null) {
            return null;
        }

        $valuationMethod = trim($valuationMethod);

        if ($valuationMethod === '') {
            throw new InvalidArgumentException(
                'A valuation method is required before Contribution review.',
            );
        }

        return DB::transaction(function () use (
            $user,
            $business,
            $contributionId,
            $expectedRevision,
            $reviewedValue,
            $valuationMethod,
            $note,
            $membership,
        ): ?bool {
            $row = $this->lockContribution(
                $business,
                $contributionId,
            );

            if ($row === null) {
                return null;
            }

            $this->assertRevision(
                $expectedRevision,
                (int) $row->revision,
            );

            if (
                ContributionStatus::from($row->status)
                !== ContributionStatus::Proposed
            ) {
                throw new InvalidArgumentException(
                    'Only Proposed Contributions may be reviewed.',
                );
            }

            DB::table('contributions')
                ->where('id', $contributionId)
                ->where('business_id', $business->getKey())
                ->update([
                    'status' => ContributionStatus::Reviewed->value,
                    'reviewed_value' => $reviewedValue->amount,
                    'valuation_method' => $valuationMethod,
                    'revision' => ((int) $row->revision) + 1,
                    'updated_at' => now(),
                ]);

            $this->appendStatusTransition(
                $business,
                $contributionId,
                $membership->getKey(),
                ContributionStatus::Proposed,
                ContributionStatus::Reviewed,
                $note,
            );

            $this->occurrence->record(
                $user,
                $business,
                'partnership.contribution.reviewed',
                'contribution',
                $contributionId,
                [
                    'reviewed_value' => $reviewedValue->amount,
                    'revision' => ((int) $row->revision) + 1,
                ],
            );

            return true;
        });
    }

    /**
     * Approval and Acceptance are never established by System Permission
     * alone. This method creates an exact frozen F2 Proposal Version for F3.
     *
     * @return array{
     *   id:string,
     *   formal_record_version_id:string,
     *   proposal_version_id:string
     * }|null
     */
    public function submitGovernance(
        User $user,
        Business $business,
        string $contributionId,
        string $phase,
        ?ContributionValue $proposedAcceptedValue = null,
    ): ?array {
        $membership = $this->actor->membership(
            $user,
            $business,
            CapabilityCatalog::CONTRIBUTIONS_MANAGE,
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

        if (! in_array($phase, ['approval', 'acceptance'], true)) {
            throw new InvalidArgumentException(
                'Invalid Contribution governance phase.',
            );
        }

        return DB::transaction(function () use (
            $user,
            $business,
            $contributionId,
            $phase,
            $proposedAcceptedValue,
            $membership,
        ): ?array {
            $row = $this->lockContribution(
                $business,
                $contributionId,
            );

            if ($row === null) {
                return null;
            }

            $status = ContributionStatus::from(
                (string) $row->status,
            );

            if (
                $phase === 'approval'
                && $status !== ContributionStatus::Reviewed
            ) {
                throw new InvalidArgumentException(
                    'Contribution approval submission requires Reviewed status.',
                );
            }

            if (
                $phase === 'acceptance'
                && $status !== ContributionStatus::Delivered
            ) {
                throw new InvalidArgumentException(
                    'Contribution acceptance submission requires Delivered status.',
                );
            }

            if (
                $phase === 'approval'
                && $proposedAcceptedValue !== null
            ) {
                throw new InvalidArgumentException(
                    'Approval submission cannot contain an Accepted Contribution Value.',
                );
            }

            if (
                $phase === 'acceptance'
                && $proposedAcceptedValue === null
            ) {
                throw new InvalidArgumentException(
                    'Acceptance submission requires the proposed Accepted Contribution Value.',
                );
            }

            $evidenceIds = DB::table('evidence_links')
                ->where('business_id', $business->getKey())
                ->where('target_type', 'contribution')
                ->where('target_id', $contributionId)
                ->orderBy('evidence_id')
                ->pluck('evidence_id')
                ->map(
                    static fn (mixed $id): string => (string) $id,
                )
                ->all();

            if ($evidenceIds === []) {
                throw new InvalidArgumentException(
                    'Contribution governance submission requires linked Evidence.',
                );
            }

            $deliveredTotal = $this->deliveredTotalMinorUnits(
                $business,
                $contributionId,
            );

            if ($phase === 'acceptance') {
                $approved = new ContributionValue(
                    (string) $row->approved_value,
                );

                if (
                    ! $proposedAcceptedValue->lessThanOrEqual(
                        $approved,
                    )
                    || $proposedAcceptedValue->minorUnits()
                        > $deliveredTotal
                ) {
                    throw new InvalidArgumentException(
                        'Accepted Contribution Value cannot exceed approved or delivered value.',
                    );
                }
            }

            $payload = $this->snapshotPayload(
                $business,
                $row,
                $evidenceIds,
                $deliveredTotal,
                $phase,
                $proposedAcceptedValue,
            );

            $contentHash = hash(
                'sha256',
                json_encode(
                    $payload,
                    JSON_THROW_ON_ERROR
                    | JSON_UNESCAPED_SLASHES
                    | JSON_UNESCAPED_UNICODE,
                ),
            );

            $existing = DB::table(
                'contribution_governance_submissions',
            )
                ->where('business_id', $business->getKey())
                ->where('contribution_id', $contributionId)
                ->where(
                    'contribution_revision',
                    $row->revision,
                )
                ->where('phase', $phase)
                ->first();

            if ($existing !== null) {
                return [
                    'id' => (string) $existing->id,
                    'formal_record_version_id' => (string) $existing->formal_record_version_id,
                    'proposal_version_id' => (string) $existing->proposal_version_id,
                ];
            }

            $latestSubmission = DB::table(
                'contribution_governance_submissions',
            )
                ->where('business_id', $business->getKey())
                ->where('contribution_id', $contributionId)
                ->orderByDesc('created_at')
                ->first();

            $familyId =
                $latestSubmission?->formal_record_family_id;

            $predecessorId =
                $latestSubmission?->formal_record_version_id;

            if ($familyId === null) {
                $family = $this->createFamily->execute(
                    $user,
                    $business,
                    new Capability(
                        CapabilityCatalog::RECORDS_MANAGE,
                    ),
                    new RecordScope(
                        'partner_contribution',
                        'contribution',
                        $contributionId,
                    ),
                );

                if ($family === null) {
                    return null;
                }

                $familyId = (string) $family->getKey();
            }

            $recordVersion = $this->createDraft->execute(
                $user,
                $business,
                new Capability(
                    CapabilityCatalog::RECORDS_MANAGE,
                ),
                (string) $familyId,
                $contentHash,
                sprintf(
                    'Contribution %s proposal for revision %d.',
                    $phase,
                    (int) $row->revision,
                ),
                now(),
                null,
                null,
                $predecessorId === null
                    ? null
                    : (string) $predecessorId,
            );

            if ($recordVersion === null) {
                return null;
            }

            $submitted = $this->submitForReview->execute(
                $user,
                $business,
                new Capability(
                    CapabilityCatalog::RECORDS_MANAGE,
                ),
                (string) $recordVersion->getKey(),
                1,
            );

            if ($submitted === null) {
                return null;
            }

            $proposal = $this->createProposal->execute(
                $user,
                $business,
                new Capability(
                    CapabilityCatalog::RECORDS_MANAGE,
                ),
                $contentHash,
            );

            if ($proposal === null) {
                return null;
            }

            $proposalVersion = $this->freezeProposal->execute(
                $user,
                $business,
                new Capability(
                    CapabilityCatalog::RECORDS_MANAGE,
                ),
                (string) $proposal->getKey(),
                1,
                [(string) $recordVersion->getKey()],
            );

            if ($proposalVersion === null) {
                return null;
            }

            $submissionId = (string) Str::uuid7();

            DB::table(
                'contribution_governance_submissions',
            )->insert([
                'id' => $submissionId,
                'business_id' => $business->getKey(),
                'contribution_id' => $contributionId,
                'contribution_revision' => $row->revision,
                'phase' => $phase,
                'proposed_accepted_value' => $proposedAcceptedValue?->amount,
                'formal_record_family_id' => $familyId,
                'formal_record_version_id' => $recordVersion->getKey(),
                'proposal_id' => $proposal->getKey(),
                'proposal_version_id' => $proposalVersion->getKey(),
                'content_hash' => $contentHash,
                'created_by_membership_id' => $membership->getKey(),
                'created_at' => now(),
            ]);

            $this->occurrence->record(
                $user,
                $business,
                'partnership.contribution.governance_submitted',
                'contribution',
                $contributionId,
                [
                    'phase' => $phase,
                    'contribution_revision' => (int) $row->revision,
                    'proposal_version_id' => (string) $proposalVersion->getKey(),
                ],
            );

            return [
                'id' => $submissionId,
                'formal_record_version_id' => (string) $recordVersion->getKey(),
                'proposal_version_id' => (string) $proposalVersion->getKey(),
            ];
        });
    }

    public function advanceContentReview(
        User $user,
        Business $business,
        string $submissionId,
        FormalRecordState $target,
    ): ?bool {
        if (
            ! in_array(
                $target,
                [
                    FormalRecordState::UnderReview,
                    FormalRecordState::Approved,
                ],
                true,
            )
        ) {
            throw new InvalidArgumentException(
                'Contribution content review may only enter Under Review or Approved.',
            );
        }

        $submission = DB::table(
            'contribution_governance_submissions',
        )
            ->where('business_id', $business->getKey())
            ->where('id', $submissionId)
            ->first();

        if ($submission === null) {
            return null;
        }

        $result = $this->transitionRecord->execute(
            $user,
            $business,
            new Capability(
                CapabilityCatalog::RECORDS_MANAGE,
            ),
            (string) $submission->formal_record_version_id,
            $target,
        );

        return $result === null ? null : true;
    }

    /**
     * Synchronizes an already-authorized F3 Decision.
     *
     * This method does not create, approve, vote or resolve a Decision.
     */
    public function syncGovernanceDecision(
        User $user,
        Business $business,
        string $submissionId,
    ): ?bool {
        $membership = $this->actor->membership(
            $user,
            $business,
            CapabilityCatalog::CONTRIBUTIONS_MANAGE,
        );

        if ($membership === null) {
            return null;
        }

        return DB::transaction(function () use (
            $user,
            $business,
            $submissionId,
            $membership,
        ): ?bool {
            $submission = DB::table(
                'contribution_governance_submissions',
            )
                ->where('business_id', $business->getKey())
                ->where('id', $submissionId)
                ->lockForUpdate()
                ->first();

            if ($submission === null) {
                return null;
            }

            $expectedDecisionType =
                $submission->phase === 'approval'
                    ? 'contribution_approval'
                    : 'contribution_acceptance';

            $decision = DB::table('decisions')
                ->where('business_id', $business->getKey())
                ->where(
                    'proposal_version_id',
                    $submission->proposal_version_id,
                )
                ->where(
                    'decision_type',
                    $expectedDecisionType,
                )
                ->where('status', 'decided')
                ->where('outcome', 'approved')
                ->first();

            if ($decision === null) {
                return false;
            }

            $row = $this->lockContribution(
                $business,
                (string) $submission->contribution_id,
            );

            if ($row === null) {
                return null;
            }

            if (
                (int) $row->revision
                !== (int) $submission->contribution_revision
            ) {
                throw new RuntimeException(
                    'Contribution changed after its frozen governance submission.',
                );
            }

            $currentEvidenceIds = DB::table('evidence_links')
                ->where('business_id', $business->getKey())
                ->where('target_type', 'contribution')
                ->where('target_id', $row->id)
                ->orderBy('evidence_id')
                ->pluck('evidence_id')
                ->map(
                    static fn (mixed $id): string => (string) $id,
                )
                ->all();

            $currentDeliveredTotal =
                $this->deliveredTotalMinorUnits(
                    $business,
                    (string) $row->id,
                );

            $frozenAcceptedValue =
                $submission->phase === 'acceptance'
                    ? new ContributionValue(
                        (string) $submission->proposed_accepted_value,
                    )
                    : null;

            $currentPayload = $this->snapshotPayload(
                $business,
                $row,
                $currentEvidenceIds,
                $currentDeliveredTotal,
                (string) $submission->phase,
                $frozenAcceptedValue,
            );

            $currentContentHash = hash(
                'sha256',
                json_encode(
                    $currentPayload,
                    JSON_THROW_ON_ERROR
                    | JSON_UNESCAPED_SLASHES
                    | JSON_UNESCAPED_UNICODE,
                ),
            );

            if (
                ! hash_equals(
                    (string) $submission->content_hash,
                    $currentContentHash,
                )
            ) {
                throw new RuntimeException(
                    'Contribution content changed after its frozen governance submission.',
                );
            }

            if ($submission->phase === 'approval') {
                if (
                    ContributionStatus::from($row->status)
                    !== ContributionStatus::Reviewed
                ) {
                    throw new RuntimeException(
                        'Contribution is no longer awaiting approval.',
                    );
                }

                DB::table('contributions')
                    ->where('business_id', $business->getKey())
                    ->where('id', $row->id)
                    ->update([
                        'status' => ContributionStatus::Approved->value,
                        'approved_value' => $row->reviewed_value,
                        'approval_decision_id' => $decision->id,
                        'revision' => ((int) $row->revision) + 1,
                        'updated_at' => now(),
                    ]);

                $this->appendStatusTransition(
                    $business,
                    (string) $row->id,
                    $membership->getKey(),
                    ContributionStatus::Reviewed,
                    ContributionStatus::Approved,
                    'Governance Decision approved exact frozen Contribution proposal.',
                );

                $action =
                    'partnership.contribution.approved';
            } else {
                if (
                    ContributionStatus::from($row->status)
                    !== ContributionStatus::Delivered
                ) {
                    throw new RuntimeException(
                        'Contribution is no longer awaiting acceptance.',
                    );
                }

                DB::table('contributions')
                    ->where('business_id', $business->getKey())
                    ->where('id', $row->id)
                    ->update([
                        'status' => ContributionStatus::Accepted->value,
                        'accepted_value' => $submission->proposed_accepted_value,
                        'acceptance_decision_id' => $decision->id,
                        'revision' => ((int) $row->revision) + 1,
                        'updated_at' => now(),
                    ]);

                $this->appendStatusTransition(
                    $business,
                    (string) $row->id,
                    $membership->getKey(),
                    ContributionStatus::Delivered,
                    ContributionStatus::Accepted,
                    'Governance Decision accepted exact frozen delivered Contribution value.',
                );

                $action =
                    'partnership.contribution.accepted';
            }

            $this->occurrence->record(
                $user,
                $business,
                $action,
                'contribution',
                (string) $row->id,
                [
                    'decision_id' => (string) $decision->id,
                    'proposal_version_id' => (string) $submission->proposal_version_id,
                    'revision' => ((int) $row->revision) + 1,
                ],
            );

            return true;
        });
    }

    public function recordDelivery(
        User $user,
        Business $business,
        string $contributionId,
        int $expectedRevision,
        ContributionValue $deliveredValue,
        DateTimeInterface $deliveredAt,
        ?string $notes,
        bool $markDelivered,
    ): ?string {
        $membership = $this->actor->membership(
            $user,
            $business,
            CapabilityCatalog::CONTRIBUTIONS_MANAGE,
        );

        if ($membership === null) {
            return null;
        }

        if ($deliveredValue->minorUnits() <= 0) {
            throw new InvalidArgumentException(
                'Delivered Contribution Value must be greater than zero.',
            );
        }

        return DB::transaction(function () use (
            $user,
            $business,
            $contributionId,
            $expectedRevision,
            $deliveredValue,
            $deliveredAt,
            $notes,
            $markDelivered,
            $membership,
        ): ?string {
            $row = $this->lockContribution(
                $business,
                $contributionId,
            );

            if ($row === null) {
                return null;
            }

            $this->assertRevision(
                $expectedRevision,
                (int) $row->revision,
            );

            $status = ContributionStatus::from(
                (string) $row->status,
            );

            if (
                ! in_array(
                    $status,
                    [
                        ContributionStatus::Approved,
                        ContributionStatus::Delivered,
                    ],
                    true,
                )
            ) {
                throw new InvalidArgumentException(
                    'Only Approved or Delivered Contributions may record delivery.',
                );
            }

            $eventId = (string) Str::uuid7();

            DB::table('contribution_delivery_events')->insert([
                'id' => $eventId,
                'business_id' => $business->getKey(),
                'contribution_id' => $contributionId,
                'delivered_value' => $deliveredValue->amount,
                'delivered_at' => $deliveredAt,
                'notes' => $this->nullableTrim($notes),
                'recorded_by_membership_id' => $membership->getKey(),
                'created_at' => now(),
            ]);

            $nextStatus =
                $markDelivered
                && $status === ContributionStatus::Approved
                    ? ContributionStatus::Delivered
                    : $status;

            DB::table('contributions')
                ->where('business_id', $business->getKey())
                ->where('id', $contributionId)
                ->update([
                    'status' => $nextStatus->value,
                    'revision' => ((int) $row->revision) + 1,
                    'updated_at' => now(),
                ]);

            if ($nextStatus !== $status) {
                $this->appendStatusTransition(
                    $business,
                    $contributionId,
                    $membership->getKey(),
                    $status,
                    $nextStatus,
                    'Delivery marked complete.',
                );
            }

            $this->occurrence->record(
                $user,
                $business,
                'partnership.contribution.delivery_recorded',
                'contribution',
                $contributionId,
                [
                    'delivery_event_id' => $eventId,
                    'delivered_value' => $deliveredValue->amount,
                    'status' => $nextStatus->value,
                    'revision' => ((int) $row->revision) + 1,
                ],
            );

            return $eventId;
        });
    }

    /**
     * Current ownership input source.
     *
     * This intentionally exposes Accepted Contribution Value only.
     *
     * @return list<array{
     *   contribution_id:string,
     *   partner_id:string,
     *   currency:string,
     *   accepted_value:string
     * }>
     */
    public function acceptedValues(
        User $user,
        Business $business,
    ): array {
        if (
            ! $this->actor->allows(
                $user,
                $business,
                CapabilityCatalog::CONTRIBUTIONS_VIEW,
            )
        ) {
            return [];
        }

        return DB::table('contributions')
            ->where('business_id', $business->getKey())
            ->where(
                'status',
                ContributionStatus::Accepted->value,
            )
            ->whereNotNull('accepted_value')
            ->orderBy('partner_id')
            ->orderBy('id')
            ->get([
                'id',
                'partner_id',
                'currency',
                'accepted_value',
            ])
            ->map(
                static fn (object $row): array => [
                    'contribution_id' => (string) $row->id,
                    'partner_id' => (string) $row->partner_id,
                    'currency' => (string) $row->currency,
                    'accepted_value' => (new ContributionValue(
                        (string) $row->accepted_value,
                    ))->amount,
                ],
            )
            ->all();
    }

    private function lockContribution(
        Business $business,
        string $contributionId,
    ): ?object {
        return DB::table('contributions')
            ->where('business_id', $business->getKey())
            ->where('id', $contributionId)
            ->lockForUpdate()
            ->first();
    }

    private function assertRevision(
        int $expected,
        int $actual,
    ): void {
        if ($expected !== $actual) {
            throw new StaleRevision(
                $expected,
                $actual,
            );
        }
    }

    /**
     * @param  array<string, mixed>  $details
     */
    private function insertTypeDetails(
        Business $business,
        string $contributionId,
        ContributionType $type,
        array $details,
    ): void {
        $businessId = $business->getKey();

        match ($type) {
            ContributionType::Cash => DB::table('cash_contribution_details')->insert([
                'contribution_id' => $contributionId,
                'business_id' => $businessId,
                'amount_committed' => $this->requiredAmount(
                    $details,
                    'amount_committed',
                ),
                'amount_received' => $this->optionalAmount(
                    $details,
                    'amount_received',
                ) ?? '0.00',
                'payment_date' => $details['payment_date'] ?? null,
            ]),

            ContributionType::TimeSkill => DB::table(
                'time_skill_contribution_details',
            )->insert([
                'contribution_id' => $contributionId,
                'business_id' => $businessId,
                'role_work' => $this->requiredText(
                    $details,
                    'role_work',
                ),
                'hours_per_month' => $this->requiredPositiveDecimal(
                    $details,
                    'hours_per_month',
                ),
                'fair_market_rate' => $this->requiredAmount(
                    $details,
                    'fair_market_rate',
                ),
                'number_of_months' => $this->requiredPositiveInteger(
                    $details,
                    'number_of_months',
                ),
                'cash_compensation_received' => $this->optionalAmount(
                    $details,
                    'cash_compensation_received',
                ) ?? '0.00',
                'start_date' => $details['start_date'] ?? null,
                'end_date' => $details['end_date'] ?? null,
                'performance_condition' => $this->optionalText(
                    $details,
                    'performance_condition',
                ),
                'vesting_rule' => $this->optionalText(
                    $details,
                    'vesting_rule',
                ),
            ]),

            ContributionType::PropertyAsset => DB::table(
                'asset_contribution_details',
            )->insert([
                'contribution_id' => $contributionId,
                'business_id' => $businessId,
                'asset_description' => $this->requiredText(
                    $details,
                    'asset_description',
                ),
                'ownership_transferred' => $this->requiredBoolean(
                    $details,
                    'ownership_transferred',
                ),
                'usage_period' => $this->optionalText(
                    $details,
                    'usage_period',
                ),
                'market_value' => $this->optionalAmount(
                    $details,
                    'market_value',
                ),
                'fair_rental_use_value' => $this->optionalAmount(
                    $details,
                    'fair_rental_use_value',
                ),
                'valuation_method' => $this->optionalText(
                    $details,
                    'valuation_method',
                ),
            ]),

            ContributionType::IpIntangible => DB::table(
                'intangible_contribution_details',
            )->insert([
                'contribution_id' => $contributionId,
                'business_id' => $businessId,
                'intangible_kind' => $this->requiredText(
                    $details,
                    'intangible_kind',
                ),
                'intangible_description' => $this->requiredText(
                    $details,
                    'intangible_description',
                ),
                'legal_beneficial_owner' => $this->requiredText(
                    $details,
                    'legal_beneficial_owner',
                ),
                'contribution_form' => $this->requiredText(
                    $details,
                    'contribution_form',
                ),
                'contribution_period' => $this->optionalText(
                    $details,
                    'contribution_period',
                ),
                'valuation_method' => $this->requiredText(
                    $details,
                    'valuation_method',
                ),
            ]),
        };
    }

    private function appendStatusTransition(
        Business $business,
        string $contributionId,
        mixed $membershipId,
        ?ContributionStatus $from,
        ContributionStatus $to,
        ?string $note,
    ): void {
        DB::table('contribution_status_transitions')->insert([
            'id' => (string) Str::uuid7(),
            'business_id' => $business->getKey(),
            'contribution_id' => $contributionId,
            'from_status' => $from?->value,
            'to_status' => $to->value,
            'changed_by_membership_id' => $membershipId,
            'note' => $this->nullableTrim($note),
            'changed_at' => now(),
        ]);
    }

    /**
     * @param  list<string>  $evidenceIds
     * @return array<string, mixed>
     */
    private function snapshotPayload(
        Business $business,
        object $row,
        array $evidenceIds,
        int $deliveredTotalMinor,
        string $phase,
        ?ContributionValue $proposedAcceptedValue,
    ): array {
        $details = match ((string) $row->contribution_type) {
            ContributionType::Cash->value => DB::table('cash_contribution_details')
                ->where('contribution_id', $row->id)
                ->first(),

            ContributionType::TimeSkill->value => DB::table('time_skill_contribution_details')
                ->where('contribution_id', $row->id)
                ->first(),

            ContributionType::PropertyAsset->value => DB::table('asset_contribution_details')
                ->where('contribution_id', $row->id)
                ->first(),

            ContributionType::IpIntangible->value => DB::table('intangible_contribution_details')
                ->where('contribution_id', $row->id)
                ->first(),

            default => null,
        };

        $detailPayload = $details === null
            ? null
            : collect((array) $details)
                ->except(['business_id'])
                ->map(
                    static fn (mixed $value): mixed => $value instanceof DateTimeInterface
                            ? $value->format(DATE_ATOM)
                            : $value,
                )
                ->all();

        return [
            'business_id' => (string) $business->getKey(),
            'contribution_id' => (string) $row->id,
            'contribution_revision' => (int) $row->revision,
            'phase' => $phase,
            'partner_id' => (string) $row->partner_id,
            'contribution_type' => (string) $row->contribution_type,
            'currency' => (string) $row->currency,
            'description' => (string) $row->description,
            'proposed_value' => (string) $row->proposed_value,
            'reviewed_value' => $row->reviewed_value === null
                    ? null
                    : (string) $row->reviewed_value,
            'approved_value' => $row->approved_value === null
                    ? null
                    : (string) $row->approved_value,
            'proposed_accepted_value' => $proposedAcceptedValue?->amount,
            'delivered_total_minor_units' => $deliveredTotalMinor,
            'valuation_method' => $row->valuation_method,
            'conditions' => $row->conditions,
            'committed_date' => $row->committed_date,
            'due_date' => $row->due_date,
            'details' => $detailPayload,
            'evidence_ids' => $evidenceIds,
        ];
    }

    private function deliveredTotalMinorUnits(
        Business $business,
        string $contributionId,
    ): int {
        $values = DB::table('contribution_delivery_events')
            ->where('business_id', $business->getKey())
            ->where('contribution_id', $contributionId)
            ->pluck('delivered_value');

        return $values->sum(
            static fn (mixed $value): int => (new ContributionValue((string) $value))
                ->minorUnits(),
        );
    }

    private function requiredAmount(
        array $details,
        string $key,
    ): string {
        if (! array_key_exists($key, $details)) {
            throw new InvalidArgumentException(
                "Missing Contribution detail: {$key}.",
            );
        }

        return (new ContributionValue(
            (string) $details[$key],
        ))->amount;
    }

    private function optionalAmount(
        array $details,
        string $key,
    ): ?string {
        if (
            ! array_key_exists($key, $details)
            || $details[$key] === null
            || trim((string) $details[$key]) === ''
        ) {
            return null;
        }

        return (new ContributionValue(
            (string) $details[$key],
        ))->amount;
    }

    private function requiredText(
        array $details,
        string $key,
    ): string {
        $value = trim((string) ($details[$key] ?? ''));

        if ($value === '') {
            throw new InvalidArgumentException(
                "Missing Contribution detail: {$key}.",
            );
        }

        return $value;
    }

    private function optionalText(
        array $details,
        string $key,
    ): ?string {
        $value = trim((string) ($details[$key] ?? ''));

        return $value === '' ? null : $value;
    }

    private function requiredPositiveInteger(
        array $details,
        string $key,
    ): int {
        $value = filter_var(
            $details[$key] ?? null,
            FILTER_VALIDATE_INT,
        );

        if ($value === false || $value <= 0) {
            throw new InvalidArgumentException(
                "{$key} must be a positive integer.",
            );
        }

        return $value;
    }

    private function requiredPositiveDecimal(
        array $details,
        string $key,
    ): string {
        $value = trim((string) ($details[$key] ?? ''));

        if (
            preg_match(
                '/\A(?:0|[1-9][0-9]{0,7})(?:\.[0-9]{1,2})?\z/',
                $value,
            ) !== 1
        ) {
            throw new InvalidArgumentException(
                "{$key} must use fixed two-decimal precision.",
            );
        }

        [$whole, $fraction] = array_pad(
            explode('.', $value, 2),
            2,
            '',
        );

        $fraction = str_pad($fraction, 2, '0');

        if (
            (int) $whole === 0
            && (int) $fraction === 0
        ) {
            throw new InvalidArgumentException(
                "{$key} must be greater than zero.",
            );
        }

        return $whole.'.'.$fraction;
    }

    private function requiredBoolean(
        array $details,
        string $key,
    ): bool {
        if (! array_key_exists($key, $details)) {
            throw new InvalidArgumentException(
                "Missing Contribution detail: {$key}.",
            );
        }

        $value = filter_var(
            $details[$key],
            FILTER_VALIDATE_BOOL,
            FILTER_NULL_ON_FAILURE,
        );

        if ($value === null) {
            throw new InvalidArgumentException(
                "{$key} must be boolean.",
            );
        }

        return $value;
    }

    private function nullableTrim(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $value = trim($value);

        return $value === '' ? null : $value;
    }
}
