<?php

declare(strict_types=1);

namespace App\Application\Partnership;

use App\Application\Governance\MakeGovernedRecordEffective;
use App\Application\Governance\PrepareGovernedRecordForEffect;
use App\Application\Records\CreateDraftRecordVersion;
use App\Application\Records\CreateFormalRecordFamily;
use App\Application\Records\CreateProposal;
use App\Application\Records\FreezeProposalVersion;
use App\Application\Records\SubmitRecordVersionForReview;
use App\Application\Records\TransitionFormalRecordVersion;
use App\Domain\Access\CapabilityCatalog;
use App\Domain\Access\ValueObjects\Capability;
use App\Domain\Partnership\Enums\OwnershipScenarioStatus;
use App\Domain\Records\Enums\FormalRecordState;
use App\Domain\Records\ValueObjects\RecordScope;
use App\Infrastructure\Persistence\Eloquent\Businesses\Business;
use App\Infrastructure\Persistence\Eloquent\Identity\User;
use Carbon\CarbonImmutable;
use DateTimeInterface;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;
use RuntimeException;

final class OwnershipGovernanceWorkflow
{
    public function __construct(
        private readonly PartnershipActorContext $actor,
        private readonly PartnershipOccurrence $occurrence,
        private readonly CreateFormalRecordFamily $createFamily,
        private readonly CreateDraftRecordVersion $createDraft,
        private readonly SubmitRecordVersionForReview $submitForReview,
        private readonly TransitionFormalRecordVersion $transitionRecord,
        private readonly CreateProposal $createProposal,
        private readonly FreezeProposalVersion $freezeProposal,
        private readonly PrepareGovernedRecordForEffect $prepareForEffect,
        private readonly MakeGovernedRecordEffective $makeEffective,
    ) {}

