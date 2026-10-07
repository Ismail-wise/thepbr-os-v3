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
use DateTimeImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

final class OwnershipWorkflow
{
    public function __construct(
        private readonly PartnershipActorContext $actor,
        private readonly ContributionWorkflow $contributions,
        private readonly GetAcceptedContributionRegister $acceptedRegister,
    ) {}

    /**
     * Build planning truth from canonical Chapter 2 Accepted Contributions.
     *
     * The accepted-register hash and matching Contribution Decision Record are
     * captured once. They can never be silently replaced later.
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

        if ($membership === null
            || ! $this->actor->allows(
                $user,
                $business,
                CapabilityCatalog::CONTRIBUTIONS_VIEW,
            )
            || ! $this->actor->allows(
                $user,
                $business,
                CapabilityCatalog::RECORDS_VIEW,
            )) {
            return null;
        }

        $name = trim($name);
        if ($name === '' || mb_strlen($name) > 240) {
            throw ValidationException::withMessages([
                'name' => 'Ownership Scenario name is required and must be 240 characters or fewer.',
            ]);
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

        new ShareQuantity($authorizedShares);
        new ShareQuantity($reservedUnissuedShares);

        $chapterSource = $this->acceptedRegister->execute(
            $user,
            $business,
        );

        if ($chapterSource === null
            || ($chapterSource['decisionReady'] ?? false) !== true
            || ! is_string($chapterSource['registerHash'] ?? null)
            || ! is_string($chapterSource['currency'] ?? null)) {
            throw ValidationException::withMessages([
                'contributions' => 'Complete Partner Contributions and record its current Decision before starting Ownership & Equity.',
            ]);
        }

        $sourceDecision = DB::table('contribution_decision_records')
            ->where('business_id', $business->getKey())
            ->where('accepted_register_hash', $chapterSource['registerHash'])
            ->where('contribution_setup_revision', $chapterSource['setupRevision'])
            ->first();

        if ($sourceDecision === null) {
            throw ValidationException::withMessages([
                'contributions' => 'Record the current Partner Contribution Decision before starting Ownership & Equity.',
            ]);
        }

        $currency = (string) $chapterSource['currency'];
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
            $chapterSource,
            $sourceDecision,
        ): string {
            $businessId = (string) $business->getKey();
            $lockedDecision = DB::table('contribution_decision_records')
                ->where('id', $sourceDecision->id)
                ->where('business_id', $businessId)
                ->lockForUpdate()
                ->first();

            if ($lockedDecision === null
                || (string) $lockedDecision->accepted_register_hash
                    !== (string) $chapterSource['registerHash']) {
                throw ValidationException::withMessages([
                    'contributions' => 'Partner Contribution source changed before Ownership capture. Reload before continuing.',
                ]);
            }

            $scenarioId = (string) Str::uuid7();
            $classId = (string) Str::uuid7();

            DB::table('ownership_scenarios')->insert([
                'id' => $scenarioId,
                'business_id' => $businessId,
                'name' => $name,
                'currency' => $currency,
                'share_value_minor_units' => $shareValueMinorUnits,
                'authorized_shares' => $authorizedShares,
                'reserved_unissued_shares' => $reservedUnissuedShares,
                'status' => OwnershipScenarioStatus::Draft->value,
                'revision' => 1,
                'frozen_at' => null,
                'source_accepted_register_hash' => $chapterSource['registerHash'],
                'source_contribution_decision_record_id' => $lockedDecision->id,
                'source_contribution_setup_id' => $lockedDecision->contribution_setup_id,
                'source_contribution_setup_revision' => $lockedDecision->contribution_setup_revision,
                'share_rights_reviewed_at' => null,
                'capacity_reviewed_at' => null,
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
                'restrictions' => null,
                'special_rights' => null,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            $partnerTotals = [];

            foreach ($accepted as $acceptedRow) {
                $contributionId = (string) $acceptedRow['contribution_id'];
                $partnerId = (string) $acceptedRow['partner_id'];

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

                if ($canonical === null
                    || $canonical->status !== ContributionStatus::Accepted->value
                    || $canonical->accepted_value === null) {
                    throw ValidationException::withMessages([
                        'contributions' => 'Ownership may only import canonical Accepted Contribution truth.',
                    ]);
                }

                if (strtoupper((string) $canonical->currency) !== $currency) {
                    throw ValidationException::withMessages([
                        'currency' => 'Accepted Contributions must use the Chapter 2 configured currency. No FX conversion is inferred.',
                    ]);
                }

                if ((string) $canonical->accepted_value
                    !== (string) $acceptedRow['accepted_value']) {
                    throw ValidationException::withMessages([
                        'contributions' => 'Accepted Contribution changed during Ownership capture. Reload before continuing.',
                    ]);
                }

                $value = new ContributionValue(
                    (string) $canonical->accepted_value,
                );
                $minorUnits = $value->minorUnits();

                DB::table('ownership_scenario_contribution_sources')->insert([
                    'id' => (string) Str::uuid7(),
                    'business_id' => $businessId,
                    'ownership_scenario_id' => $scenarioId,
                    'contribution_id' => $contributionId,
                    'partner_id' => $partnerId,
                    'contribution_revision' => (int) $canonical->revision,
                    'currency' => $currency,
                    'accepted_value_minor_units' => $minorUnits,
                    'captured_at' => now(),
                ]);

                $partnerTotals[$partnerId] = ($partnerTotals[$partnerId] ?? 0)
                    + $minorUnits;
            }

            foreach ($partnerTotals as $partnerId => $minorUnits) {
                $shares = ShareQuantity::fromAcceptedValue(
                    $minorUnits,
                    $shareValueMinorUnits,
                );

                DB::table('ownership_scenario_positions')->insert([
                    'id' => (string) Str::uuid7(),
                    'business_id' => $businessId,
                    'ownership_scenario_id' => $scenarioId,
                    'partner_id' => $partnerId,
                    'share_class_id' => $classId,
                    'accepted_contribution_minor_units' => $minorUnits,
                    'shares_issued' => $shares->value(),
                    'shares_vested' => $shares->value(),
                    'vesting_applies' => null,
                    'voting_rights' => $shares->value(),
                    'profit_rights' => $shares->value(),
                    'issue_date' => now()->toDateString(),
                    'vesting_start_date' => null,
                    'vesting_period_months' => null,
                    'vesting_cliff_months' => null,
                    'vesting_conditions' => null,
                    'early_exit_treatment' => null,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }

            $this->assertShareCapacity($businessId, $scenarioId);

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
        ?string $shareClassName = null,
    ): bool {
        if (! $this->actor->allows(
            $user,
            $business,
            CapabilityCatalog::OWNERSHIP_MANAGE,
        )) {
            return false;
        }

        $votingRatio = new EntitlementRatio($votingRightPerShare);
        $profitRatio = new EntitlementRatio($profitRightPerShare);

        if ($shareClassName !== null) {
            $shareClassName = trim($shareClassName);

            if ($shareClassName === '' || mb_strlen($shareClassName) > 120) {
                throw ValidationException::withMessages([
                    'share_class_name' => 'Share Class Name is required and must be 120 characters or fewer.',
                ]);
            }
        }

        return DB::transaction(function () use (
            $business,
            $scenarioId,
            $shareClassId,
            $votingRatio,
            $profitRatio,
            $transferAllowed,
            $restrictions,
            $specialRights,
            $shareClassName,
        ): bool {
            $businessId = (string) $business->getKey();
            $this->lockDraftScenario($businessId, $scenarioId);

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
               <> ROUND(shares_issued * CAST(? AS numeric), 8)
           OR
           shares_issued * CAST(? AS numeric)
               <> ROUND(shares_issued * CAST(? AS numeric), 8)
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
                    'share_class' => 'Share Class rights would exceed supported 8-decimal precision. Adjust the share/right structure instead of silently rounding.',
                ]);
            }

            $classUpdates = [
                'voting_right_per_share' => $votingRatio->value(),
                'profit_right_per_share' => $profitRatio->value(),
                'transfer_allowed' => $transferAllowed,
                'restrictions' => $restrictions === null ? null : trim($restrictions),
                'special_rights' => $specialRights === null ? null : trim($specialRights),
                'updated_at' => now(),
            ];

            if ($shareClassName !== null) {
                $classUpdates['name'] = $shareClassName;
            }

            $updated = DB::table('ownership_scenario_share_classes')
                ->where('id', $shareClassId)
                ->where('business_id', $businessId)
                ->where('ownership_scenario_id', $scenarioId)
                ->update($classUpdates);

            if ($updated !== 1) {
                throw ValidationException::withMessages([
                    'share_class' => 'Share Class was not found in this Business Scenario.',
                ]);
            }

            DB::table('ownership_scenario_positions')
                ->where('business_id', $businessId)
                ->where('ownership_scenario_id', $scenarioId)
                ->where('share_class_id', $shareClassId)
                ->update([
                    'voting_rights' => DB::raw(
                        'ROUND(shares_issued * CAST('.
                        DB::connection()->getPdo()->quote($votingRatio->value()).
                        ' AS numeric), 8)',
                    ),
                    'profit_rights' => DB::raw(
                        'ROUND(shares_issued * CAST('.
                        DB::connection()->getPdo()->quote($profitRatio->value()).
                        ' AS numeric), 8)',
                    ),
                    'updated_at' => now(),
                ]);

            DB::table('ownership_scenarios')
                ->where('id', $scenarioId)
                ->where('business_id', $businessId)
                ->update([
                    'share_rights_reviewed_at' => now(),
                    'revision' => DB::raw('revision + 1'),
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
        return $this->setVestingDecision(
            $user,
            $business,
            $scenarioId,
            $positionId,
            true,
            $vestedShares,
            $startDate,
            $periodMonths,
            $cliffMonths,
            $conditions,
            $earlyExitTreatment,
        );
    }

    public function setVestingDecision(
        User $user,
        Business $business,
        string $scenarioId,
        string $positionId,
        bool $applies,
        ?string $vestedShares = null,
        ?string $startDate = null,
        ?int $periodMonths = null,
        ?int $cliffMonths = null,
        ?string $conditions = null,
        ?string $earlyExitTreatment = null,
    ): bool {
        if (! $this->actor->allows(
            $user,
            $business,
            CapabilityCatalog::OWNERSHIP_MANAGE,
        )) {
            return false;
        }

        return DB::transaction(function () use (
            $business,
            $scenarioId,
            $positionId,
            $applies,
            $vestedShares,
            $startDate,
            $periodMonths,
            $cliffMonths,
            $conditions,
            $earlyExitTreatment,
        ): bool {
            $businessId = (string) $business->getKey();
            $this->lockDraftScenario($businessId, $scenarioId);

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

            if (! $applies) {
                DB::table('ownership_scenario_positions')
                    ->where('id', $positionId)
                    ->update([
                        'vesting_applies' => false,
                        'shares_vested' => $position->shares_issued,
                        'vesting_start_date' => null,
                        'vesting_period_months' => null,
                        'vesting_cliff_months' => null,
                        'vesting_conditions' => null,
                        'early_exit_treatment' => null,
                        'updated_at' => now(),
                    ]);

                $this->touchScenarioRevision($businessId, $scenarioId);

                return true;
            }

            if ($vestedShares === null
                || $startDate === null
                || $periodMonths === null
                || $periodMonths <= 0
                || $periodMonths > 1200
                || $cliffMonths === null
                || $cliffMonths < 0
                || $cliffMonths > $periodMonths
                || trim((string) $conditions) === ''
                || trim((string) $earlyExitTreatment) === '') {
                throw ValidationException::withMessages([
                    'vesting' => 'When Vesting applies, provide Vested Shares, Start Date, Vesting Period, Cliff, Conditions and Early-exit Treatment.',
                ]);
            }

            new ShareQuantity($vestedShares);

            $vestingDate = DateTimeImmutable::createFromFormat(
                '!Y-m-d',
                $startDate,
            );

            if ($vestingDate === false
                || $vestingDate->format('Y-m-d') !== $startDate) {
                throw ValidationException::withMessages([
                    'vesting_start_date' => 'Vesting Start Date must be a valid calendar date.',
                ]);
            }

            if (mb_strlen(trim((string) $conditions)) > 4000
                || mb_strlen(trim((string) $earlyExitTreatment)) > 4000) {
                throw ValidationException::withMessages([
                    'vesting' => 'Vesting Conditions and Early-exit Treatment must each be 4,000 characters or fewer.',
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

            if ($valid === null || ! (bool) $valid->valid) {
                throw ValidationException::withMessages([
                    'vested_shares' => 'Vested Shares must be non-negative and cannot exceed Shares Issued.',
                ]);
            }

            DB::table('ownership_scenario_positions')
                ->where('id', $positionId)
                ->update([
                    'vesting_applies' => true,
                    'shares_vested' => $vestedShares,
                    'vesting_start_date' => $startDate,
                    'vesting_period_months' => $periodMonths,
                    'vesting_cliff_months' => $cliffMonths,
                    'vesting_conditions' => trim((string) $conditions),
                    'early_exit_treatment' => trim((string) $earlyExitTreatment),
                    'updated_at' => now(),
                ]);

            $this->touchScenarioRevision($businessId, $scenarioId);

            return true;
        });
    }

    public function setCapacity(
        User $user,
        Business $business,
        string $scenarioId,
        string $authorizedShares,
        string $reservedUnissuedShares,
    ): bool {
        if (! $this->actor->allows(
            $user,
            $business,
            CapabilityCatalog::OWNERSHIP_MANAGE,
        )) {
            return false;
        }

        new ShareQuantity($authorizedShares);
        new ShareQuantity($reservedUnissuedShares);

        return DB::transaction(function () use (
            $business,
            $scenarioId,
            $authorizedShares,
            $reservedUnissuedShares,
        ): bool {
            $businessId = (string) $business->getKey();
            $this->lockDraftScenario($businessId, $scenarioId);

            DB::table('ownership_scenarios')
                ->where('id', $scenarioId)
                ->where('business_id', $businessId)
                ->update([
                    'authorized_shares' => $authorizedShares,
                    'reserved_unissued_shares' => $reservedUnissuedShares,
                    'capacity_reviewed_at' => now(),
                    'revision' => DB::raw('revision + 1'),
                    'updated_at' => now(),
                ]);

            $this->assertShareCapacity($businessId, $scenarioId);

            return true;
        });
    }

    public function setIssuanceRule(
        User $user,
        Business $business,
        string $scenarioId,
        string $approvalRule,
        string $approvalThresholdPercent,
        bool $preemptionRight,
        string $valuationMethod,
        bool $dilutionAcknowledged,
    ): bool {
        if (! $this->actor->allows(
            $user,
            $business,
            CapabilityCatalog::OWNERSHIP_MANAGE,
        )) {
            return false;
        }

        $approvalRule = trim($approvalRule);
        $valuationMethod = trim($valuationMethod);

        if ($approvalRule === '' || mb_strlen($approvalRule) > 500) {
            throw ValidationException::withMessages([
                'approval_rule' => 'Describe who should approve future new shares in 500 characters or fewer.',
            ]);
        }

        if ($valuationMethod === '' || mb_strlen($valuationMethod) > 500) {
            throw ValidationException::withMessages([
                'valuation_method' => 'Describe the agreed new-share valuation method in 500 characters or fewer.',
            ]);
        }

        if (! preg_match('/\A\d+(?:\.\d{1,2})?\z/', $approvalThresholdPercent)) {
            throw ValidationException::withMessages([
                'approval_threshold' => 'Approval Threshold must be a percentage greater than 0 and no more than 100.',
            ]);
        }

        $threshold = (float) $approvalThresholdPercent;
        if ($threshold <= 0 || $threshold > 100) {
            throw ValidationException::withMessages([
                'approval_threshold' => 'Approval Threshold must be greater than 0 and no more than 100.',
            ]);
        }

        return DB::transaction(function () use (
            $business,
            $scenarioId,
            $approvalRule,
            $approvalThresholdPercent,
            $preemptionRight,
            $valuationMethod,
            $dilutionAcknowledged,
        ): bool {
            $businessId = (string) $business->getKey();
            $this->lockDraftScenario($businessId, $scenarioId);

            $existing = DB::table('ownership_scenario_issuance_rules')
                ->where('business_id', $businessId)
                ->where('ownership_scenario_id', $scenarioId)
                ->lockForUpdate()
                ->first();

            $values = [
                'approval_rule' => $approvalRule,
                'approval_threshold_percent' => $approvalThresholdPercent,
                'preemption_right' => $preemptionRight,
                'valuation_method' => $valuationMethod,
                'dilution_acknowledged' => $dilutionAcknowledged,
                'updated_at' => now(),
            ];

            if ($existing === null) {
                DB::table('ownership_scenario_issuance_rules')->insert([
                    'id' => (string) Str::uuid7(),
                    'business_id' => $businessId,
                    'ownership_scenario_id' => $scenarioId,
                    ...$values,
                    'created_at' => now(),
                ]);
            } else {
                DB::table('ownership_scenario_issuance_rules')
                    ->where('id', $existing->id)
                    ->update($values);
            }

            $this->touchScenarioRevision($businessId, $scenarioId);

            return true;
        });
    }

    public function freeze(
        User $user,
        Business $business,
        string $scenarioId,
        int $expectedRevision,
    ): bool {
        if (! $this->actor->allows(
            $user,
            $business,
            CapabilityCatalog::OWNERSHIP_MANAGE,
        )) {
            return false;
        }

        $currentSource = $this->acceptedRegister->execute(
            $user,
            $business,
        );

        return DB::transaction(function () use (
            $business,
            $scenarioId,
            $expectedRevision,
            $currentSource,
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

            $this->assertChapterReadiness(
                $businessId,
                $scenarioId,
                $scenario,
                $currentSource,
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
     * It is never inferred from latest-created row.
     */
    public function currentEffectiveRegisterVersion(
        User $user,
        Business $business,
        ?\DateTimeInterface $at = null,
    ): ?object {
        if (! $this->actor->allows(
            $user,
            $business,
            CapabilityCatalog::OWNERSHIP_VIEW,
        )) {
            return null;
        }

        $at ??= now();

        return DB::table('ownership_register_versions')
            ->where('business_id', $business->getKey())
            ->whereIn('status', ['effective', 'superseded', 'archived'])
            ->where('effective_from', '<=', $at)
            ->where(function ($query) use ($at): void {
                $query->whereNull('effective_until')
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

    /** @param array<string,mixed>|null $currentSource */
    private function assertChapterReadiness(
        string $businessId,
        string $scenarioId,
        object $scenario,
        ?array $currentSource,
    ): void {
        if ($scenario->source_accepted_register_hash === null
            || $scenario->source_contribution_decision_record_id === null
            || $scenario->source_contribution_setup_id === null
            || $scenario->source_contribution_setup_revision === null) {
            throw ValidationException::withMessages([
                'contributions' => 'Ownership needs the exact completed Chapter 2 Accepted Contribution source before it can be frozen.',
            ]);
        }

        if ($currentSource === null
            || ($currentSource['decisionReady'] ?? false) !== true
            || ($currentSource['registerHash'] ?? null)
                !== (string) $scenario->source_accepted_register_hash) {
            throw ValidationException::withMessages([
                'contributions' => 'Source changed — review Ownership again before submitting it for approval.',
            ]);
        }

        $sourceDecision = DB::table('contribution_decision_records')
            ->where('id', $scenario->source_contribution_decision_record_id)
            ->where('business_id', $businessId)
            ->where('accepted_register_hash', $scenario->source_accepted_register_hash)
            ->where('contribution_setup_id', $scenario->source_contribution_setup_id)
            ->where('contribution_setup_revision', $scenario->source_contribution_setup_revision)
            ->first();

        if ($sourceDecision === null) {
            throw ValidationException::withMessages([
                'contributions' => 'The captured Chapter 2 Decision source is no longer valid for this Ownership Scenario.',
            ]);
        }

        if ($scenario->share_rights_reviewed_at === null) {
            throw ValidationException::withMessages([
                'share_class' => 'Review Share Class, Voting, Profit and Transfer Rights before approval.',
            ]);
        }

        if ($scenario->capacity_reviewed_at === null) {
            throw ValidationException::withMessages([
                'capacity' => 'Review Authorized, Issued, Reserved and Available Share capacity before approval.',
            ]);
        }

        $positions = DB::table('ownership_scenario_positions')
            ->where('business_id', $businessId)
            ->where('ownership_scenario_id', $scenarioId)
            ->get();

        if ($positions->isEmpty()) {
            throw ValidationException::withMessages([
                'allocation' => 'Ownership requires at least one calculated Share allocation.',
            ]);
        }

        foreach ($positions as $position) {
            if ($position->vesting_applies === null) {
                throw ValidationException::withMessages([
                    'vesting' => 'Review whether Vesting applies to every Share allocation.',
                ]);
            }

            if ((bool) $position->vesting_applies
                && ($position->vesting_start_date === null
                    || $position->vesting_period_months === null
                    || $position->vesting_cliff_months === null
                    || trim((string) $position->vesting_conditions) === ''
                    || trim((string) $position->early_exit_treatment) === '')) {
                throw ValidationException::withMessages([
                    'vesting' => 'Complete the Vesting terms for every allocation where Vesting applies.',
                ]);
            }

            if ((bool) $position->vesting_applies
                && (int) $position->vesting_cliff_months
                    > (int) $position->vesting_period_months) {
                throw ValidationException::withMessages([
                    'vesting' => 'Vesting Cliff cannot exceed the Vesting Period.',
                ]);
            }
        }

        $rule = DB::table('ownership_scenario_issuance_rules')
            ->where('business_id', $businessId)
            ->where('ownership_scenario_id', $scenarioId)
            ->first();

        if ($rule === null || ! (bool) $rule->dilution_acknowledged) {
            throw ValidationException::withMessages([
                'issuance_rule' => 'Complete the New Share Issuance Rule and acknowledge possible dilution before approval.',
            ]);
        }

        $sourceCount = DB::table('ownership_scenario_contribution_sources')
            ->where('business_id', $businessId)
            ->where('ownership_scenario_id', $scenarioId)
            ->count();

        if ($sourceCount !== (int) ($currentSource['acceptedCount'] ?? -1)) {
            throw ValidationException::withMessages([
                'contributions' => 'The captured Ownership source does not match the current Accepted Contribution Register.',
            ]);
        }

        $this->assertShareCapacity($businessId, $scenarioId);
    }

    private function touchScenarioRevision(
        string $businessId,
        string $scenarioId,
    ): void {
        $updated = DB::table('ownership_scenarios')
            ->where('id', $scenarioId)
            ->where('business_id', $businessId)
            ->where('status', OwnershipScenarioStatus::Draft->value)
            ->update([
                'revision' => DB::raw('revision + 1'),
                'updated_at' => now(),
            ]);

        if ($updated !== 1) {
            throw ValidationException::withMessages([
                'scenario' => 'Ownership Scenario changed. Reload before continuing.',
            ]);
        }
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
    ) <= s.authorized_shares AS valid
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
            [$businessId, $scenarioId],
        );

        if ($capacity === null || ! (bool) $capacity->valid) {
            throw ValidationException::withMessages([
                'authorized_shares' => 'Issued Shares plus Reserved / Unissued Shares cannot exceed Authorized Shares.',
            ]);
        }
    }
}
