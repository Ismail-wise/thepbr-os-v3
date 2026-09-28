<?php

declare(strict_types=1);

namespace App\Application\Partnership;

use App\Application\Access\AuthorizeBusinessCapability;
use App\Application\PartnerChanges\RecordPartnerChangeOccurrence;
use App\Domain\Access\CapabilityCatalog;
use App\Domain\Access\ValueObjects\Capability;
use App\Domain\Partnership\ValueObjects\ShareQuantity;
use App\Infrastructure\Persistence\Eloquent\Businesses\Business;
use App\Infrastructure\Persistence\Eloquent\Identity\User;
use App\Infrastructure\Persistence\Eloquent\PartnerChanges\PartnerChangeCase;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;
use RuntimeException;

final class OwnershipTransferWorkflow
{
    public function __construct(
        private readonly PartnershipActorContext $actor,
        private readonly AuthorizeBusinessCapability $authorize,
        private readonly RecordPartnerChangeOccurrence $occurrence,
    ) {}

    public function effectExistingShareTransfer(
        User $user,
        Business $business,
        string $partnerChangeCaseId,
        string $sourceRegisterVersionId,
        string $sellerPartnerId,
        string $buyerPartnerId,
        string $sourceShareClassId,
        ShareQuantity $shares,
        string $proposalVersionId,
        string $decisionId,
        CarbonImmutable $effectiveFrom,
    ): ?string {
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
                CapabilityCatalog::PARTNER_CHANGES_MANAGE,
            )
        ) {
            return null;
        }

        if ($sellerPartnerId === $buyerPartnerId) {
            throw new InvalidArgumentException(
                'Seller and buyer must be different Partners.',
            );
        }

        $businessId = (string) $business->getKey();

        return DB::transaction(function () use (
            $user,
            $business,
            $businessId,
            $partnerChangeCaseId,
            $sourceRegisterVersionId,
            $sellerPartnerId,
            $buyerPartnerId,
            $sourceShareClassId,
            $shares,
            $proposalVersionId,
            $decisionId,
            $effectiveFrom,
            $membership,
        ): string {
            $case = PartnerChangeCase::query()
                ->where('business_id', $businessId)
                ->whereKey($partnerChangeCaseId)
                ->where('transaction_type', 'transfer_existing')
                ->where('status', 'ready_for_effect')
                ->lockForUpdate()
                ->first();

            if (
                $case === null
                || (string) $case->source_ownership_register_version_id
                    !== $sourceRegisterVersionId
                || (string) $case->seller_partner_id !== $sellerPartnerId
                || (string) $case->buyer_partner_id !== $buyerPartnerId
                || (string) $case->source_share_class_id !== $sourceShareClassId
                || (string) $case->shares !== $shares->value()
            ) {
                throw new InvalidArgumentException(
                    'Ownership transfer does not match the exact Ready for Effect Partner Change Case.',
                );
            }

            $caseAccess = $this->authorize->decide(
                $user,
                $business,
                $business,
                new Capability(CapabilityCatalog::PARTNER_CHANGES_MANAGE),
                PartnerChangeCase::class,
                (string) $case->getKey(),
            );

            if (! $caseAccess->allowed) {
                throw new InvalidArgumentException(
                    'Partner Change Case is not available to this Membership.',
                );
            }

            if ($effectiveFrom->isFuture()) {
                throw new InvalidArgumentException(
                    'A future-dated Partner Change cannot become Effective early.',
                );
            }

            $source = DB::table('ownership_register_versions')
                ->where('business_id', $businessId)
                ->where('id', $sourceRegisterVersionId)
                ->where('status', 'effective')
                ->lockForUpdate()
                ->first();

            if ($source === null) {
                throw new InvalidArgumentException(
                    'The captured Ownership Register is no longer the current Effective register.',
                );
            }

            $shareClass = DB::table('ownership_register_share_classes')
                ->where('business_id', $businessId)
                ->where('ownership_register_version_id', $source->id)
                ->where('id', $sourceShareClassId)
                ->first();

            if ($shareClass === null || ! (bool) $shareClass->transfer_allowed) {
                throw new InvalidArgumentException(
                    'The selected Share Class is not transferable under the captured Ownership Register.',
                );
            }
            $seller = DB::table('ownership_register_positions')
                ->where('business_id', $businessId)
                ->where('ownership_register_version_id', $source->id)
                ->where('partner_id', $sellerPartnerId)
                ->where('share_class_id', $sourceShareClassId)
                ->first();

            if ($seller === null) {
                throw new InvalidArgumentException(
                    'Seller has no position in the selected Share Class.',
                );
            }

            $capacity = DB::selectOne(
                <<<'SQL'
SELECT
    CAST(? AS numeric) <= CAST(? AS numeric) AS issued_ok,
    CAST(? AS numeric) <= CAST(? AS numeric) AS vested_ok
SQL,
                [
                    $shares->value(),
                    $seller->shares_issued,
                    $shares->value(),
                    $seller->shares_vested,
                ],
            );

            if (
                $capacity === null
                || ! (bool) $capacity->issued_ok
                || ! (bool) $capacity->vested_ok
            ) {
                throw new InvalidArgumentException(
                    'Only issued and vested Shares may be transferred.',
                );
            }

            $decision = DB::table('decisions')
                ->where('business_id', $businessId)
                ->where('id', $decisionId)
                ->where('proposal_version_id', $proposalVersionId)
                ->where('status', 'decided')
                ->where('outcome', 'approved')
                ->first();

            if ($decision === null) {
                throw new InvalidArgumentException(
                    'Ownership transfer requires the exact approved Governance Decision.',
                );
            }

            $registerId = (string) $source->ownership_register_id;
            $nextVersion = (int) DB::table('ownership_register_versions')
                ->where('business_id', $businessId)
                ->where('ownership_register_id', $registerId)
                ->max('version_number') + 1;

            $signedAt = DB::table('signature_requests')
                ->where('business_id', $businessId)
                ->where('decision_id', $decisionId)
                ->where('status', 'completed')
                ->max('completed_at');

            $registerVersionId = (string) Str::uuid7();

            DB::table('ownership_register_versions')->insert([
                'id' => $registerVersionId,
                'business_id' => $businessId,
                'ownership_register_id' => $registerId,
                'version_number' => $nextVersion,
                'source_ownership_scenario_id' => $source->source_ownership_scenario_id,
                'proposal_version_id' => $proposalVersionId,
                'governance_decision_id' => $decisionId,
                'currency' => $source->currency,
                'share_value_minor_units' => $source->share_value_minor_units,
                'authorized_shares' => $source->authorized_shares,
                'issued_shares' => $source->issued_shares,
                'reserved_unissued_shares' => $source->reserved_unissued_shares,
                'available_shares' => $source->available_shares,
                'status' => 'pending_effect',
                'approved_at' => $decision->resolved_at,
                'signed_at' => $signedAt,
                'effective_from' => $effectiveFrom,
                'effective_until' => null,
                'authority_snapshot_id' => $decision->authority_snapshot_id,
                'created_by_membership_id' => $membership->getKey(),
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            $submission = DB::table('partner_change_governance_submissions')
                ->where('business_id', $businessId)
                ->where('partner_change_case_id', $partnerChangeCaseId)
                ->where('proposal_version_id', $proposalVersionId)
                ->where('decision_id', $decisionId)
                ->whereNull('effective_register_version_id')
                ->lockForUpdate()
                ->first();

            if ($submission === null) {
                throw new InvalidArgumentException(
                    'Ownership transfer requires the exact governed Partner Change submission.',
                );
            }

            $proposalBindingExists = DB::table('proposal_version_records')
                ->where('business_id', $businessId)
                ->where('proposal_version_id', $proposalVersionId)
                ->where(
                    'formal_record_version_id',
                    $submission->formal_record_version_id,
                )
                ->exists();

            if (! $proposalBindingExists) {
                throw new InvalidArgumentException(
                    'Partner Change Proposal does not bind the exact Formal Record Version.',
                );
            }

            $decision = DB::table('decisions')
                ->where('business_id', $businessId)
                ->where('id', $decisionId)
                ->where('proposal_version_id', $proposalVersionId)
                ->where('status', 'decided')
                ->where('outcome', 'approved')
                ->first();

            if ($decision === null || $decision->resolved_at === null) {
                throw new InvalidArgumentException(
                    'Ownership transfer requires the exact approved Governance Decision.',
                );
            }

            $authoritySnapshot = DB::table('authority_snapshots')
                ->where('business_id', $businessId)
                ->where('id', $decision->authority_snapshot_id)
                ->where('proposal_version_id', $proposalVersionId)
                ->first();

            if ($authoritySnapshot === null) {
                throw new InvalidArgumentException(
                    'Ownership transfer Governance Authority Snapshot is unavailable.',
                );
            }

            $signedAt = null;

            if ((bool) $authoritySnapshot->signature_required) {
                $signedAt = DB::table('signature_requests')
                    ->where('business_id', $businessId)
                    ->where('decision_id', $decisionId)
                    ->where('proposal_version_id', $proposalVersionId)
                    ->where(
                        'authority_snapshot_id',
                        $decision->authority_snapshot_id,
                    )
                    ->where('status', 'completed')
                    ->max('completed_at');

                if ($signedAt === null) {
                    throw new InvalidArgumentException(
                        'Required signatures must be completed before Ownership transfer effectivity.',
                    );
                }
            }

            $partners = DB::table('partners')
                ->where('business_id', $businessId)
                ->whereIn('id', [$sellerPartnerId, $buyerPartnerId])
                ->lockForUpdate()
                ->pluck('id')
                ->map(static fn (mixed $id): string => (string) $id)
                ->all();

            if (count($partners) !== 2) {
                throw new InvalidArgumentException(
                    'Seller and buyer must both be Partners in the current Business.',
                );
            }

            $positive = DB::selectOne(
                'SELECT CAST(? AS numeric) > 0 AS valid',
                [$shares->value()],
            );

            if ($positive === null || ! (bool) $positive->valid) {
                throw new InvalidArgumentException(
                    'Transferred Shares must be greater than zero.',
                );
            }

            $classMap = [];

            $classes = DB::table('ownership_register_share_classes')
                ->where('business_id', $businessId)
                ->where('ownership_register_version_id', $source->id)
                ->orderBy('id')
                ->get();

            foreach ($classes as $class) {
                $newClassId = (string) Str::uuid7();
                $classMap[(string) $class->id] = $newClassId;

                DB::table('ownership_register_share_classes')->insert([
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

            $rightsDelta = DB::selectOne(
                <<<'SQL'
SELECT
    (CAST(? AS numeric) * CAST(? AS numeric))::text AS voting_delta,
    (CAST(? AS numeric) * CAST(? AS numeric))::text AS profit_delta,
    (
        CAST(? AS numeric) * CAST(? AS numeric)
        = ROUND(CAST(? AS numeric) * CAST(? AS numeric), 8)
    ) AS voting_exact,
    (
        CAST(? AS numeric) * CAST(? AS numeric)
        = ROUND(CAST(? AS numeric) * CAST(? AS numeric), 8)
    ) AS profit_exact
SQL,
                [
                    $shares->value(),
                    $shareClass->voting_right_per_share,
                    $shares->value(),
                    $shareClass->profit_right_per_share,
                    $shares->value(),
                    $shareClass->voting_right_per_share,
                    $shares->value(),
                    $shareClass->voting_right_per_share,
                    $shares->value(),
                    $shareClass->profit_right_per_share,
                    $shares->value(),
                    $shareClass->profit_right_per_share,
                ],
            );

            if (
                $rightsDelta === null
                || ! (bool) $rightsDelta->voting_exact
                || ! (bool) $rightsDelta->profit_exact
            ) {
                throw new InvalidArgumentException(
                    'Transfer rights exceed supported 8-decimal precision.',
                );
            }

            $positions = DB::table('ownership_register_positions')
                ->where('business_id', $businessId)
                ->where('ownership_register_version_id', $source->id)
                ->orderBy('id')
                ->get();

            $buyerSourcePosition = $positions->first(
                fn (object $position): bool => (string) $position->partner_id === $buyerPartnerId
                    && (string) $position->share_class_id === $sourceShareClassId,
            );

            foreach ($positions as $position) {
                $oldClassId = (string) $position->share_class_id;
                $newClassId = $classMap[$oldClassId] ?? null;

                if ($newClassId === null) {
                    throw new RuntimeException(
                        'Ownership Share Class snapshot mapping is incomplete.',
                    );
                }

                $isSellerSourceClass =
                    (string) $position->partner_id === $sellerPartnerId
                    && $oldClassId === $sourceShareClassId;
                $isBuyerSourceClass =
                    (string) $position->partner_id === $buyerPartnerId
                    && $oldClassId === $sourceShareClassId;

                if ($isSellerSourceClass) {
                    $adjusted = DB::selectOne(
                        <<<'SQL'
SELECT
    (CAST(? AS numeric) - CAST(? AS numeric))::text AS shares_issued,
    (CAST(? AS numeric) - CAST(? AS numeric))::text AS shares_vested,
    (CAST(? AS numeric) - CAST(? AS numeric))::text AS voting_rights,
    (CAST(? AS numeric) - CAST(? AS numeric))::text AS profit_rights
SQL,
                        [
                            $position->shares_issued,
                            $shares->value(),
                            $position->shares_vested,
                            $shares->value(),
                            $position->voting_rights,
                            $rightsDelta->voting_delta,
                            $position->profit_rights,
                            $rightsDelta->profit_delta,
                        ],
                    );

                    if ($adjusted === null) {
                        throw new RuntimeException(
                            'Seller Ownership transfer calculation failed.',
                        );
                    }

                    $this->insertPosition(
                        $businessId,
                        $registerVersionId,
                        $position,
                        $newClassId,
                        $adjusted->shares_issued,
                        $adjusted->shares_vested,
                        $adjusted->voting_rights,
                        $adjusted->profit_rights,
                    );

                    continue;
                }

                if ($isBuyerSourceClass) {
                    $adjusted = DB::selectOne(
                        <<<'SQL'
SELECT
    (CAST(? AS numeric) + CAST(? AS numeric))::text AS shares_issued,
    (CAST(? AS numeric) + CAST(? AS numeric))::text AS shares_vested,
    (CAST(? AS numeric) + CAST(? AS numeric))::text AS voting_rights,
    (CAST(? AS numeric) + CAST(? AS numeric))::text AS profit_rights
SQL,
                        [
                            $position->shares_issued,
                            $shares->value(),
                            $position->shares_vested,
                            $shares->value(),
                            $position->voting_rights,
                            $rightsDelta->voting_delta,
                            $position->profit_rights,
                            $rightsDelta->profit_delta,
                        ],
                    );

                    if ($adjusted === null) {
                        throw new RuntimeException(
                            'Buyer Ownership transfer calculation failed.',
                        );
                    }

                    $this->insertPosition(
                        $businessId,
                        $registerVersionId,
                        $position,
                        $newClassId,
                        $adjusted->shares_issued,
                        $adjusted->shares_vested,
                        $adjusted->voting_rights,
                        $adjusted->profit_rights,
                    );

                    continue;
                }

                $this->insertPosition(
                    $businessId,
                    $registerVersionId,
                    $position,
                    $newClassId,
                    (string) $position->shares_issued,
                    (string) $position->shares_vested,
                    (string) $position->voting_rights,
                    (string) $position->profit_rights,
                );
            }

            if ($buyerSourcePosition === null) {
                $newClassId = $classMap[$sourceShareClassId] ?? null;

                if ($newClassId === null) {
                    throw new RuntimeException(
                        'Transferred Share Class snapshot mapping is incomplete.',
                    );
                }

                DB::table('ownership_register_positions')->insert([
                    'id' => (string) Str::uuid7(),
                    'business_id' => $businessId,
                    'ownership_register_version_id' => $registerVersionId,
                    'partner_id' => $buyerPartnerId,
                    'share_class_id' => $newClassId,
                    'accepted_contribution_minor_units' => 0,
                    'shares_issued' => $shares->value(),
                    'shares_vested' => $shares->value(),
                    'voting_rights' => $rightsDelta->voting_delta,
                    'profit_rights' => $rightsDelta->profit_delta,
                    'issue_date' => $effectiveFrom->toDateString(),
                    'vesting_start_date' => null,
                    'vesting_period_months' => null,
                    'vesting_cliff_months' => null,
                    'vesting_conditions' => null,
                    'early_exit_treatment' => null,
                ]);
            }

            $sources = DB::table('ownership_register_contribution_sources')
                ->where('business_id', $businessId)
                ->where('ownership_register_version_id', $source->id)
                ->orderBy('id')
                ->get();

            foreach ($sources as $contributionSource) {
                DB::table('ownership_register_contribution_sources')->insert([
                    'id' => (string) Str::uuid7(),
                    'business_id' => $businessId,
                    'ownership_register_version_id' => $registerVersionId,
                    'contribution_id' => $contributionSource->contribution_id,
                    'partner_id' => $contributionSource->partner_id,
                    'contribution_revision' => $contributionSource->contribution_revision,
                    'currency' => $contributionSource->currency,
                    'accepted_value_minor_units' => $contributionSource->accepted_value_minor_units,
                ]);
            }

            DB::table('ownership_register_transfer_sources')->insert([
                'id' => (string) Str::uuid7(),
                'business_id' => $businessId,
                'ownership_register_version_id' => $registerVersionId,
                'partner_change_case_id' => $partnerChangeCaseId,
                'source_ownership_register_version_id' => $sourceRegisterVersionId,
                'seller_partner_id' => $sellerPartnerId,
                'buyer_partner_id' => $buyerPartnerId,
                'source_share_class_id' => $sourceShareClassId,
                'shares_transferred' => $shares->value(),
                'created_at' => now(),
            ]);

            DB::table('ownership_register_versions')
                ->where('id', $sourceRegisterVersionId)
                ->where('business_id', $businessId)
                ->where('status', 'effective')
                ->update([
                    'status' => 'superseded',
                    'effective_until' => $effectiveFrom,
                    'updated_at' => now(),
                ]);

            DB::table('ownership_register_versions')
                ->where('id', $registerVersionId)
                ->where('business_id', $businessId)
                ->where('status', 'pending_effect')
                ->update([
                    'status' => 'effective',
                    'updated_at' => now(),
                ]);

            DB::table('partner_change_governance_submissions')
                ->where('id', $submission->id)
                ->where('business_id', $businessId)
                ->update([
                    'effective_register_version_id' => $registerVersionId,
                    'effected_at' => now(),
                ]);

            $this->occurrence->record(
                $user,
                $business,
                'partner_change.ownership_transfer.effective',
                'partner_change_case',
                $partnerChangeCaseId,
                [
                    'source_register_version_id' => $sourceRegisterVersionId,
                    'effective_register_version_id' => $registerVersionId,
                    'proposal_version_id' => $proposalVersionId,
                    'decision_id' => $decisionId,
                ],
            );

            return $registerVersionId;
        });
    }

    public function effectNewShareIssue(
        User $user,
        Business $business,
        string $partnerChangeCaseId,
        string $sourceRegisterVersionId,
        string $buyerPartnerId,
        string $sourceShareClassId,
        ShareQuantity $shares,
        string $proposalVersionId,
        string $decisionId,
        CarbonImmutable $effectiveFrom,
    ): ?string {
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
                CapabilityCatalog::PARTNER_CHANGES_MANAGE,
            )
        ) {
            return null;
        }

        if ($effectiveFrom->isFuture()) {
            throw new InvalidArgumentException(
                'A future-dated Partner Change cannot become Effective early.',
            );
        }

        $businessId = (string) $business->getKey();

        return DB::transaction(function () use (
            $user,
            $business,
            $businessId,
            $partnerChangeCaseId,
            $sourceRegisterVersionId,
            $buyerPartnerId,
            $sourceShareClassId,
            $shares,
            $proposalVersionId,
            $decisionId,
            $effectiveFrom,
            $membership,
        ): string {
            $case = PartnerChangeCase::query()
                ->where('business_id', $businessId)
                ->whereKey($partnerChangeCaseId)
                ->where('transaction_type', 'issue_new')
                ->where('status', 'ready_for_effect')
                ->lockForUpdate()
                ->first();

            if (
                $case === null
                || (string) $case->source_ownership_register_version_id
                    !== $sourceRegisterVersionId
                || (string) $case->buyer_partner_id !== $buyerPartnerId
                || (string) $case->source_share_class_id !== $sourceShareClassId
                || (string) $case->shares !== $shares->value()
            ) {
                throw new InvalidArgumentException(
                    'New-share issue does not match the exact Ready for Effect Partner Change Case.',
                );
            }

            $caseAccess = $this->authorize->decide(
                $user,
                $business,
                $business,
                new Capability(CapabilityCatalog::PARTNER_CHANGES_MANAGE),
                PartnerChangeCase::class,
                (string) $case->getKey(),
            );

            if (! $caseAccess->allowed) {
                throw new InvalidArgumentException(
                    'Partner Change Case is not available to this Membership.',
                );
            }

            $source = DB::table('ownership_register_versions')
                ->where('business_id', $businessId)
                ->where('id', $sourceRegisterVersionId)
                ->where('status', 'effective')
                ->lockForUpdate()
                ->first();

            if ($source === null) {
                throw new InvalidArgumentException(
                    'The captured Ownership Register is no longer current.',
                );
            }

            $sourceClass = DB::table('ownership_register_share_classes')
                ->where('business_id', $businessId)
                ->where('ownership_register_version_id', $sourceRegisterVersionId)
                ->where('id', $sourceShareClassId)
                ->first();

            if ($sourceClass === null) {
                throw new InvalidArgumentException(
                    'The captured Share Class no longer exists.',
                );
            }

            $requirement = DB::table('partner_change_requirements')
                ->where('business_id', $businessId)
                ->where('partner_change_case_id', $partnerChangeCaseId)
                ->where('requirement_type', 'ownership')
                ->where('requirement_key', 'ownership_scenario_frozen')
                ->where('status', 'met')
                ->where('source_type', 'ownership_scenario')
                ->whereNotNull('source_id')
                ->orderByDesc('recorded_at')
                ->first();

            if ($requirement === null) {
                throw new InvalidArgumentException(
                    'New-share issue requires a captured Frozen Ownership Scenario.',
                );
            }

            $scenario = DB::table('ownership_scenarios')
                ->where('business_id', $businessId)
                ->where('id', $requirement->source_id)
                ->where('status', 'frozen')
                ->lockForUpdate()
                ->first();

            if ($scenario === null) {
                throw new InvalidArgumentException(
                    'The required Frozen Ownership Scenario is unavailable.',
                );
            }

            $scenarioClass = DB::table('ownership_scenario_share_classes')
                ->where('business_id', $businessId)
                ->where('ownership_scenario_id', $scenario->id)
                ->where('name', $sourceClass->name)
                ->first();

            if ($scenarioClass === null) {
                throw new InvalidArgumentException(
                    'Frozen Ownership Scenario does not preserve the issued Share Class.',
                );
            }

            $sourceBuyerShares = DB::table('ownership_register_positions')
                ->where('business_id', $businessId)
                ->where('ownership_register_version_id', $sourceRegisterVersionId)
                ->where('partner_id', $buyerPartnerId)
                ->where('share_class_id', $sourceShareClassId)
                ->value('shares_issued') ?? '0';

            $scenarioBuyerShares = DB::table('ownership_scenario_positions')
                ->where('business_id', $businessId)
                ->where('ownership_scenario_id', $scenario->id)
                ->where('partner_id', $buyerPartnerId)
                ->where('share_class_id', $scenarioClass->id)
                ->value('shares_issued');

            $delta = DB::selectOne(
                <<<'SQL'
SELECT
    CAST(? AS numeric) - CAST(? AS numeric) = CAST(? AS numeric)
        AS buyer_delta_matches,
    CAST(? AS numeric) > 0 AS positive
SQL,
                [
                    $scenarioBuyerShares ?? '0',
                    $sourceBuyerShares,
                    $shares->value(),
                    $shares->value(),
                ],
            );

            if (
                $scenarioBuyerShares === null
                || $delta === null
                || ! (bool) $delta->buyer_delta_matches
                || ! (bool) $delta->positive
            ) {
                throw new InvalidArgumentException(
                    'Frozen Ownership Scenario must issue the exact approved Share quantity to the Buyer.',
                );
            }

            $submission = DB::table('partner_change_governance_submissions')
                ->where('business_id', $businessId)
                ->where('partner_change_case_id', $partnerChangeCaseId)
                ->where('proposal_version_id', $proposalVersionId)
                ->where('decision_id', $decisionId)
                ->whereNull('effective_register_version_id')
                ->lockForUpdate()
                ->first();

            if ($submission === null) {
                throw new InvalidArgumentException(
                    'New-share issue requires the exact governed Partner Change submission.',
                );
            }

            $decision = DB::table('decisions')
                ->where('business_id', $businessId)
                ->where('id', $decisionId)
                ->where('proposal_version_id', $proposalVersionId)
                ->where('status', 'decided')
                ->where('outcome', 'approved')
                ->first();

            if ($decision === null || $decision->resolved_at === null) {
                throw new InvalidArgumentException(
                    'New-share issue requires the exact approved Governance Decision.',
                );
            }

            $authoritySnapshot = DB::table('authority_snapshots')
                ->where('business_id', $businessId)
                ->where('id', $decision->authority_snapshot_id)
                ->where('proposal_version_id', $proposalVersionId)
                ->first();

            if ($authoritySnapshot === null) {
                throw new InvalidArgumentException(
                    'New-share issue Governance Authority Snapshot is unavailable.',
                );
            }

            $signedAt = null;

            if ((bool) $authoritySnapshot->signature_required) {
                $signedAt = DB::table('signature_requests')
                    ->where('business_id', $businessId)
                    ->where('decision_id', $decisionId)
                    ->where('proposal_version_id', $proposalVersionId)
                    ->where('authority_snapshot_id', $decision->authority_snapshot_id)
                    ->where('status', 'completed')
                    ->max('completed_at');

                if ($signedAt === null) {
                    throw new InvalidArgumentException(
                        'Required signatures must be completed before new-share effectivity.',
                    );
                }
            }

            $registerId = (string) $source->ownership_register_id;
            $nextVersion = ((int) DB::table('ownership_register_versions')
                ->where('business_id', $businessId)
                ->where('ownership_register_id', $registerId)
                ->max('version_number')) + 1;

            $capacity = DB::selectOne(
                <<<'SQL'
SELECT
    s.authorized_shares::text AS authorized_shares,
    COALESCE(SUM(p.shares_issued), 0)::text AS issued_shares,
    s.reserved_unissued_shares::text AS reserved_unissued_shares,
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
GROUP BY s.authorized_shares, s.reserved_unissued_shares
SQL,
                [$businessId, $scenario->id],
            );

            if ($capacity === null) {
                throw new RuntimeException(
                    'Frozen Ownership Scenario capacity is unavailable.',
                );
            }

            $registerVersionId = (string) Str::uuid7();

            DB::table('ownership_register_versions')->insert([
                'id' => $registerVersionId,
                'business_id' => $businessId,
                'ownership_register_id' => $registerId,
                'version_number' => $nextVersion,
                'source_ownership_scenario_id' => $scenario->id,
                'proposal_version_id' => $proposalVersionId,
                'governance_decision_id' => $decisionId,
                'currency' => $scenario->currency,
                'share_value_minor_units' => $scenario->share_value_minor_units,
                'authorized_shares' => $capacity->authorized_shares,
                'issued_shares' => $capacity->issued_shares,
                'reserved_unissued_shares' => $capacity->reserved_unissued_shares,
                'available_shares' => $capacity->available_shares,
                'status' => 'pending_effect',
                'approved_at' => $decision->resolved_at,
                'signed_at' => $signedAt,
                'effective_from' => $effectiveFrom,
                'effective_until' => null,
                'authority_snapshot_id' => $decision->authority_snapshot_id,
                'created_by_membership_id' => $membership->getKey(),
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            $classMap = [];

            $classes = DB::table('ownership_scenario_share_classes')
                ->where('business_id', $businessId)
                ->where('ownership_scenario_id', $scenario->id)
                ->orderBy('id')
                ->get();

            foreach ($classes as $class) {
                $newClassId = (string) Str::uuid7();
                $classMap[(string) $class->id] = $newClassId;

                DB::table('ownership_register_share_classes')->insert([
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

            $positions = DB::table('ownership_scenario_positions')
                ->where('business_id', $businessId)
                ->where('ownership_scenario_id', $scenario->id)
                ->orderBy('id')
                ->get();

            foreach ($positions as $position) {
                $newClassId = $classMap[(string) $position->share_class_id] ?? null;

                if ($newClassId === null) {
                    throw new RuntimeException(
                        'Ownership Scenario Share Class mapping is incomplete.',
                    );
                }

                DB::table('ownership_register_positions')->insert([
                    'id' => (string) Str::uuid7(),
                    'business_id' => $businessId,
                    'ownership_register_version_id' => $registerVersionId,
                    'partner_id' => $position->partner_id,
                    'share_class_id' => $newClassId,
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

            $sources = DB::table('ownership_scenario_contribution_sources')
                ->where('business_id', $businessId)
                ->where('ownership_scenario_id', $scenario->id)
                ->orderBy('id')
                ->get();

            foreach ($sources as $contributionSource) {
                DB::table('ownership_register_contribution_sources')->insert([
                    'id' => (string) Str::uuid7(),
                    'business_id' => $businessId,
                    'ownership_register_version_id' => $registerVersionId,
                    'contribution_id' => $contributionSource->contribution_id,
                    'partner_id' => $contributionSource->partner_id,
                    'contribution_revision' => $contributionSource->contribution_revision,
                    'currency' => $contributionSource->currency,
                    'accepted_value_minor_units' => $contributionSource->accepted_value_minor_units,
                ]);
            }

            DB::table('ownership_register_versions')
                ->where('id', $sourceRegisterVersionId)
                ->where('business_id', $businessId)
                ->where('status', 'effective')
                ->update([
                    'status' => 'superseded',
                    'effective_until' => $effectiveFrom,
                    'updated_at' => now(),
                ]);

            DB::table('ownership_register_versions')
                ->where('id', $registerVersionId)
                ->where('business_id', $businessId)
                ->where('status', 'pending_effect')
                ->update([
                    'status' => 'effective',
                    'updated_at' => now(),
                ]);

            DB::table('partner_change_governance_submissions')
                ->where('id', $submission->id)
                ->where('business_id', $businessId)
                ->update([
                    'effective_register_version_id' => $registerVersionId,
                    'effected_at' => now(),
                ]);

            $this->occurrence->record(
                $user,
                $business,
                'partner_change.ownership_issue.effective',
                'partner_change_case',
                $partnerChangeCaseId,
                [
                    'source_register_version_id' => $sourceRegisterVersionId,
                    'effective_register_version_id' => $registerVersionId,
                    'ownership_scenario_id' => (string) $scenario->id,
                    'proposal_version_id' => $proposalVersionId,
                    'decision_id' => $decisionId,
                ],
            );

            return $registerVersionId;
        });
    }

    private function insertPosition(
        string $businessId,
        string $registerVersionId,
        object $source,
        string $shareClassId,
        string $sharesIssued,
        string $sharesVested,
        string $votingRights,
        string $profitRights,
    ): void {
        DB::table('ownership_register_positions')->insert([
            'id' => (string) Str::uuid7(),
            'business_id' => $businessId,
            'ownership_register_version_id' => $registerVersionId,
            'partner_id' => $source->partner_id,
            'share_class_id' => $shareClassId,
            'accepted_contribution_minor_units' => $source->accepted_contribution_minor_units,
            'shares_issued' => $sharesIssued,
            'shares_vested' => $sharesVested,
            'voting_rights' => $votingRights,
            'profit_rights' => $profitRights,
            'issue_date' => $source->issue_date,
            'vesting_start_date' => $source->vesting_start_date,
            'vesting_period_months' => $source->vesting_period_months,
            'vesting_cliff_months' => $source->vesting_cliff_months,
            'vesting_conditions' => $source->vesting_conditions,
            'early_exit_treatment' => $source->early_exit_treatment,
        ]);
    }
}
