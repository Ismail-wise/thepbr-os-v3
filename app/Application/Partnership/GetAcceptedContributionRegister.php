<?php

declare(strict_types=1);

namespace App\Application\Partnership;

use App\Domain\Access\CapabilityCatalog;
use App\Domain\Partnership\AcceptedContributionRegisterContract;
use App\Domain\Partnership\ContributionTimeSkillValuation;
use App\Domain\Partnership\Enums\ContributionType;
use App\Domain\Partnership\ValueObjects\ContributionValue;
use App\Infrastructure\Persistence\Eloquent\Businesses\Business;
use App\Infrastructure\Persistence\Eloquent\Identity\User;
use Illuminate\Support\Facades\DB;

final class GetAcceptedContributionRegister
{
    public function __construct(
        private readonly PartnershipActorContext $actor,
        private readonly AcceptedContributionRegisterContract $contract,
        private readonly ContributionTimeSkillValuation $timeSkill,
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

        $setup = DB::table(
            'contribution_setups',
        )
            ->where(
                'business_id',
                $businessId,
            )
            ->first();

        if ($setup === null) {
            return [
                'contractVersion' => AcceptedContributionRegisterContract::READ_MODEL_VERSION,
                'registerContractVersion' => AcceptedContributionRegisterContract::CONTRACT_VERSION,
                'available' => false,
                'reason' => 'setup_required',
                'currency' => null,
                'rows' => [],
                'matrix' => [],
                'currencyTotals' => [],
                'acceptedCount' => 0,
                'registerHash' => null,
                'decisionReady' => false,
                'warnings' => [
                    'Complete Contribution Setup before creating the Accepted Contribution Register.',
                ],
            ];
        }

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
            ->where(
                'contribution.status',
                'accepted',
            )
            ->whereNotNull(
                'contribution.accepted_value',
            )
            ->whereNotNull(
                'contribution.acceptance_decision_id',
            )
            ->orderBy(
                'partner.display_name',
            )
            ->orderBy(
                'contribution.id',
            )
            ->get([
                'contribution.id',
                'contribution.partner_id',
                'partner.display_name as partner_name',
                'contribution.contribution_type',
                'contribution.currency',
                'contribution.description',
                'contribution.proposed_value',
                'contribution.reviewed_value',
                'contribution.approved_value',
                'contribution.accepted_value',
                'contribution.valuation_method',
                'contribution.conditions',
                'contribution.committed_date',
                'contribution.due_date',
                'contribution.revision',
                'contribution.acceptance_decision_id',
            ]);

        $registerRows = [];
        $matrix = [];
        $currencyTotalsMinor = [];
        $warnings = [];

        foreach ($rows as $row) {
            $acceptedValue =
                new ContributionValue(
                    (string) $row
                        ->accepted_value,
                );

            $currency = (string) $row->currency;
            $minor = $acceptedValue
                ->minorUnits();

            $currencyTotalsMinor[$currency] =
                $this->contract->addMinorUnits(
                    $currencyTotalsMinor[
                        $currency
                    ] ?? 0,
                    $minor,
                );

            $partnerId =
                (string) $row->partner_id;

            if (
                ! isset(
                    $matrix[
                        $partnerId
                    ]
                )
            ) {
                $matrix[$partnerId] = [
                    'partnerId' => $partnerId,
                    'partnerName' => (string)
                            $row->partner_name,
                    'currencies' => [],
                ];
            }

            if (
                ! isset(
                    $matrix[
                        $partnerId
                    ]['currencies'][
                        $currency
                    ]
                )
            ) {
                $matrix[
                    $partnerId
                ]['currencies'][
                    $currency
                ] = [
                    'cashMinor' => 0,
                    'timeSkillMinor' => 0,
                    'propertyAssetMinor' => 0,
                    'ipIntangibleMinor' => 0,
                    'totalMinor' => 0,
                ];
            }

            $bucket = match (
                (string)
                    $row->contribution_type
            ) {
                ContributionType::Cash->value => 'cashMinor',
                ContributionType::TimeSkill->value => 'timeSkillMinor',
                ContributionType::PropertyAsset->value => 'propertyAssetMinor',
                ContributionType::IpIntangible->value => 'ipIntangibleMinor',
            };

            $matrix[
                $partnerId
            ]['currencies'][
                $currency
            ][$bucket] =
                $this->contract->addMinorUnits(
                    $matrix[
                        $partnerId
                    ]['currencies'][
                        $currency
                    ][$bucket],
                    $minor,
                );

            $matrix[
                $partnerId
            ]['currencies'][
                $currency
            ]['totalMinor'] =
                $this->contract->addMinorUnits(
                    $matrix[
                        $partnerId
                    ]['currencies'][
                        $currency
                    ]['totalMinor'],
                    $minor,
                );

            $source = $this->source(
                $businessId,
                $row,
            );

            $evidence = $this->evidence(
                $businessId,
                (string) $row->id,
            );

            $details = $this->details(
                $businessId,
                $row,
            );

            $acceptedAt = DB::table(
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
                ->where(
                    'to_status',
                    'accepted',
                )
                ->orderByDesc(
                    'changed_at',
                )
                ->value('changed_at');

            if ($source === null) {
                $warnings[] =
                    'An Accepted Contribution is missing its frozen governance source.';
            } elseif (
                ($source['formalState'] ?? null)
                !== 'approved'
            ) {
                $warnings[] =
                    'An Accepted Contribution has governance approval but its frozen content is not in the Approved content-review state.';
            }

            $registerRows[] = [
                'contributionId' => (string) $row->id,
                'partnerId' => $partnerId,
                'partnerName' => (string)
                        $row->partner_name,
                'type' => (string)
                        $row
                            ->contribution_type,
                'currency' => $currency,
                'description' => (string)
                        $row->description,
                'proposedValue' => (string)
                        $row
                            ->proposed_value,
                'reviewedValue' => $row->reviewed_value
                        === null
                    ? null
                    : (string)
                        $row
                            ->reviewed_value,
                'approvedValue' => $row->approved_value
                        === null
                    ? null
                    : (string)
                        $row
                            ->approved_value,
                'acceptedValue' => $acceptedValue->amount,
                'valuationMethod' => $row->valuation_method
                        === null
                    ? null
                    : (string)
                        $row
                            ->valuation_method,
                'conditions' => $row->conditions
                        === null
                    ? null
                    : (string)
                        $row->conditions,
                'revision' => (int) $row->revision,
                'acceptedAt' => $acceptedAt === null
                    ? null
                    : (string)
                        $acceptedAt,
                'details' => $details,
                'evidence' => $evidence,
                'governanceSource' => $source,
            ];
        }

        $matrixRows = collect($matrix)
            ->map(
                function (
                    array $partner,
                ): array {
                    $currencies = [];

                    foreach (
                        $partner['currencies'] as $currency => $values
                    ) {
                        $currencies[] = [
                            'currency' => (string)
                                    $currency,
                            'cash' => $this->contract
                                ->decimal(
                                    $values[
                                        'cashMinor'
                                    ],
                                ),
                            'timeSkill' => $this->contract
                                ->decimal(
                                    $values[
                                        'timeSkillMinor'
                                    ],
                                ),
                            'propertyAsset' => $this->contract
                                ->decimal(
                                    $values[
                                        'propertyAssetMinor'
                                    ],
                                ),
                            'ipIntangible' => $this->contract
                                ->decimal(
                                    $values[
                                        'ipIntangibleMinor'
                                    ],
                                ),
                            'total' => $this->contract
                                ->decimal(
                                    $values[
                                        'totalMinor'
                                    ],
                                ),
                        ];
                    }

                    return [
                        'partnerId' => $partner[
                                'partnerId'
                            ],
                        'partnerName' => $partner[
                                'partnerName'
                            ],
                        'currencies' => $currencies,
                    ];
                },
            )
            ->values()
            ->all();

        ksort($currencyTotalsMinor);

        $currencyTotals = [];

        foreach (
            $currencyTotalsMinor as $currency => $minor
        ) {
            $currencyTotals[] = [
                'currency' => $currency,
                'total' => $this->contract->decimal(
                    $minor,
                ),
            ];
        }

        $setupCurrency =
            (string) $setup->currency;
        $currencies = array_keys(
            $currencyTotalsMinor,
        );

        $singleSetupCurrency =
            $currencies === []
            || (
                count($currencies) === 1
                && $currencies[0]
                    === $setupCurrency
            );

        if (! $singleSetupCurrency) {
            $warnings[] =
                'Accepted Contributions use more than the configured Contribution currency. No FX conversion is inferred.';
        }

        $hashPayload = [
            'contractVersion' => AcceptedContributionRegisterContract::CONTRACT_VERSION,
            'businessId' => $businessId,
            'setup' => [
                'id' => (string) $setup->id,
                'revision' => (int) $setup->revision,
                'valuationDate' => (string)
                        $setup
                            ->valuation_date,
                'currency' => $setupCurrency,
                'periodStart' => (string)
                        $setup
                            ->period_start,
                'periodEnd' => (string)
                        $setup
                            ->period_end,
            ],
            'rows' => array_map(
                static function (
                    array $row,
                ): array {
                    return [
                        'contributionId' => $row[
                                'contributionId'
                            ],
                        'partnerId' => $row[
                                'partnerId'
                            ],
                        'type' => $row['type'],
                        'currency' => $row[
                                'currency'
                            ],
                        'acceptedValue' => $row[
                                'acceptedValue'
                            ],
                        'revision' => $row[
                                'revision'
                            ],
                        'acceptedAt' => $row[
                                'acceptedAt'
                            ],
                        'evidence' => $row[
                                'evidence'
                            ],
                        'governanceSource' => $row[
                                'governanceSource'
                            ],
                    ];
                },
                $registerRows,
            ),
        ];

        $registerHash =
            $this->contract->hash(
                $hashPayload,
            );

        return [
            'contractVersion' => AcceptedContributionRegisterContract::READ_MODEL_VERSION,
            'registerContractVersion' => AcceptedContributionRegisterContract::CONTRACT_VERSION,
            'available' => true,
            'reason' => null,
            'currency' => $setupCurrency,
            'setupRevision' => (int) $setup->revision,
            'rows' => $registerRows,
            'matrix' => $matrixRows,
            'currencyTotals' => $currencyTotals,
            'acceptedCount' => count($registerRows),
            'registerHash' => $registerHash,
            'decisionReady' => $registerRows !== []
                && $singleSetupCurrency
                && collect($registerRows)->every(
                    static function (
                        array $row,
                    ): bool {
                        $source =
                            $row[
                                'governanceSource'
                            ] ?? null;

                        return is_array($source)
                            && (
                                $source[
                                    'formalState'
                                ] ?? null
                            ) === 'approved';
                    },
                ),
            'warnings' => array_values(
                array_unique(
                    $warnings,
                ),
            ),
            'semantics' => [
                'acceptedOnly' => true,
                'fxConversionInferred' => false,
                'createsEquity' => false,
                'createsShares' => false,
                'createsOwnership' => false,
            ],
        ];
    }

    private function source(
        string $businessId,
        object $row,
    ): ?array {
        $source = DB::table(
            'contribution_governance_submissions as submission',
        )
            ->join(
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
            ->where(
                'submission.phase',
                'acceptance',
            )
            ->where(
                'decision.id',
                $row
                    ->acceptance_decision_id,
            )
            ->where(
                'decision.status',
                'decided',
            )
            ->where(
                'decision.outcome',
                'approved',
            )
            ->orderByDesc(
                'decision.resolved_at',
            )
            ->first([
                'submission.formal_record_version_id',
                'submission.proposal_version_id',
                'submission.content_hash',
                'submission.contribution_revision',
                'decision.id as decision_id',
                'decision.resolved_at',
            ]);

        if ($source === null) {
            return null;
        }

        $formalState = DB::table(
            'record_version_state_transitions',
        )
            ->where(
                'business_id',
                $businessId,
            )
            ->where(
                'formal_record_version_id',
                $source
                    ->formal_record_version_id,
            )
            ->orderByDesc('sequence')
            ->value('to_state');

        return [
            'decisionId' => (string) $source->decision_id,
            'formalRecordVersionId' => (string) $source
                ->formal_record_version_id,
            'proposalVersionId' => (string) $source
                ->proposal_version_id,
            'contentHash' => (string) $source
                ->content_hash,
            'submissionRevision' => (int) $source
                ->contribution_revision,
            'formalState' => $formalState === null
                ? null
                : (string) $formalState,
            'resolvedAt' => $source->resolved_at
                    === null
                ? null
                : (string)
                    $source
                        ->resolved_at,
        ];
    }

    /**
     * @return list<array<string,mixed>>
     */
    private function evidence(
        string $businessId,
        string $contributionId,
    ): array {
        return DB::table(
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
            ->join(
                'document_versions as version',
                function ($join): void {
                    $join
                        ->on(
                            'version.id',
                            '=',
                            'evidence.document_version_id',
                        )
                        ->on(
                            'version.business_id',
                            '=',
                            'evidence.business_id',
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
                $contributionId,
            )
            ->orderBy(
                'link.evidence_id',
            )
            ->get([
                'evidence.id',
                'evidence.document_version_id',
                'evidence.verified_at',
                'version.content_sha256',
            ])
            ->map(
                static fn (
                    object $row,
                ): array => [
                    'evidenceId' => (string) $row->id,
                    'documentVersionId' => (string) $row
                        ->document_version_id,
                    'documentHash' => (string) $row
                        ->content_sha256,
                    'verified' => $row->verified_at
                            !== null,
                ],
            )
            ->values()
            ->all();
    }

    private function details(
        string $businessId,
        object $row,
    ): ?array {
        return match (
            (string)
                $row->contribution_type
        ) {
            ContributionType::Cash->value => $this->cashDetails(
                $businessId,
                (string) $row->id,
            ),
            ContributionType::TimeSkill->value => $this->timeSkillDetails(
                $businessId,
                (string) $row->id,
            ),
            ContributionType::PropertyAsset->value => $this->assetDetails(
                $businessId,
                (string) $row->id,
            ),
            ContributionType::IpIntangible->value => $this->intangibleDetails(
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
            'assetOwner' => $detail->asset_owner
                    === null
                ? null
                : (string)
                    $detail
                        ->asset_owner,
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
            'kind' => (string)
                    $detail
                        ->intangible_kind,
            'description' => (string)
                    $detail
                        ->intangible_description,
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
        ];
    }
}
