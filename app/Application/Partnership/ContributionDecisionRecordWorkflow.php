<?php

declare(strict_types=1);

namespace App\Application\Partnership;

use App\Domain\Access\CapabilityCatalog;
use App\Domain\Partnership\ContributionDecisionRecordContract;
use App\Infrastructure\Persistence\Eloquent\Businesses\Business;
use App\Infrastructure\Persistence\Eloquent\Identity\User;
use App\Infrastructure\Persistence\Eloquent\Members\Membership;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;
use RuntimeException;

final class ContributionDecisionRecordWorkflow
{
    public function __construct(
        private readonly PartnershipActorContext $actor,
        private readonly PartnershipOccurrence $occurrence,
        private readonly GetAcceptedContributionRegister $register,
        private readonly ContributionDecisionRecordContract $contract,
    ) {}

    /**
     * @param  array<string,mixed>  $input
     * @return array{id:string,created:bool,registerHash:string}|null
     */
    public function create(
        User $user,
        Business $business,
        array $input,
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

        $normalized = $this->contract->normalize(
            $input,
        );

        $register = $this->register->execute(
            $user,
            $business,
        );

        if (
            $register === null
            || ($register['available'] ?? false)
                !== true
            || ($register['decisionReady'] ?? false)
                !== true
        ) {
            throw new RuntimeException(
                'A current single-currency Accepted Contribution Register with governed Acceptance sources is required.',
            );
        }

        $hash = (string)
            $register['registerHash'];
        $currency = (string)
            $register['currency'];

        return DB::transaction(function () use (
            $user,
            $business,
            $membership,
            $normalized,
            $register,
            $hash,
            $currency,
        ): array {
            $businessId =
                (string) $business->getKey();

            $currentSetup = DB::table(
                'contribution_setups',
            )
                ->where(
                    'business_id',
                    $businessId,
                )
                ->lockForUpdate()
                ->first();

            if ($currentSetup === null) {
                throw new RuntimeException(
                    'Contribution Setup is required before recording the Contribution Decision.',
                );
            }

            if (
                (int) $currentSetup->revision
                !== (int)
                    $register[
                        'setupRevision'
                    ]
                || (string)
                    $currentSetup->currency
                    !== $currency
            ) {
                throw new RuntimeException(
                    'Contribution Setup changed while the Accepted Contribution Register was being prepared.',
                );
            }

            $existing = DB::table(
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
                ->lockForUpdate()
                ->first();

            if ($existing !== null) {
                return [
                    'id' => (string) $existing->id,
                    'created' => false,
                    'registerHash' => $hash,
                ];
            }

            $owner = Membership::query()
                ->where(
                    'business_id',
                    $businessId,
                )
                ->whereKey(
                    $normalized[
                        'decisionOwnerMembershipId'
                    ],
                )
                ->where(
                    'access_status',
                    'active',
                )
                ->lockForUpdate()
                ->first();

            if ($owner === null) {
                throw new InvalidArgumentException(
                    'Decision Owner must be an active member of this Business.',
                );
            }

            $total = collect(
                $register['currencyTotals'],
            )
                ->firstWhere(
                    'currency',
                    $currency,
                );

            if (
                ! is_array($total)
                || ! isset($total['total'])
            ) {
                throw new RuntimeException(
                    'Accepted Contribution total is unavailable for the configured currency.',
                );
            }

            $recordId =
                (string) Str::uuid7();

            DB::table(
                'contribution_decision_records',
            )->insert([
                'id' => $recordId,
                'business_id' => $businessId,
                'contract_version' => ContributionDecisionRecordContract::CONTRACT_VERSION,
                'contribution_setup_id' => $currentSetup->id,
                'contribution_setup_revision' => $currentSetup->revision,
                'currency' => $currency,
                'accepted_contribution_count' => (int)
                        $register[
                            'acceptedCount'
                        ],
                'accepted_total' => (string) $total['total'],
                'accepted_register_hash' => $hash,
                'decision_owner_membership_id' => $owner->getKey(),
                'effective_date' => $normalized[
                        'effectiveDate'
                    ],
                'review_date' => $normalized[
                        'reviewDate'
                    ],
                'decision_summary' => $normalized[
                        'decisionSummary'
                    ],
                'evidence_references' => json_encode(
                    $normalized[
                        'evidenceReferences'
                    ],
                    JSON_THROW_ON_ERROR
                    | JSON_UNESCAPED_SLASHES
                    | JSON_UNESCAPED_UNICODE,
                ),
                'created_by_membership_id' => $membership->getKey(),
                'created_at' => now(),
            ]);

            foreach (
                $register['rows'] as $row
            ) {
                $source =
                    $row[
                        'governanceSource'
                    ] ?? null;

                if (! is_array($source)) {
                    throw new RuntimeException(
                        'Accepted Contribution source changed before the Decision Record was saved.',
                    );
                }

                DB::table(
                    'contribution_decision_record_sources',
                )->insert([
                    'contribution_decision_record_id' => $recordId,
                    'business_id' => $businessId,
                    'contribution_id' => $row[
                            'contributionId'
                        ],
                    'contribution_revision' => $row['revision'],
                    'acceptance_decision_id' => $source[
                            'decisionId'
                        ],
                    'formal_record_version_id' => $source[
                            'formalRecordVersionId'
                        ],
                    'proposal_version_id' => $source[
                            'proposalVersionId'
                        ],
                    'accepted_value' => $row[
                            'acceptedValue'
                        ],
                    'accepted_at' => $row['acceptedAt']
                        ?? $source[
                            'resolvedAt'
                        ]
                        ?? now(),
                    'evidence_snapshot' => json_encode(
                        $row[
                            'evidence'
                        ] ?? [],
                        JSON_THROW_ON_ERROR
                        | JSON_UNESCAPED_SLASHES
                        | JSON_UNESCAPED_UNICODE,
                    ),
                ]);
            }

            $this->occurrence->record(
                $user,
                $business,
                'partnership.contribution.decision_record_created',
                'contribution_decision_record',
                $recordId,
                [
                    'accepted_count' => (int)
                            $register[
                                'acceptedCount'
                            ],
                    'currency' => $currency,
                    'decision_owner_membership_id' => (string)
                            $owner->getKey(),
                    'setup_revision' => (int)
                            $currentSetup
                                ->revision,
                ],
            );

            return [
                'id' => $recordId,
                'created' => true,
                'registerHash' => $hash,
            ];
        });
    }
}
