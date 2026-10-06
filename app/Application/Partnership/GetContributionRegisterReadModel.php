<?php

declare(strict_types=1);

namespace App\Application\Partnership;

use App\Domain\Access\CapabilityCatalog;
use App\Domain\Partnership\ContributionTimeSkillValuation;
use App\Domain\Partnership\ContributionValuationCatalog;
use App\Domain\Partnership\Enums\ContributionStatus;
use App\Domain\Partnership\Enums\ContributionType;
use App\Domain\Partnership\ValueObjects\ContributionValue;
use App\Infrastructure\Persistence\Eloquent\Businesses\Business;
use App\Infrastructure\Persistence\Eloquent\Identity\User;
use Illuminate\Support\Facades\DB;

final class GetContributionRegisterReadModel
{
    public function __construct(
        private readonly PartnershipActorContext $actor,
        private readonly ContributionTimeSkillValuation $timeSkill,
        private readonly ContributionValuationCatalog $catalog,
    ) {}

    /**
     * @return array<string,mixed>|null
     */
    public function execute(
        User $user,
        Business $business,
    ): ?array {
        if (! $this->actor->allows(
            $user,
            $business,
            CapabilityCatalog::CONTRIBUTIONS_VIEW,
        )) {
            return null;
        }

        $businessId =
            (string) $business->getKey();

        $rows = DB::table(
            'contributions as contribution',
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
                'contribution.business_id',
                $businessId,
            )
            ->orderByDesc(
                'contribution.created_at',
            )
            ->get([
                'contribution.*',
                'partner.display_name as partner_name',
            ])
            ->map(
                fn (object $row): array => $this->row(
                    $businessId,
                    $row,
                ),
            )
            ->values()
            ->all();

        $counts = collect($rows)
            ->countBy('status')
            ->all();

        return [
            'contractVersion' => 'contribution-register-read-model-v1',
            'canManage' => $this->actor->allows(
                $user,
                $business,
                CapabilityCatalog::CONTRIBUTIONS_MANAGE,
            ),
            'rows' => $rows,
            'counts' => [
                'total' => count($rows),
                'proposed' => (int) ($counts['proposed'] ?? 0),
                'reviewed' => (int) ($counts['reviewed'] ?? 0),
                'approved' => (int) ($counts['approved'] ?? 0),
                'delivered' => (int) ($counts['delivered'] ?? 0),
                'accepted' => (int) ($counts['accepted'] ?? 0),
                'rejected' => (int) ($counts['rejected'] ?? 0),
                'cancelled' => (int) ($counts['cancelled'] ?? 0),
                'defaulted' => (int) ($counts['defaulted'] ?? 0),
            ],
            'typeOptions' => [
                [
                    'key' => ContributionType::Cash->value,
                    'label' => 'Cash',
                ],
                [
                    'key' => ContributionType::TimeSkill->value,
                    'label' => 'Time & Skill',
                ],
                [
                    'key' => ContributionType::PropertyAsset->value,
                    'label' => 'Property / Asset',
                ],
                [
                    'key' => ContributionType::IpIntangible->value,
                    'label' => 'IP / Intangible',
                ],
            ],
            'intangibleSubtypes' => $this->catalog
                ->intangibleSubtypes(),
            'valuationMethods' => [
                ContributionType::Cash->value => $this->catalog->methods(
                    ContributionType::Cash,
                ),
                ContributionType::TimeSkill->value => $this->catalog->methods(
                    ContributionType::TimeSkill,
                ),
                ContributionType::PropertyAsset->value => $this->catalog->methods(
                    ContributionType::PropertyAsset,
                ),
                ContributionType::IpIntangible->value => $this->catalog->methods(
                    ContributionType::IpIntangible,
                ),
            ],
            'terminalOptions' => [
                [
                    'key' => 'rejected',
                    'label' => 'Rejected',
                ],
                [
                    'key' => 'cancelled',
                    'label' => 'Cancelled',
                ],
                [
                    'key' => 'defaulted',
                    'label' => 'Defaulted',
                ],
            ],
            'semantics' => [
                'proposedIsAccepted' => false,
                'reviewedIsAccepted' => false,
                'approvedIsAccepted' => false,
                'deliveredIsAccepted' => false,
                'acceptedOnlyFeedsEquityPlanning' => true,
                'contributionCreatesOwnership' => false,
            ],
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function row(
        string $businessId,
        object $row,
    ): array {
        $type = ContributionType::from(
            (string)
                $row->contribution_type,
        );
        $status = ContributionStatus::from(
            (string) $row->status,
        );

        $evidence = DB::table(
            'evidence_links as link',
        )
            ->join(
                'evidence as evidence',
                function ($join): void {
                    $join
                        ->on(
                            'evidence.id',
                            '=',
                            'link.evidence_id',
                        )
                        ->on(
                            'evidence.business_id',
                            '=',
                            'link.business_id',
                        );
                },
            )
            ->where(
                'link.business_id',
                $businessId,
            )
            ->where(
                'link.target_type',
                'contribution',
            )
            ->where(
                'link.target_id',
                $row->id,
            )
            ->orderBy('link.created_at')
            ->get([
                'evidence.id',
                'evidence.verified_at',
                'evidence.source_date',
                'evidence.confidentiality',
            ])
            ->map(
                static fn (
                    object $item,
                ): array => [
                    'id' => (string) $item->id,
                    'verified' => $item->verified_at
                            !== null,
                    'sourceDate' => $item->source_date
                            === null
                        ? null
                        : (string)
                            $item
                                ->source_date,
                    'confidentiality' => (string)
                            $item
                                ->confidentiality,
                ],
            )
            ->values()
            ->all();

        $deliveries = DB::table(
            'contribution_delivery_events',
        )
            ->where(
                'business_id',
                $businessId,
            )
            ->where(
                'contribution_id',
                $row->id,
            )
            ->orderBy('delivered_at')
            ->get()
            ->map(
                static fn (
                    object $item,
                ): array => [
                    'id' => (string) $item->id,
                    'value' => (string)
                            $item
                                ->delivered_value,
                    'deliveredAt' => (string)
                            $item
                                ->delivered_at,
                    'extent' => property_exists(
                        $item,
                        'delivery_extent',
                    )
                        ? (string)
                            $item
                                ->delivery_extent
                        : 'full',
                    'scope' => property_exists(
                        $item,
                        'delivered_scope',
                    )
                        ? $item
                            ->delivered_scope
                        : null,
                    'adjustmentBasis' => property_exists(
                        $item,
                        'adjustment_basis',
                    )
                        ? $item
                            ->adjustment_basis
                        : null,
                    'notes' => $item->notes,
                ],
            )
            ->values()
            ->all();

        $deliveredMinor = 0;

        foreach ($deliveries as $delivery) {
            $minor =
                (new ContributionValue(
                    (string)
                        $delivery['value'],
                ))->minorUnits();

            if (
                $minor
                > PHP_INT_MAX
                    - $deliveredMinor
            ) {
                $deliveredMinor =
                    PHP_INT_MAX;
                break;
            }

            $deliveredMinor += $minor;
        }

        $history = DB::table(
            'contribution_status_transitions',
        )
            ->where(
                'business_id',
                $businessId,
            )
            ->where(
                'contribution_id',
                $row->id,
            )
            ->orderBy('changed_at')
            ->get([
                'from_status',
                'to_status',
                'note',
                'changed_at',
            ])
            ->map(
                fn (object $item): array => [
                    'from' => $item->from_status
                            === null
                        ? null
                        : $this->statusLabel(
                            (string)
                                $item
                                    ->from_status,
                        ),
                    'to' => $this->statusLabel(
                        (string)
                            $item
                                ->to_status,
                    ),
                    'note' => $item->note,
                    'changedAt' => (string)
                            $item
                                ->changed_at,
                ],
            )
            ->values()
            ->all();

        $governance = DB::table(
            'contribution_governance_submissions as submission',
        )
            ->leftJoin(
                'decisions as decision',
                function ($join): void {
                    $join
                        ->on(
                            'decision.proposal_version_id',
                            '=',
                            'submission.proposal_version_id',
                        )
                        ->on(
                            'decision.business_id',
                            '=',
                            'submission.business_id',
                        );
                },
            )
            ->where(
                'submission.business_id',
                $businessId,
            )
            ->where(
                'submission.contribution_id',
                $row->id,
            )
            ->orderBy(
                'submission.created_at',
            )
            ->get([
                'submission.id',
                'submission.phase',
                'submission.contribution_revision',
                'submission.proposed_accepted_value',
                'submission.formal_record_version_id',
                'submission.proposal_version_id',
                'submission.created_at',
                'decision.status as decision_status',
                'decision.outcome as decision_outcome',
                'decision.resolved_at',
            ])
            ->map(
                function (
                    object $item,
                ) use ($businessId): array {
                    $formalState = DB::table(
                        'record_version_state_transitions',
                    )
                        ->where(
                            'business_id',
                            $businessId,
                        )
                        ->where(
                            'formal_record_version_id',
                            $item->formal_record_version_id,
                        )
                        ->orderByDesc('sequence')
                        ->value('to_state');

                    return [
                        'submissionId' => (string) $item->id,
                        'phase' => (string) $item->phase,
                        'sourceRevision' => (int)
                                $item
                                    ->contribution_revision,
                        'proposedAcceptedValue' => $item
                            ->proposed_accepted_value
                                === null
                            ? null
                            : (string)
                                $item
                                    ->proposed_accepted_value,
                        'formalState' => $formalState === null
                            ? null
                            : (string) $formalState,
                        'decisionStatus' => $item->decision_status,
                        'decisionOutcome' => $item->decision_outcome,
                        'resolvedAt' => $item->resolved_at
                                === null
                            ? null
                            : (string)
                                $item
                                    ->resolved_at,
                        'createdAt' => (string)
                                $item
                                    ->created_at,
                    ];
                },
            )
            ->values()
            ->all();

        return [
            'id' => (string) $row->id,
            'partnerId' => (string) $row->partner_id,
            'partnerName' => (string) $row->partner_name,
            'type' => $type->value,
            'typeLabel' => $this->typeLabel($type),
            'status' => $status->value,
            'statusLabel' => $this->statusLabel(
                $status->value,
            ),
            'terminal' => $status->isTerminal(),
            'currency' => (string) $row->currency,
            'description' => (string) $row->description,
            'proposedValue' => (string)
                    $row->proposed_value,
            'reviewedValue' => $row->reviewed_value
                    === null
                ? null
                : (string)
                    $row->reviewed_value,
            'approvedValue' => $row->approved_value
                    === null
                ? null
                : (string)
                    $row->approved_value,
            'acceptedValue' => $row->accepted_value
                    === null
                ? null
                : (string)
                    $row->accepted_value,
            'valuationMethod' => $row->valuation_method,
            'conditions' => $row->conditions,
            'committedDate' => $row->committed_date
                    === null
                ? null
                : (string)
                    $row->committed_date,
            'dueDate' => $row->due_date
                    === null
                ? null
                : (string)
                    $row->due_date,
            'revision' => (int) $row->revision,
            'details' => $this->details(
                $businessId,
                $row,
                $type,
            ),
            'evidence' => $evidence,
            'evidenceCount' => count($evidence),
            'verifiedEvidenceCount' => count(array_filter(
                $evidence,
                static fn (
                    array $item,
                ): bool => $item['verified']
                    === true,
            )),
            'deliveries' => $deliveries,
            'deliveredTotal' => $this->decimal(
                $deliveredMinor,
            ),
            'history' => $history,
            'governance' => $governance,
            'createdAt' => (string) $row->created_at,
            'updatedAt' => (string) $row->updated_at,
        ];
    }

    private function details(
        string $businessId,
        object $row,
        ContributionType $type,
    ): ?array {
        return match ($type) {
            ContributionType::Cash => $this->cashDetails(
                $businessId,
                (string) $row->id,
            ),
            ContributionType::TimeSkill => $this->timeSkillDetails(
                $businessId,
                (string) $row->id,
            ),
            ContributionType::PropertyAsset => $this->assetDetails(
                $businessId,
                (string) $row->id,
            ),
            ContributionType::IpIntangible => $this->intangibleDetails(
                $businessId,
                (string) $row->id,
            ),
        };
    }

    private function cashDetails(
        string $businessId,
        string $id,
    ): ?array {
        $detail = DB::table(
            'cash_contribution_details',
        )
            ->where(
                'business_id',
                $businessId,
            )
            ->where(
                'contribution_id',
                $id,
            )
            ->first();

        if ($detail === null) {
            return null;
        }

        return [
            'amountCommitted' => (string)
                    $detail
                        ->amount_committed,
            'amountReceived' => (bool)
                    $detail
                        ->amount_received_recorded
                ? (string)
                    $detail
                        ->amount_received
                : null,
            'paymentDate' => $detail->payment_date
                    === null
                ? null
                : (string)
                    $detail
                        ->payment_date,
        ];
    }

    private function timeSkillDetails(
        string $businessId,
        string $id,
    ): ?array {
        $detail = DB::table(
            'time_skill_contribution_details',
        )
            ->where(
                'business_id',
                $businessId,
            )
            ->where(
                'contribution_id',
                $id,
            )
            ->first();

        if ($detail === null) {
            return null;
        }

        $calculation =
            $this->timeSkill->calculate(
                (string)
                    $detail
                        ->hours_per_month,
                new ContributionValue(
                    (string)
                        $detail
                            ->fair_market_rate,
                ),
                (int)
                    $detail
                        ->number_of_months,
                new ContributionValue(
                    (string)
                        $detail
                            ->cash_compensation_received,
                ),
            );

        return [
            'roleWork' => (string)
                    $detail->role_work,
            'hoursPerMonth' => (string)
                    $detail
                        ->hours_per_month,
            'fairMarketRate' => (string)
                    $detail
                        ->fair_market_rate,
            'numberOfMonths' => (int)
                    $detail
                        ->number_of_months,
            'cashCompensationReceived' => (string)
                    $detail
                        ->cash_compensation_received,
            'startDate' => $detail->start_date
                    === null
                ? null
                : (string)
                    $detail->start_date,
            'endDate' => $detail->end_date
                    === null
                ? null
                : (string)
                    $detail->end_date,
            'performanceCondition' => $detail
                ->performance_condition,
            'contributionVestingCondition' => $detail->vesting_rule,
            'calculation' => $calculation,
        ];
    }

    private function assetDetails(
        string $businessId,
        string $id,
    ): ?array {
        $detail = DB::table(
            'asset_contribution_details',
        )
            ->where(
                'business_id',
                $businessId,
            )
            ->where(
                'contribution_id',
                $id,
            )
            ->first();

        if ($detail === null) {
            return null;
        }

        return [
            'assetDescription' => (string)
                    $detail
                        ->asset_description,
            'assetOwner' => $detail->asset_owner,
            'ownershipTransferred' => (bool)
                    $detail
                        ->ownership_transferred,
            'usagePeriod' => $detail->usage_period,
            'marketValue' => $detail->market_value
                    === null
                ? null
                : (string)
                    $detail
                        ->market_value,
            'fairRentalUseValue' => $detail
                ->fair_rental_use_value
                    === null
                ? null
                : (string)
                    $detail
                        ->fair_rental_use_value,
            'valuationMethod' => $detail
                ->valuation_method,
        ];
    }

    private function intangibleDetails(
        string $businessId,
        string $id,
    ): ?array {
        $detail = DB::table(
            'intangible_contribution_details',
        )
            ->where(
                'business_id',
                $businessId,
            )
            ->where(
                'contribution_id',
                $id,
            )
            ->first();

        if ($detail === null) {
            return null;
        }

        return [
            'subtype' => (string)
                    $detail
                        ->intangible_kind,
            'legalBeneficialOwner' => (string)
                    $detail
                        ->legal_beneficial_owner,
            'contributionForm' => (string)
                    $detail
                        ->contribution_form,
            'contributionPeriod' => $detail
                ->contribution_period,
            'valuationMethod' => (string)
                    $detail
                        ->valuation_method,
            'description' => (string)
                    $detail
                        ->intangible_description,
        ];
    }

    private function typeLabel(
        ContributionType $type,
    ): string {
        return match ($type) {
            ContributionType::Cash => 'Cash',
            ContributionType::TimeSkill => 'Time & Skill',
            ContributionType::PropertyAsset => 'Property / Asset',
            ContributionType::IpIntangible => 'IP / Intangible',
        };
    }

    private function statusLabel(
        string $status,
    ): string {
        return match ($status) {
            'proposed' => 'Proposed',
            'reviewed' => 'Reviewed',
            'approved' => 'Approved',
            'delivered' => 'Delivered',
            'accepted' => 'Accepted',
            'rejected' => 'Rejected',
            'cancelled' => 'Cancelled',
            'defaulted' => 'Defaulted',
            default => 'Unknown',
        };
    }

    private function decimal(
        int $minorUnits,
    ): string {
        return intdiv(
            $minorUnits,
            100,
        ).'.'.str_pad(
            (string) ($minorUnits % 100),
            2,
            '0',
            STR_PAD_LEFT,
        );
    }
}