    /**
     * Promote an exact Frozen Ownership Scenario into an exact frozen F2
     * Proposal Version. This method never creates Effective Ownership truth.
     *
     * @return array{
     *     id:string,
     *     formal_record_version_id:string,
     *     proposal_version_id:string
     * }|null
     */
    public function submitGovernance(
        User $user,
        Business $business,
        string $scenarioId,
        DateTimeInterface $effectiveFrom,
        ?DateTimeInterface $effectiveUntil = null,
    ): ?array {
        $membership = $this->actor->membership(
            $user,
            $business,
            CapabilityCatalog::OWNERSHIP_MANAGE,
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

        if (
            $effectiveUntil !== null
            && $effectiveUntil <= $effectiveFrom
        ) {
            throw new InvalidArgumentException(
                'Ownership Effective Until must be later than Effective From.',
            );
        }

        return DB::transaction(function () use (
            $user,
            $business,
            $scenarioId,
            $effectiveFrom,
            $effectiveUntil,
            $membership,
        ): ?array {
            $businessId = (string) $business->getKey();

            $scenario = DB::table('ownership_scenarios')
                ->where('id', $scenarioId)
                ->where('business_id', $businessId)
                ->lockForUpdate()
                ->first();

            if (
                $scenario === null
                || $scenario->status
                    !== OwnershipScenarioStatus::Frozen->value
            ) {
                return null;
            }

            $payload = $this->snapshotPayload(
                $businessId,
                $scenarioId,
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
                'ownership_governance_submissions',
            )
                ->where('business_id', $businessId)
                ->where('ownership_scenario_id', $scenarioId)
                ->where(
                    'scenario_revision',
                    $scenario->revision,
                )
                ->first();

            if ($existing !== null) {
                return [
                    'id' => (string) $existing->id,
                    'formal_record_version_id' => (string) $existing->formal_record_version_id,
                    'proposal_version_id' => (string) $existing->proposal_version_id,
                ];
            }

            $latestSubmission = DB::table(
                'ownership_governance_submissions',
            )
                ->where('business_id', $businessId)
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
                        'ownership_register',
                        'business',
                        $businessId,
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
                    'Ownership proposal from Frozen Scenario %s revision %d.',
                    $scenarioId,
                    (int) $scenario->revision,
                ),
                $effectiveFrom,
                $effectiveUntil,
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
                'ownership_governance_submissions',
            )->insert([
                'id' => $submissionId,
                'business_id' => $businessId,
                'ownership_scenario_id' => $scenarioId,
                'scenario_revision' => $scenario->revision,
                'formal_record_family_id' => $familyId,
                'formal_record_version_id' => $recordVersion->getKey(),
                'proposal_id' => $proposal->getKey(),
                'proposal_version_id' => $proposalVersion->getKey(),
                'content_hash' => $contentHash,
                'effective_from' => $effectiveFrom,
                'effective_until' => $effectiveUntil,
                'created_by_membership_id' => $membership->getKey(),
                'effective_register_version_id' => null,
                'created_at' => now(),
            ]);

            $this->occurrence->record(
                $user,
                $business,
                'partnership.ownership.governance_submitted',
                'ownership_scenario',
                $scenarioId,
                [
                    'scenario_revision' => (int) $scenario->revision,
                    'proposal_version_id' => (string) $proposalVersion->getKey(),
                    'formal_record_version_id' => (string) $recordVersion->getKey(),
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
                'Ownership content review may only enter Under Review or Approved.',
            );
        }

        if (
            ! $this->actor->allows(
                $user,
                $business,
                CapabilityCatalog::OWNERSHIP_MANAGE,
            )
            || ! $this->actor->allows(
                $user,
                $business,
                CapabilityCatalog::RECORDS_MANAGE,
            )
        ) {
            return null;
        }

        $submission = DB::table(
            'ownership_governance_submissions',
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
     * Synchronize an already-authorized F3 Ownership Decision into canonical
     * Ownership truth.
     *
     * This method does not create, approve, vote, sign or resolve a Decision.
     */
    public function effectApprovedGovernance(
        User $user,
        Business $business,
        string $submissionId,
    ): ?bool {
        $membership = $this->actor->membership(
            $user,
            $business,
            CapabilityCatalog::OWNERSHIP_MANAGE,
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
            $businessId = (string) $business->getKey();

            $submission = DB::table(
                'ownership_governance_submissions',
            )
                ->where('business_id', $businessId)
                ->where('id', $submissionId)
                ->lockForUpdate()
                ->first();

            if ($submission === null) {
                return null;
            }

            if (
                $submission->effective_register_version_id
                !== null
            ) {
                return true;
            }

            $decision = DB::table('decisions')
                ->where('business_id', $businessId)
                ->where(
                    'proposal_version_id',
                    $submission->proposal_version_id,
                )
                ->where(
                    'decision_type',
                    'ownership_approval',
                )
                ->where('status', 'decided')
                ->where('outcome', 'approved')
                ->first();

            if ($decision === null) {
                return false;
            }

            $scenario = DB::table('ownership_scenarios')
                ->where('business_id', $businessId)
                ->where(
                    'id',
                    $submission->ownership_scenario_id,
                )
                ->lockForUpdate()
                ->first();

            if (
                $scenario === null
                || $scenario->status
                    !== OwnershipScenarioStatus::Frozen->value
                || (int) $scenario->revision
                    !== (int) $submission->scenario_revision
            ) {
                throw new RuntimeException(
                    'Frozen Ownership Scenario no longer matches its governance submission.',
                );
            }

            $payload = $this->snapshotPayload(
                $businessId,
                (string) $scenario->id,
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

            if (
                ! hash_equals(
                    (string) $submission->content_hash,
                    $contentHash,
                )
            ) {
                throw new RuntimeException(
                    'Frozen Ownership Scenario snapshot no longer matches its Proposal.',
                );
            }

            $authoritySnapshot = DB::table(
                'authority_snapshots',
            )
                ->where('business_id', $businessId)
                ->where('id', $decision->authority_snapshot_id)
                ->where(
                    'proposal_version_id',
                    $submission->proposal_version_id,
                )
                ->first();

            if ($authoritySnapshot === null) {
                throw new RuntimeException(
                    'Ownership Decision Authority Snapshot binding is invalid.',
                );
            }

            $latestRecordState = DB::table(
                'record_version_state_transitions',
            )
                ->where(
                    'formal_record_version_id',
                    $submission->formal_record_version_id,
                )
                ->orderByDesc('sequence')
                ->value('to_state');

            if (
                $latestRecordState
                === FormalRecordState::Effective->value
            ) {
                throw new RuntimeException(
                    'Ownership formal record is already Effective without a bound Ownership Register Version.',
                );
            }

            if (
                $latestRecordState
                !== FormalRecordState::ReadyForEffect->value
            ) {
                $prepared = $this->prepareForEffect->execute(
                    $user,
                    $business,
                    (string) $decision->id,
                    (string) $submission->formal_record_version_id,
                );

                if (! $prepared) {
                    return false;
                }
            }

            $formalRecordVersion = DB::table(
                'formal_record_versions',
            )
                ->where('business_id', $businessId)
                ->where(
                    'id',
                    $submission->formal_record_version_id,
                )
                ->first();

            if (
                $formalRecordVersion === null
                || $formalRecordVersion->effective_from === null
            ) {
                throw new RuntimeException(
                    'Ownership formal record is missing its Effective From date.',
                );
            }

            if (
                CarbonImmutable::parse(
                    (string) $formalRecordVersion->effective_from,
                )->isFuture()
            ) {
                return false;
            }

            if ($decision->resolved_at === null) {
                throw new RuntimeException(
                    'Approved Ownership Decision is missing its resolution timestamp.',
                );
            }

            $register = DB::table('ownership_registers')
                ->where('business_id', $businessId)
                ->lockForUpdate()
                ->first();

            if ($register === null) {
                $registerId = (string) Str::uuid7();

                DB::table('ownership_registers')->insert([
                    'id' => $registerId,
                    'business_id' => $businessId,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            } else {
                $registerId = (string) $register->id;
            }

            $nextVersion = (
                (int) DB::table('ownership_register_versions')
                    ->where(
                        'ownership_register_id',
                        $registerId,
                    )
                    ->max('version_number')
            ) + 1;

            $capacity = DB::selectOne(
                <<<'SQL'
SELECT
    s.authorized_shares::text AS authorized_shares,
    COALESCE(SUM(p.shares_issued), 0)::text
        AS issued_shares,
    s.reserved_unissued_shares::text
        AS reserved_unissued_shares,
    (
        s.authorized_shares
        - COALESCE(SUM(p.shares_issued), 0)
        - s.reserved_unissued_shares
    )::text AS available_shares
FROM ownership_scenarios s
LEFT JOIN ownership_scenario_positions p
       ON p.ownership_scenario_id = s.id
      AND p.business_id = s.business_id
WHERE s.business_id = ?
  AND s.id = ?
GROUP BY
    s.authorized_shares,
    s.reserved_unissued_shares
SQL,
                [
                    $businessId,
                    $scenario->id,
                ],
            );

            if ($capacity === null) {
                throw new RuntimeException(
                    'Ownership Scenario capacity snapshot is unavailable.',
                );
            }

            $signedAt = null;

            if ((bool) $authoritySnapshot->signature_required) {
                $signedAt = DB::table('signature_requests')
                    ->where('business_id', $businessId)
                    ->where('decision_id', $decision->id)
                    ->where('status', 'completed')
                    ->max('completed_at');

                if ($signedAt === null) {
                    return false;
                }
            }

            $registerVersionId = (string) Str::uuid7();

            DB::table('ownership_register_versions')->insert([
                'id' => $registerVersionId,
                'business_id' => $businessId,
                'ownership_register_id' => $registerId,
                'version_number' => $nextVersion,
                'source_ownership_scenario_id' => $scenario->id,
                'proposal_version_id' => $submission->proposal_version_id,
                'governance_decision_id' => $decision->id,
                'currency' => $scenario->currency,
                'share_value_minor_units' => $scenario->share_value_minor_units,
                'authorized_shares' => $capacity->authorized_shares,
                'issued_shares' => $capacity->issued_shares,
                'reserved_unissued_shares' => $capacity->reserved_unissued_shares,
                'available_shares' => $capacity->available_shares,
                'status' => 'pending_effect',
                'approved_at' => $decision->resolved_at,
                'signed_at' => $signedAt,
                'effective_from' => $formalRecordVersion->effective_from,
                'effective_until' => $formalRecordVersion->effective_until,
                'authority_snapshot_id' => $decision->authority_snapshot_id,
                'created_by_membership_id' => $membership->getKey(),
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            $classMap = [];

            $classes = DB::table(
                'ownership_scenario_share_classes',
            )
                ->where('business_id', $businessId)
                ->where(
                    'ownership_scenario_id',
                    $scenario->id,
                )
                ->orderBy('id')
                ->get();

            foreach ($classes as $class) {
                $newClassId = (string) Str::uuid7();

                $classMap[(string) $class->id] =
                    $newClassId;

                DB::table(
                    'ownership_register_share_classes',
                )->insert([
                    'id' => $newClassId,
                    'business_id' => $businessId,
                    'ownership_register_version_id' => $registerVersionId,
                    'name' => $class->name,
                    'voting_right_per_share' => $class->voting_right_per_share,
                    'profit_right_per_share' => $class->profit_right_per_share,
                    'transfer_allowed' => $class->transfer_allowed,
                    'restrictions' => $class->restrictions,
                    'special_rights' => $class->special_rights,
                ]);
            }

            $positions = DB::table(
                'ownership_scenario_positions',
            )
                ->where('business_id', $businessId)
                ->where(
                    'ownership_scenario_id',
                    $scenario->id,
                )
                ->orderBy('id')
                ->get();

            foreach ($positions as $position) {
                $registerClassId =
                    $classMap[
                        (string) $position->share_class_id
                    ] ?? null;

                if ($registerClassId === null) {
                    throw new RuntimeException(
                        'Ownership Share Class snapshot mapping is incomplete.',
                    );
                }

                DB::table(
                    'ownership_register_positions',
                )->insert([
                    'id' => (string) Str::uuid7(),
                    'business_id' => $businessId,
                    'ownership_register_version_id' => $registerVersionId,
                    'partner_id' => $position->partner_id,
                    'share_class_id' => $registerClassId,
                    'accepted_contribution_minor_units' => $position->accepted_contribution_minor_units,
                    'shares_issued' => $position->shares_issued,
                    'shares_vested' => $position->shares_vested,
                    'voting_rights' => $position->voting_rights,
                    'profit_rights' => $position->profit_rights,
                    'issue_date' => $position->issue_date,
                    'vesting_start_date' => $position->vesting_start_date,
                    'vesting_period_months' => $position->vesting_period_months,
                    'vesting_cliff_months' => $position->vesting_cliff_months,
                    'vesting_conditions' => $position->vesting_conditions,
                    'early_exit_treatment' => $position->early_exit_treatment,
                ]);
            }

            $sources = DB::table(
                'ownership_scenario_contribution_sources',
            )
                ->where('business_id', $businessId)
                ->where(
                    'ownership_scenario_id',
                    $scenario->id,
                )
                ->orderBy('id')
                ->get();

            foreach ($sources as $source) {
                DB::table(
                    'ownership_register_contribution_sources',
                )->insert([
                    'id' => (string) Str::uuid7(),
                    'business_id' => $businessId,
                    'ownership_register_version_id' => $registerVersionId,
                    'contribution_id' => $source->contribution_id,
                    'partner_id' => $source->partner_id,
                    'contribution_revision' => $source->contribution_revision,
                    'currency' => $source->currency,
                    'accepted_value_minor_units' => $source->accepted_value_minor_units,
                ]);
            }

            $madeEffective = $this->makeEffective->execute(
                $user,
                $business,
                (string) $decision->id,
                (string) $submission->formal_record_version_id,
            );

            if (! $madeEffective) {
                throw new RuntimeException(
                    'Governed Ownership record could not become Effective.',
                );
            }

            $previous = DB::table(
                'ownership_register_versions',
            )
                ->where('business_id', $businessId)
                ->where('status', 'effective')
                ->where('id', '<>', $registerVersionId)
                ->lockForUpdate()
                ->first();

            if ($previous !== null) {
                DB::table('ownership_register_versions')
                    ->where('id', $previous->id)
                    ->where('business_id', $businessId)
                    ->update([
                        'status' => 'superseded',
                        'effective_until' => $formalRecordVersion->effective_from,
                        'updated_at' => now(),
                    ]);
            }

            DB::table('ownership_register_versions')
                ->where('id', $registerVersionId)
                ->where('business_id', $businessId)
                ->update([
                    'status' => 'effective',
                    'updated_at' => now(),
                ]);

            DB::table(
                'ownership_governance_submissions',
            )
                ->where('id', $submission->id)
                ->where('business_id', $businessId)
                ->update([
                    'effective_register_version_id' => $registerVersionId,
                ]);

            $this->occurrence->record(
                $user,
                $business,
                'partnership.ownership.effective',
                'ownership_register_version',
                $registerVersionId,
                [
                    'scenario_id' => (string) $scenario->id,
                    'proposal_version_id' => (string) $submission->proposal_version_id,
                    'decision_id' => (string) $decision->id,
                    'authority_snapshot_id' => (string) $decision->authority_snapshot_id,
                    'version_number' => $nextVersion,
                ],
            );

            return true;
        });
    }

    /**
     * @return array<string, mixed>
     */
    private function snapshotPayload(
        string $businessId,
        string $scenarioId,
    ): array {
        $scenario = DB::table('ownership_scenarios')
            ->where('business_id', $businessId)
            ->where('id', $scenarioId)
            ->first();

        if ($scenario === null) {
            throw new RuntimeException(
                'Ownership Scenario snapshot source is unavailable.',
            );
        }

        $classes = DB::table(
            'ownership_scenario_share_classes',
        )
            ->where('business_id', $businessId)
            ->where(
                'ownership_scenario_id',
                $scenarioId,
            )
            ->orderBy('id')
            ->get()
            ->map(
                static fn (object $row): array => (array) $row,
            )
            ->all();

        $positions = DB::table(
            'ownership_scenario_positions',
        )
            ->where('business_id', $businessId)
            ->where(
                'ownership_scenario_id',
                $scenarioId,
            )
            ->orderBy('id')
            ->get()
            ->map(
                static fn (object $row): array => (array) $row,
            )
            ->all();

        $sources = DB::table(
            'ownership_scenario_contribution_sources',
        )
            ->where('business_id', $businessId)
            ->where(
                'ownership_scenario_id',
                $scenarioId,
            )
            ->orderBy('id')
            ->get()
            ->map(
                static fn (object $row): array => (array) $row,
            )
            ->all();

        return [
            'business_id' => $businessId,
            'scenario' => (array) $scenario,
            'share_classes' => $classes,
            'positions' => $positions,
            'accepted_contribution_sources' => $sources,
        ];
    }
}
