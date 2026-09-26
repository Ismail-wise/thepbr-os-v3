<?php

declare(strict_types=1);

namespace App\Application\Partnership;

use App\Domain\Access\CapabilityCatalog;
use App\Domain\Partnership\Enums\ContributionStatus;
use App\Domain\Partnership\Enums\OwnershipScenarioStatus;
use App\Domain\Partnership\ValueObjects\ContributionValue;
use App\Domain\Partnership\ValueObjects\EntitlementRatio;
use App\Domain\Partnership\ValueObjects\ShareQuantity;
use App\Infrastructure\Persistence\Eloquent\Businesses\Business;
use App\Infrastructure\Persistence\Eloquent\Identity\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

final class OwnershipWorkflow
{
    public function __construct(
        private readonly PartnershipActorContext $actor,
        private readonly ContributionWorkflow $contributions,
    ) {}

    /**
     * Build planning truth from canonical Accepted Contributions only.
     *
     * This never creates or changes an Effective Ownership Register Version.
     */
    public function createFromAcceptedContributions(
        User $user,
        Business $business,
        string $name,
        string $currency,
        int $shareValueMinorUnits,
        string $authorizedShares,
        string $reservedUnissuedShares = '0',
    ): ?string {
        $membership = $this->actor->membership(
            $user,
            $business,
            CapabilityCatalog::OWNERSHIP_MANAGE,
        );

        if ($membership === null) {
            return null;
        }

        if (
            ! $this->actor->allows(
                $user,
                $business,
                CapabilityCatalog::CONTRIBUTIONS_VIEW,
            )
        ) {
            return null;
        }

        if ($shareValueMinorUnits <= 0) {
            throw ValidationException::withMessages([
                'share_value' => 'Share Value must be greater than zero.',
            ]);
        }

        $currency = strtoupper($currency);

        if (! preg_match('/^[A-Z]{3}$/', $currency)) {
            throw ValidationException::withMessages([
                'currency' => 'Ownership currency must use a three-letter currency code.',
            ]);
        }

        $accepted = $this->contributions->acceptedValues(
            $user,
            $business,
        );

        if ($accepted === []) {
            throw ValidationException::withMessages([
                'contributions' => 'Ownership Scenario requires at least one Accepted Contribution.',
            ]);
        }

        return DB::transaction(function () use (
            $business,
            $membership,
            $name,
            $currency,
            $shareValueMinorUnits,
            $authorizedShares,
            $reservedUnissuedShares,
            $accepted,
        ): string {
            $businessId = (string) $business->getKey();

            $scenarioId = (string) Str::uuid();
            $classId = (string) Str::uuid();

            DB::table('ownership_scenarios')->insert([
                'id' => $scenarioId,
                'business_id' => $businessId,
                'name' => trim($name),
                'currency' => $currency,
                'share_value_minor_units' => $shareValueMinorUnits,
                'authorized_shares' => $authorizedShares,
                'reserved_unissued_shares' => $reservedUnissuedShares,
                'status' => OwnershipScenarioStatus::Draft->value,
                'revision' => 1,
                'created_by_membership_id' => $membership->getKey(),
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            DB::table('ownership_scenario_share_classes')->insert([
                'id' => $classId,
                'business_id' => $businessId,
                'ownership_scenario_id' => $scenarioId,
                'name' => 'Ordinary',
                'voting_right_per_share' => '1',
                'profit_right_per_share' => '1',
                'transfer_allowed' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            $partnerTotals = [];

            foreach ($accepted as $acceptedRow) {
                $contributionId = (string) $acceptedRow['contribution_id'];
                $partnerId = (string) $acceptedRow['partner_id'];

                if (
                    strtoupper((string) $acceptedRow['currency'])
                    !== $currency
                ) {
                    throw ValidationException::withMessages([
                        'currency' => 'Accepted Contributions must use the Ownership Scenario currency.',
                    ]);
                }

                $canonical = DB::table('contributions')
                    ->where('id', $contributionId)
                    ->where('business_id', $businessId)
                    ->where('partner_id', $partnerId)
                    ->lockForUpdate()
                    ->first([
                        'id',
                        'partner_id',
                        'currency',
                        'status',
                        'revision',
                        'accepted_value',
                    ]);

                if (
                    $canonical === null
                    || $canonical->status
                        !== ContributionStatus::Accepted->value
                    || $canonical->accepted_value === null
                ) {
                    throw ValidationException::withMessages([
                        'contributions' => 'Ownership may only import canonical Accepted Contribution truth.',
                    ]);
                }

                if (
                    strtoupper((string) $canonical->currency)
                    !== $currency
                ) {
                    throw ValidationException::withMessages([
                        'currency' => 'Canonical Accepted Contribution currency changed during Ownership capture.',
                    ]);
                }

                if (
                    (string) $canonical->accepted_value
                    !== (string) $acceptedRow['accepted_value']
                ) {
                    throw ValidationException::withMessages([
                        'contributions' => 'Accepted Contribution changed during Ownership capture. Reload before continuing.',
                    ]);
                }

                $value = new ContributionValue(
                    (string) $canonical->accepted_value,
                );

                $minorUnits = $value->minorUnits();

                DB::table(
                    'ownership_scenario_contribution_sources',
                )->insert([
                    'id' => (string) Str::uuid(),
                    'business_id' => $businessId,
                    'ownership_scenario_id' => $scenarioId,
                    'contribution_id' => $contributionId,
                    'partner_id' => $partnerId,
                    'contribution_revision' => (int) $canonical->revision,
                    'currency' => $currency,
                    'accepted_value_minor_units' => $minorUnits,
                    'captured_at' => now(),
                ]);

                $partnerTotals[$partnerId] = (
                    $partnerTotals[$partnerId] ?? 0
                ) + $minorUnits;
            }

            foreach ($partnerTotals as $partnerId => $minorUnits) {
                $shares = ShareQuantity::fromAcceptedValue(
                    $minorUnits,
                    $shareValueMinorUnits,
                );

                DB::table('ownership_scenario_positions')->insert([
                    'id' => (string) Str::uuid(),
                    'business_id' => $businessId,
                    'ownership_scenario_id' => $scenarioId,
                    'partner_id' => $partnerId,
                    'share_class_id' => $classId,
                    'accepted_contribution_minor_units' => $minorUnits,
                    'shares_issued' => $shares->value(),
                    'shares_vested' => $shares->value(),
                    'voting_rights' => $shares->value(),
                    'profit_rights' => $shares->value(),
                    'issue_date' => now()->toDateString(),
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }

            $this->assertShareCapacity(
                $businessId,
                $scenarioId,
            );

            return $scenarioId;
        });
    }

    public function setShareClassRights(
        User $user,
        Business $business,
        string $scenarioId,
        string $shareClassId,
        string $votingRightPerShare,
        string $profitRightPerShare,
        bool $transferAllowed,
        ?string $restrictions,
        ?string $specialRights,
    ): bool {
        if (
            ! $this->actor->allows(
                $user,
                $business,
                CapabilityCatalog::OWNERSHIP_MANAGE,
            )
        ) {
            return false;
        }

        $votingRatio = new EntitlementRatio($votingRightPerShare);
        $profitRatio = new EntitlementRatio($profitRightPerShare);

        return DB::transaction(function () use (
            $business,
            $scenarioId,
            $shareClassId,
            $votingRatio,
            $profitRatio,
            $transferAllowed,
            $restrictions,
            $specialRights,
        ): bool {
            $businessId = (string) $business->getKey();

            $this->lockDraftScenario(
                $businessId,
                $scenarioId,
            );

            $precision = DB::selectOne(
                <<<'SQL'
SELECT NOT EXISTS (
    SELECT 1
      FROM ownership_scenario_positions
     WHERE business_id = ?
       AND ownership_scenario_id = ?
       AND share_class_id = ?
       AND (
           shares_issued * CAST(? AS numeric)
               <> ROUND(
                   shares_issued * CAST(? AS numeric),
                   8
               )
           OR
           shares_issued * CAST(? AS numeric)
               <> ROUND(
                   shares_issued * CAST(? AS numeric),
                   8
               )
       )
) AS valid
SQL,
                [
                    $businessId,
                    $scenarioId,
                    $shareClassId,
                    $votingRatio->value(),
                    $votingRatio->value(),
                    $profitRatio->value(),
                    $profitRatio->value(),
                ],
            );

            if ($precision === null || ! (bool) $precision->valid) {
                throw ValidationException::withMessages([
                    'share_class' => 'Share Class rights would exceed supported 8-decimal precision. Adjust the approved share/right structure instead of silently rounding.',
                ]);
            }

            $updated = DB::table(
                'ownership_scenario_share_classes',
            )
                ->where('id', $shareClassId)
                ->where('business_id', $businessId)
                ->where('ownership_scenario_id', $scenarioId)
                ->update([
                    'voting_right_per_share' => $votingRatio->value(),
                    'profit_right_per_share' => $profitRatio->value(),
                    'transfer_allowed' => $transferAllowed,
                    'restrictions' => $restrictions,
                    'special_rights' => $specialRights,
                    'updated_at' => now(),
                ]);

            if ($updated !== 1) {
                throw ValidationException::withMessages([
                    'share_class' => 'Share Class was not found in this Business Scenario.',
                ]);
            }

            /*
             * PostgreSQL NUMERIC performs the calculation in decimal space.
             * We deliberately avoid binary floating-point for ownership rights.
             */
            DB::table('ownership_scenario_positions')
                ->where('business_id', $businessId)
                ->where('ownership_scenario_id', $scenarioId)
                ->where('share_class_id', $shareClassId)
                ->update([
                    'voting_rights' => DB::raw(
                        'ROUND(shares_issued * CAST('.
                        DB::connection()->getPdo()->quote(
                            $votingRatio->value(),
                        ).
                        ' AS numeric), 8)',
                    ),
                    'profit_rights' => DB::raw(
                        'ROUND(shares_issued * CAST('.
                        DB::connection()->getPdo()->quote(
                            $profitRatio->value(),
                        ).
                        ' AS numeric), 8)',
                    ),
                    'updated_at' => now(),
                ]);

            return true;
        });
    }

    public function setVesting(
        User $user,
        Business $business,
        string $scenarioId,
        string $positionId,
        string $vestedShares,
        ?string $startDate,
        ?int $periodMonths,
        ?int $cliffMonths,
        ?string $conditions,
        ?string $earlyExitTreatment,
    ): bool {
        if (
            ! $this->actor->allows(
                $user,
                $business,
                CapabilityCatalog::OWNERSHIP_MANAGE,
            )
        ) {
            return false;
        }

        return DB::transaction(function () use (
            $business,
            $scenarioId,
            $positionId,
            $vestedShares,
            $startDate,
            $periodMonths,
            $cliffMonths,
            $conditions,
            $earlyExitTreatment,
        ): bool {
            $businessId = (string) $business->getKey();

            $this->lockDraftScenario(
                $businessId,
                $scenarioId,
            );

            $position = DB::table('ownership_scenario_positions')
                ->where('id', $positionId)
                ->where('business_id', $businessId)
                ->where('ownership_scenario_id', $scenarioId)
                ->lockForUpdate()
                ->first();

            if ($position === null) {
                throw ValidationException::withMessages([
                    'position' => 'Ownership position was not found.',
                ]);
            }

            $valid = DB::selectOne(
                'SELECT CAST(? AS numeric) >= 0
                        AND CAST(? AS numeric) <= CAST(? AS numeric)
                    AS valid',
                [
                    $vestedShares,
                    $vestedShares,
                    (string) $position->shares_issued,
                ],
            );

            if (! (bool) $valid->valid) {
                throw ValidationException::withMessages([
                    'vested_shares' => 'Vested Shares must be non-negative and cannot exceed Shares Issued.',
                ]);
            }

            DB::table('ownership_scenario_positions')
                ->where('id', $positionId)
                ->update([
                    'shares_vested' => $vestedShares,
                    'vesting_start_date' => $startDate,
                    'vesting_period_months' => $periodMonths,
                    'vesting_cliff_months' => $cliffMonths,
                    'vesting_conditions' => $conditions,
                    'early_exit_treatment' => $earlyExitTreatment,
                    'updated_at' => now(),
                ]);

            return true;
        });
    }

    public function freeze(
        User $user,
        Business $business,
        string $scenarioId,
        int $expectedRevision,
    ): bool {
        if (
            ! $this->actor->allows(
                $user,
                $business,
                CapabilityCatalog::OWNERSHIP_MANAGE,
            )
        ) {
            return false;
        }

        return DB::transaction(function () use (
            $business,
            $scenarioId,
            $expectedRevision,
        ): bool {
            $businessId = (string) $business->getKey();

            $scenario = $this->lockDraftScenario(
                $businessId,
                $scenarioId,
            );

            if ((int) $scenario->revision !== $expectedRevision) {
                throw ValidationException::withMessages([
                    'revision' => 'Ownership Scenario changed. Reload before freezing.',
                ]);
            }

            $this->assertShareCapacity(
                $businessId,
                $scenarioId,
            );

            DB::table('ownership_scenarios')
                ->where('id', $scenarioId)
                ->where('business_id', $businessId)
                ->update([
                    'status' => OwnershipScenarioStatus::Frozen->value,
                    'revision' => $expectedRevision + 1,
                    'frozen_at' => now(),
                    'updated_at' => now(),
                ]);

            return true;
        });
    }

    /**
     * Current official ownership truth is Effective-date aware.
     *
     * It is never inferred from latest-created row.
     */
    public function currentEffectiveRegisterVersion(
        User $user,
        Business $business,
        ?\DateTimeInterface $at = null,
    ): ?object {
        if (
            ! $this->actor->allows(
                $user,
                $business,
                CapabilityCatalog::OWNERSHIP_VIEW,
            )
        ) {
            return null;
        }

        $at ??= now();

        return DB::table('ownership_register_versions')
            ->where('business_id', $business->getKey())
            ->whereIn(
                'status',
                ['effective', 'superseded', 'archived'],
            )
            ->where('effective_from', '<=', $at)
            ->where(function ($query) use ($at): void {
                $query
                    ->whereNull('effective_until')
                    ->orWhere('effective_until', '>', $at);
            })
            ->orderByDesc('effective_from')
            ->first();
    }

    private function lockDraftScenario(
        string $businessId,
        string $scenarioId,
    ): object {
        $scenario = DB::table('ownership_scenarios')
            ->where('id', $scenarioId)
            ->where('business_id', $businessId)
            ->lockForUpdate()
            ->first();

        if ($scenario === null) {
            throw ValidationException::withMessages([
                'scenario' => 'Ownership Scenario was not found in this Business.',
            ]);
        }

        if ($scenario->status !== OwnershipScenarioStatus::Draft->value) {
            throw ValidationException::withMessages([
                'scenario' => 'Only Draft Ownership Scenario may be edited.',
            ]);
        }

        return $scenario;
    }

    private function assertShareCapacity(
        string $businessId,
        string $scenarioId,
    ): void {
        $capacity = DB::selectOne(
            <<<'SQL'
SELECT
    (
        COALESCE(SUM(p.shares_issued), 0)
        + s.reserved_unissued_shares
        <= s.authorized_shares
    ) AS valid
FROM ownership_scenarios s
LEFT JOIN ownership_scenario_positions p
       ON p.ownership_scenario_id = s.id
      AND p.business_id = s.business_id
WHERE s.id = ?
  AND s.business_id = ?
GROUP BY
    s.authorized_shares,
    s.reserved_unissued_shares
SQL,
            [
                $scenarioId,
                $businessId,
            ],
        );

        if ($capacity === null || ! (bool) $capacity->valid) {
            throw ValidationException::withMessages([
                'authorized_shares' => 'Issued plus Reserved/Unissued Shares cannot exceed Authorized Shares.',
            ]);
        }
    }
}
