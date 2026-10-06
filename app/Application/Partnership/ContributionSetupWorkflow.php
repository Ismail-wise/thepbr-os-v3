<?php

declare(strict_types=1);

namespace App\Application\Partnership;

use App\Domain\Access\CapabilityCatalog;
use App\Domain\Partnership\ContributionSetupContract;
use App\Domain\Records\Exceptions\StaleRevision;
use App\Infrastructure\Persistence\Eloquent\Businesses\Business;
use App\Infrastructure\Persistence\Eloquent\Identity\User;
use App\Infrastructure\Persistence\Eloquent\Members\Membership;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;

final class ContributionSetupWorkflow
{
    public function __construct(
        private readonly PartnershipActorContext $actor,
        private readonly PartnershipOccurrence $occurrence,
        private readonly ContributionSetupContract $contract,
    ) {}

    /**
     * @param  array<string,mixed>  $input
     * @return array{id:string,revision:int,created:bool}|null
     */
    public function save(
        User $user,
        Business $business,
        int $expectedRevision,
        array $input,
    ): ?array {
        $membership = $this->actor->membership(
            $user,
            $business,
            CapabilityCatalog::CONTRIBUTIONS_MANAGE,
        );

        if ($membership === null) {
            return null;
        }

        if ($expectedRevision < 0) {
            throw new InvalidArgumentException(
                'Contribution Setup revision is invalid.',
            );
        }

        $normalized = $this->contract->normalize(
            $input,
        );

        return DB::transaction(function () use (
            $user,
            $business,
            $membership,
            $expectedRevision,
            $normalized,
        ): array {
            $businessId = (string) $business->getKey();

            $this->requireActiveMembership(
                $business,
                $normalized[
                    'valuationOwnerMembershipId'
                ],
            );

            foreach (
                $normalized['approverMembershipIds'] as $approverId
            ) {
                $this->requireActiveMembership(
                    $business,
                    $approverId,
                );
            }

            $current = DB::table(
                'contribution_setups',
            )
                ->where(
                    'business_id',
                    $businessId,
                )
                ->lockForUpdate()
                ->first();

            if ($current === null) {
                if ($expectedRevision !== 0) {
                    throw new StaleRevision(
                        $expectedRevision,
                        0,
                    );
                }

                $id = (string) Str::uuid7();
                $revision = 1;

                DB::table(
                    'contribution_setups',
                )->insert([
                    'id' => $id,
                    'business_id' => $businessId,
                    'contract_version' => ContributionSetupContract::CONTRACT_VERSION,
                    'valuation_date' => $normalized['valuationDate'],
                    'currency' => $normalized['currency'],
                    'period_start' => $normalized['periodStart'],
                    'period_end' => $normalized['periodEnd'],
                    'valuation_owner_membership_id' => $normalized[
                            'valuationOwnerMembershipId'
                        ],
                    'revision' => $revision,
                    'updated_by_membership_id' => $membership->getKey(),
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);

                $created = true;
            } else {
                $actualRevision =
                    (int) $current->revision;

                if (
                    $expectedRevision
                    !== $actualRevision
                ) {
                    throw new StaleRevision(
                        $expectedRevision,
                        $actualRevision,
                    );
                }

                $id = (string) $current->id;
                $revision =
                    $actualRevision + 1;

                DB::table(
                    'contribution_setups',
                )
                    ->where(
                        'business_id',
                        $businessId,
                    )
                    ->where('id', $id)
                    ->update([
                        'contract_version' => ContributionSetupContract::CONTRACT_VERSION,
                        'valuation_date' => $normalized[
                                'valuationDate'
                            ],
                        'currency' => $normalized['currency'],
                        'period_start' => $normalized[
                                'periodStart'
                            ],
                        'period_end' => $normalized[
                                'periodEnd'
                            ],
                        'valuation_owner_membership_id' => $normalized[
                                'valuationOwnerMembershipId'
                            ],
                        'revision' => $revision,
                        'updated_by_membership_id' => $membership->getKey(),
                        'updated_at' => now(),
                    ]);

                DB::table(
                    'contribution_setup_approvers',
                )
                    ->where(
                        'business_id',
                        $businessId,
                    )
                    ->where(
                        'contribution_setup_id',
                        $id,
                    )
                    ->delete();

                $created = false;
            }

            foreach (
                $normalized['approverMembershipIds'] as $approverId
            ) {
                DB::table(
                    'contribution_setup_approvers',
                )->insert([
                    'contribution_setup_id' => $id,
                    'business_id' => $businessId,
                    'membership_id' => $approverId,
                    'created_at' => now(),
                ]);
            }

            $this->occurrence->record(
                $user,
                $business,
                $created
                    ? 'partnership.contribution.setup_created'
                    : 'partnership.contribution.setup_updated',
                'contribution_setup',
                $id,
                [
                    'revision' => $revision,
                    'currency' => $normalized['currency'],
                    'approver_count' => count(
                        $normalized[
                            'approverMembershipIds'
                        ],
                    ),
                    'valuation_owner_membership_id' => $normalized[
                            'valuationOwnerMembershipId'
                        ],
                ],
            );

            return [
                'id' => $id,
                'revision' => $revision,
                'created' => $created,
            ];
        });
    }

    private function requireActiveMembership(
        Business $business,
        string $membershipId,
    ): Membership {
        $membership = Membership::query()
            ->where(
                'business_id',
                $business->getKey(),
            )
            ->whereKey($membershipId)
            ->where(
                'access_status',
                'active',
            )
            ->lockForUpdate()
            ->first();

        if ($membership === null) {
            throw new InvalidArgumentException(
                'Contribution Setup people must be active members of this Business.',
            );
        }

        return $membership;
    }
}
