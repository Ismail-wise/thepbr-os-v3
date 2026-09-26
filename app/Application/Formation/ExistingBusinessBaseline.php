<?php

declare(strict_types=1);

namespace App\Application\Formation;

use App\Domain\Access\CapabilityCatalog;
use App\Domain\Businesses\Enums\BusinessOriginType;
use App\Domain\Records\Exceptions\StaleRevision;
use App\Infrastructure\Persistence\Eloquent\Businesses\Business;
use App\Infrastructure\Persistence\Eloquent\Identity\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;

final class ExistingBusinessBaseline
{
    public function __construct(
        private readonly FormationActorContext $actor,
        private readonly FormationOccurrence $occurrence,
    ) {}

    /**
     * @param  array<string, mixed>  $fields
     */
    public function saveProfile(
        User $user,
        Business $business,
        int $expectedRevision,
        array $fields,
    ): ?array {
        if (! $this->canManage($user, $business)) {
            return null;
        }

        return $this->saveAndRecord(
            $user,
            $business,
            'existing_business_profiles',
            'existing_business_profile',
            'formation.existing_profile.saved',
            $expectedRevision,
            $fields,
        );
    }

    public function addFinancialSnapshot(
        User $user,
        Business $business,
        array $fields,
    ): ?string {
        return $this->append(
            $user,
            $business,
            'financial_snapshots',
            'financial_snapshot',
            'formation.financial_snapshot.created',
            $fields,
        );
    }

    public function addAsset(
        User $user,
        Business $business,
        array $fields,
    ): ?string {
        return $this->append(
            $user,
            $business,
            'business_assets',
            'business_asset',
            'formation.asset.created',
            $fields,
        );
    }

    public function addLiability(
        User $user,
        Business $business,
        array $fields,
    ): ?string {
        return $this->append(
            $user,
            $business,
            'business_liabilities',
            'business_liability',
            'formation.liability.created',
            $fields,
        );
    }

    public function addOwnerPosition(
        User $user,
        Business $business,
        array $fields,
    ): ?string {
        return $this->append(
            $user,
            $business,
            'existing_owner_positions',
            'existing_owner_position',
            'formation.owner_position.created',
            $fields,
        );
    }

    public function addObligation(
        User $user,
        Business $business,
        array $fields,
    ): ?string {
        return $this->append(
            $user,
            $business,
            'current_obligations',
            'current_obligation',
            'formation.obligation.created',
            $fields,
        );
    }

    public function addRiskSnapshot(
        User $user,
        Business $business,
        array $fields,
    ): ?string {
        return $this->append(
            $user,
            $business,
            'current_risk_control_snapshots',
            'current_risk_control_snapshot',
            'formation.risk_snapshot.created',
            $fields,
        );
    }

    public function addAgreementConstraint(
        User $user,
        Business $business,
        array $fields,
    ): ?string {
        return $this->append(
            $user,
            $business,
            'agreement_constraints',
            'agreement_constraint',
            'formation.agreement_constraint.created',
            $fields,
        );
    }

    public function saveGapAssessment(
        User $user,
        Business $business,
        int $expectedRevision,
        array $fields,
    ): ?array {
        if (! $this->canManage($user, $business)) {
            return null;
        }

        return $this->saveAndRecord(
            $user,
            $business,
            'gap_assessments',
            'gap_assessment',
            'formation.gap_assessment.saved',
            $expectedRevision,
            $fields,
        );
    }

    public function saveConversionPlan(
        User $user,
        Business $business,
        int $expectedRevision,
        string $plan,
    ): ?array {
        if (! $this->canManage($user, $business)) {
            return null;
        }

        return $this->saveAndRecord(
            $user,
            $business,
            'partnership_conversion_plans',
            'partnership_conversion_plan',
            'formation.conversion_plan.saved',
            $expectedRevision,
            ['plan' => $plan],
        );
    }

    public function addValuation(
        User $user,
        Business $business,
        array $fields,
    ): ?string {
        if (! in_array(
            $fields['review_state'] ?? null,
            ['draft', 'reviewed'],
            true,
        )) {
            throw new InvalidArgumentException(
                'Valuation review state must be Draft or Reviewed.',
            );
        }

        return $this->append(
            $user,
            $business,
            'valuations',
            'valuation',
            'formation.valuation.created',
            $fields,
        );
    }

    private function canManage(User $user, Business $business): bool
    {
        if (! $this->actor->allows(
            $user,
            $business,
            CapabilityCatalog::FORMATION_MANAGE,
        )) {
            return false;
        }

        $this->assertExistingBusiness($business);

        DB::table('businesses')
            ->where('id', $business->getKey())
            ->whereNull('setup_phase')
            ->update([
                'setup_phase' => 'formation',
                'updated_at' => now(),
            ]);

        return true;
    }

    private function assertExistingBusiness(Business $business): void
    {
        if (
            $business->origin_type
            !== BusinessOriginType::ExistingBusinessImportedIntoPbr
        ) {
            throw new InvalidArgumentException(
                'This baseline action belongs to the Existing Business journey.',
            );
        }
    }

    /**
     * @param  array<string, mixed>  $fields
     */
    private function append(
        User $user,
        Business $business,
        string $table,
        string $targetType,
        string $action,
        array $fields,
    ): ?string {
        if (! $this->canManage($user, $business)) {
            return null;
        }

        $id = (string) Str::uuid7();

        DB::table($table)->insert([
            'id' => $id,
            'business_id' => $business->getKey(),
            ...$fields,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->occurrence->record(
            $user,
            $business,
            $action,
            $targetType,
            $id,
            [],
        );

        return $id;
    }

    /**
     * @param  array<string, mixed>  $fields
     * @return array<string, mixed>
     */
    private function saveAndRecord(
        User $user,
        Business $business,
        string $table,
        string $targetType,
        string $action,
        int $expectedRevision,
        array $fields,
    ): array {
        $row = DB::transaction(function () use (
            $table,
            $business,
            $expectedRevision,
            $fields,
        ): array {
            $existing = DB::table($table)
                ->where('business_id', $business->getKey())
                ->lockForUpdate()
                ->first();

            if ($existing === null) {
                if ($expectedRevision !== 0) {
                    throw new StaleRevision(
                        $expectedRevision,
                        0,
                    );
                }

                $id = (string) Str::uuid7();

                DB::table($table)->insert([
                    'id' => $id,
                    'business_id' => $business->getKey(),
                    ...$fields,
                    'revision' => 1,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);

                return [
                    'id' => $id,
                    'revision' => 1,
                ];
            }

            $actual = (int) $existing->revision;

            if ($actual !== $expectedRevision) {
                throw new StaleRevision(
                    $expectedRevision,
                    $actual,
                );
            }

            DB::table($table)
                ->where('id', $existing->id)
                ->where('business_id', $business->getKey())
                ->update([
                    ...$fields,
                    'revision' => $actual + 1,
                    'updated_at' => now(),
                ]);

            return [
                'id' => (string) $existing->id,
                'revision' => $actual + 1,
            ];
        });

        $this->occurrence->record(
            $user,
            $business,
            $action,
            $targetType,
            $row['id'],
            ['revision' => $row['revision']],
        );

        return $row;
    }
}
