<?php

declare(strict_types=1);

namespace App\Application\Partnership;

use App\Domain\Access\CapabilityCatalog;
use App\Domain\Partnership\ContributionDecisionRecordContract;
use App\Infrastructure\Persistence\Eloquent\Businesses\Business;
use App\Infrastructure\Persistence\Eloquent\Identity\User;
use App\Infrastructure\Persistence\Eloquent\Members\Membership;
use Illuminate\Support\Facades\DB;

final class GetContributionDecisionRecordReadModel
{
    public function __construct(
        private readonly PartnershipActorContext $actor,
        private readonly GetAcceptedContributionRegister $register,
    ) {}

    /**
     * @return array<string,mixed>|null
     */
    public function execute(
        User $user,
        Business $business,
    ): ?array {
        if (
            ! $this->actor->allows(
                $user,
                $business,
                CapabilityCatalog::CONTRIBUTIONS_VIEW,
            )
            || ! $this->actor->allows(
                $user,
                $business,
                CapabilityCatalog::RECORDS_VIEW,
            )
        ) {
            return null;
        }

        $register = $this->register->execute(
            $user,
            $business,
        );

        if ($register === null) {
            return null;
        }

        $businessId =
            (string) $business->getKey();

        $latest = DB::table(
            'contribution_decision_records',
        )
            ->where(
                'business_id',
                $businessId,
            )
            ->orderByDesc(
                'created_at',
            )
            ->first();

        $current = null;
        $hash =
            $register['registerHash']
            ?? null;

        if (is_string($hash)) {
            $current = DB::table(
                'contribution_decision_records',
            )
                ->where(
                    'business_id',
                    $businessId,
                )
                ->where(
                    'accepted_register_hash',
                    $hash,
                )
                ->first();
        }

        $canCreate =
            ($register['decisionReady']
                ?? false) === true
            && $current === null
            && $this->actor->allows(
                $user,
                $business,
                CapabilityCatalog::CONTRIBUTIONS_MANAGE,
            )
            && $this->actor->allows(
                $user,
                $business,
                CapabilityCatalog::RECORDS_MANAGE,
            );

        $display = $current ?? $latest;

        return [
            'contractVersion' => ContributionDecisionRecordContract::READ_MODEL_VERSION,
            'recordContractVersion' => ContributionDecisionRecordContract::CONTRACT_VERSION,
            'available' => ($register['available']
                    ?? false) === true,
            'recorded' => $current !== null,
            'hasHistoricalRecord' => $latest !== null,
            'currentRegisterHash' => $hash,
            'currentRegisterAcceptedCount' => (int) (
                $register[
                    'acceptedCount'
                ] ?? 0
            ),
            'currentRegisterCurrency' => $register['currency']
                ?? null,
            'canCreate' => $canCreate,
            'stale' => $latest !== null
                && $current === null,
            'status' => match (true) {
                ($register['available']
                    ?? false) !== true => 'setup_required',
                ($register['decisionReady']
                    ?? false) !== true => 'accepted_register_not_ready',
                $current !== null => 'current_decision_recorded',
                $latest !== null => 'historical_decision_stale',
                default => 'ready_to_record',
            },
            'decisionOwnerOptions' => $canCreate
                ? $this->memberOptions(
                    $business,
                )
                : [],
            'record' => $display === null
                ? null
                : $this->record(
                    $business,
                    $display,
                    $current !== null,
                ),
            'suggestedDecisionSummary' => $this->suggestedSummary(
                $register,
            ),
            'semantics' => [
                'approvalTruthComesFromSources' => true,
                'acceptanceTruthComesFromSources' => true,
                'decisionRecordCreatesSignature' => false,
                'decisionRecordCreatesEffectivity' => false,
                'decisionRecordCreatesEquity' => false,
                'decisionRecordCreatesOwnership' => false,
            ],
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function record(
        Business $business,
        object $row,
        bool $current,
    ): array {
        $sources = DB::table(
            'contribution_decision_record_sources as source',
        )
            ->join(
                'contributions as contribution',
                function ($join): void {
                    $join
                        ->on(
                            'contribution.id',
                            '=',
                            'source.contribution_id',
                        )
                        ->on(
                            'contribution.business_id',
                            '=',
                            'source.business_id',
                        );
                },
            )
            ->join(
                'partners as partner',
                function ($join): void {
                    $join
                        ->on(
                            'partner.id',
                            '=',
                            'contribution.partner_id',
                        )
                        ->on(
                            'partner.business_id',
                            '=',
                            'contribution.business_id',
                        );
                },
            )
            ->where(
                'source.business_id',
                $business->getKey(),
            )
            ->where(
                'source.contribution_decision_record_id',
                $row->id,
            )
            ->orderBy(
                'partner.display_name',
            )
            ->get([
                'source.contribution_id',
                'source.contribution_revision',
                'source.acceptance_decision_id',
                'source.formal_record_version_id',
                'source.proposal_version_id',
                'source.accepted_value',
                'source.accepted_at',
                'partner.display_name as partner_name',
                'contribution.description',
                'contribution.contribution_type',
            ])
            ->map(
                static fn (
                    object $source,
                ): array => [
                    'partnerName' => (string)
                            $source
                                ->partner_name,
                    'description' => (string)
                            $source
                                ->description,
                    'type' => (string)
                            $source
                                ->contribution_type,
                    'acceptedValue' => (string)
                            $source
                                ->accepted_value,
                    'acceptedAt' => (string)
                            $source
                                ->accepted_at,
                    'contributionRevision' => (int)
                            $source
                                ->contribution_revision,
                    'governanceSource' => [
                        'decisionId' => (string)
                                $source
                                    ->acceptance_decision_id,
                        'formalRecordVersionId' => (string)
                                $source
                                    ->formal_record_version_id,
                        'proposalVersionId' => (string)
                                $source
                                    ->proposal_version_id,
                    ],
                ],
            )
            ->values()
            ->all();

        $references = json_decode(
            (string)
                $row->evidence_references,
            true,
            512,
            JSON_THROW_ON_ERROR,
        );

        return [
            'id' => (string) $row->id,
            'isCurrent' => $current,
            'currency' => (string) $row->currency,
            'acceptedContributionCount' => (int)
                    $row
                        ->accepted_contribution_count,
            'acceptedTotal' => (string)
                    $row->accepted_total,
            'acceptedRegisterHash' => (string)
                    $row
                        ->accepted_register_hash,
            'setupRevision' => (int)
                    $row
                        ->contribution_setup_revision,
            'decisionOwner' => $this->membershipLabel(
                $business,
                (string)
                    $row
                        ->decision_owner_membership_id,
            ),
            'effectiveDate' => (string)
                    $row->effective_date,
            'reviewDate' => (string) $row->review_date,
            'decisionSummary' => (string)
                    $row
                        ->decision_summary,
            'evidenceReferences' => is_array($references)
                ? array_values(
                    array_filter(
                        $references,
                        'is_string',
                    ),
                )
                : [],
            'createdAt' => (string) $row->created_at,
            'sources' => $sources,
        ];
    }

    /**
     * @return list<array{id:string,name:string}>
     */
    private function memberOptions(
        Business $business,
    ): array {
        return Membership::query()
            ->with(['user.profile'])
            ->where(
                'business_id',
                $business->getKey(),
            )
            ->where(
                'access_status',
                'active',
            )
            ->get()
            ->map(
                fn (Membership $membership): array => [
                    'id' => (string)
                        $membership->getKey(),
                    'name' => $this->label($membership),
                ],
            )
            ->sortBy('name')
            ->values()
            ->all();
    }

    private function membershipLabel(
        Business $business,
        string $membershipId,
    ): string {
        $membership = Membership::query()
            ->with(['user.profile'])
            ->where(
                'business_id',
                $business->getKey(),
            )
            ->whereKey($membershipId)
            ->first();

        return $membership === null
            ? 'Unavailable member'
            : $this->label($membership);
    }

    private function label(
        Membership $membership,
    ): string {
        $displayName = trim((string) (
            $membership->user?->profile
                ?->display_name ?? ''
        ));

        return $displayName !== ''
            ? $displayName
            : (string) (
                $membership->user?->email
                ?? 'Business member'
            );
    }

    /**
     * @param  array<string,mixed>  $register
     */
    private function suggestedSummary(
        array $register,
    ): string {
        $count = (int) (
            $register['acceptedCount']
            ?? 0
        );
        $currency = (string) (
            $register['currency']
            ?? ''
        );
        $total = collect(
            $register['currencyTotals']
            ?? [],
        )->firstWhere(
            'currency',
            $currency,
        );

        $amount = is_array($total)
            ? (string) (
                $total['total'] ?? ''
            )
            : '';

        return trim(sprintf(
            'Record %d governed Accepted Contribution%s totaling %s %s as the current Partner Contribution decision source.',
            $count,
            $count === 1 ? '' : 's',
            $currency,
            $amount,
        ));
    }
}
