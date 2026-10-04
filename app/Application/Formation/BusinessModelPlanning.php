<?php

declare(strict_types=1);

namespace App\Application\Formation;

use App\Domain\Access\CapabilityCatalog;
use App\Domain\Records\Exceptions\StaleRevision;
use App\Infrastructure\Persistence\Eloquent\Businesses\Business;
use App\Infrastructure\Persistence\Eloquent\Identity\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;

final class BusinessModelPlanning
{
    public function __construct(
        private readonly FormationActorContext $actor,
        private readonly FormationOccurrence $occurrence,
    ) {}

    /**
     * @param  array<string, ?string>  $blocks
     * @return array{id:string,revision:int}|null
     */
    public function saveBmc(
        User $user,
        Business $business,
        int $expectedRevision,
        array $blocks,
    ): ?array {
        if (
            ! $this->actor->allows(
                $user,
                $business,
                CapabilityCatalog::BUSINESS_MODEL_MANAGE,
            )
        ) {
            return null;
        }

        $expected = [
            'customer_segments',
            'value_propositions',
            'channels',
            'customer_relationships',
            'revenue_streams',
            'key_resources',
            'key_activities',
            'key_partnerships',
            'cost_structure',
        ];

        if (array_keys($blocks) !== $expected) {
            throw new InvalidArgumentException(
                'BMC must use exactly the nine canonical blocks.',
            );
        }

        $row = DB::transaction(function () use (
            $business,
            $expectedRevision,
            $blocks,
        ): array {
            $existing = DB::table('business_model_canvases')
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

                DB::table('business_model_canvases')->insert([
                    'id' => $id,
                    'business_id' => $business->getKey(),
                    ...$blocks,
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

            DB::table('business_model_canvases')
                ->where('id', $existing->id)
                ->where('business_id', $business->getKey())
                ->update([
                    ...$blocks,
                    'revision' => $actual + 1,
                    'updated_at' => now(),
                ]);

            return [
                'id' => (string) $existing->id,
                'revision' => $actual + 1,
            ];
        });

        DB::table('businesses')
            ->where('id', $business->getKey())
            ->whereNull('setup_phase')
            ->update([
                'setup_phase' => 'formation',
                'updated_at' => now(),
            ]);

        $this->occurrence->record(
            $user,
            $business,
            'formation.bmc.saved',
            'business_model_canvas',
            $row['id'],
            ['revision' => $row['revision']],
        );

        return $row;
    }

    /**
     * @param  array<string,?string>  $fields
     * @return array{id:string,revision:int}|null
     */
    public function saveOperatingProfile(
        User $user,
        Business $business,
        int $expectedRevision,
        array $fields,
    ): ?array {
        if (
            ! $this->actor->allows(
                $user,
                $business,
                CapabilityCatalog::BUSINESS_MODEL_MANAGE,
            )
        ) {
            return null;
        }

        $expected = [
            'business_purpose',
            'market',
            'location',
            'competition_alternatives',
            'operating_model',
            'excluded_activities',
            'pricing_notes',
            'unit_name',
            'average_selling_price',
            'variable_cost_per_unit',
            'monthly_fixed_cost',
            'expected_monthly_units',
            'scalability_strategy',
            'scalability_constraints',
            'first_12_month_plan',
        ];

        if (array_keys($fields) !== $expected) {
            throw new InvalidArgumentException(
                'Business model operating profile must use the canonical field set.',
            );
        }

        $row = DB::transaction(function () use (
            $business,
            $expectedRevision,
            $fields,
        ): array {
            $existing = DB::table('business_model_operating_profiles')
                ->where('business_id', $business->getKey())
                ->lockForUpdate()
                ->first();

            if ($existing === null) {
                if ($expectedRevision !== 0) {
                    throw new StaleRevision($expectedRevision, 0);
                }

                $id = (string) Str::uuid7();

                DB::table('business_model_operating_profiles')->insert([
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
                throw new StaleRevision($expectedRevision, $actual);
            }

            DB::table('business_model_operating_profiles')
                ->where('business_id', $business->getKey())
                ->where('id', $existing->id)
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

        DB::table('businesses')
            ->where('id', $business->getKey())
            ->whereNull('setup_phase')
            ->update([
                'setup_phase' => 'formation',
                'updated_at' => now(),
            ]);

        $this->occurrence->record(
            $user,
            $business,
            'formation.business_model_foundation.saved',
            'business_model_operating_profile',
            $row['id'],
            ['revision' => $row['revision']],
        );

        return $row;
    }
}
