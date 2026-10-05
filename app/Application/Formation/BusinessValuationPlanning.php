<?php

declare(strict_types=1);

namespace App\Application\Formation;

use App\Domain\Access\CapabilityCatalog;
use App\Domain\Businesses\Enums\BusinessOriginType;
use App\Infrastructure\Persistence\Eloquent\Businesses\Business;
use App\Infrastructure\Persistence\Eloquent\Formation\BusinessValuationRun;
use App\Infrastructure\Persistence\Eloquent\Identity\User;
use DateTimeImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;

final class BusinessValuationPlanning
{
    private const array EXPLICIT_HISTORICAL_KEYS = [
        'ebitda',
        'owner_earnings',
        'free_cash_flow',
        'debt',
    ];

    public function __construct(
        private readonly FormationActorContext $actor,
        private readonly FormationOccurrence $occurrence,
        private readonly BusinessValuationCalculator $calculator,
    ) {}

    /**
     * @param  array<string,mixed>  $explicitHistorical
     * @param  array<string,mixed>  $assumptions
     * @return array<string,mixed>|null
     */
    public function calculateAndRecord(
        User $user,
        Business $business,
        string $asOfDate,
        array $explicitHistorical,
        array $assumptions,
        string $reviewState = 'draft',
    ): ?array {
        $membership = $this->actor->membership(
            $user,
            $business,
            CapabilityCatalog::FORMATION_MANAGE,
        );

        if ($membership === null) {
            return null;
        }

        $this->assertExistingBusiness($business);
        $this->assertDate($asOfDate);
        $this->assertReviewState($reviewState);
        $this->assertHistoricalKeys($explicitHistorical);

        $resolved = $this->resolveHistorical(
            $business,
            $asOfDate,
            $explicitHistorical,
        );

        $calculation = $this->calculator->calculate(
            $resolved['historical'],
            $assumptions,
        );

        if ($calculation['status'] !== 'ready') {
            throw new InvalidArgumentException(
                'Business valuation requires enough source data and assumptions for at least one supported method.',
            );
        }

        $warnings = array_values(array_unique([
            ...$calculation['warnings'],
            ...$resolved['warnings'],
        ]));

        $id = (string) Str::uuid7();
        $valuationId = (string) Str::uuid7();

        DB::transaction(function () use (
            $id,
            $valuationId,
            $business,
            $membership,
            $asOfDate,
            $reviewState,
            $calculation,
            $resolved,
            $warnings,
        ): void {
            DB::table('valuations')->insert([
                'id' => $valuationId,
                'business_id' => $business->getKey(),
                'as_of_date' => $asOfDate,
                'amount' => $calculation['range']['base'],
                'method' => 'Multi-method indicative '
                    .$calculation['formulaVersion'],
                'review_state' => $reviewState,
                'notes' => 'Indicative planning estimate only; not certified value, transaction price, ownership truth or contribution valuation.',
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            BusinessValuationRun::query()->create([
                'id' => $id,
                'business_id' => $business->getKey(),
                'valuation_id' => $valuationId,
                'as_of_date' => $asOfDate,
                'formula_version' => $calculation['formulaVersion'],
                'review_state' => $reviewState,
                'range_low' => $calculation['range']['low'],
                'base_value' => $calculation['range']['base'],
                'range_high' => $calculation['range']['high'],
                'confidence_level' => $calculation['confidence']['level'],
                'historical_inputs' => $calculation['historical'],
                'assumptions' => $calculation['assumptions'],
                'source_provenance' => $resolved['provenance'],
                'method_results' => $calculation['methods'],
                'warnings' => $warnings,
                'semantics' => $calculation['semantics'],
                'created_by_membership_id' => $membership->getKey(),
                'created_at' => now(),
            ]);

            DB::table('businesses')
                ->where('id', $business->getKey())
                ->whereNull('setup_phase')
                ->update([
                    'setup_phase' => 'formation',
                    'updated_at' => now(),
                ]);
        });

        $this->occurrence->record(
            $user,
            $business,
            'formation.business_valuation.calculated',
            'business_valuation_run',
            $id,
            [
                'formula_version' => $calculation['formulaVersion'],
                'valuation_id' => $valuationId,
                'method_count' => $calculation['confidence']['usableMethodCount'],
                'confidence_level' => $calculation['confidence']['level'],
                'review_state' => $reviewState,
            ],
        );

        return [
            'id' => $id,
            'baselineValuationId' => $valuationId,
            'asOfDate' => $asOfDate,
            'formulaVersion' => $calculation['formulaVersion'],
            'reviewState' => $reviewState,
            'historical' => $calculation['historical'],
            'assumptions' => $calculation['assumptions'],
            'provenance' => $resolved['provenance'],
            'methods' => $calculation['methods'],
            'range' => $calculation['range'],
            'confidence' => $calculation['confidence'],
            'warnings' => $warnings,
            'semantics' => $calculation['semantics'],
        ];
    }

    /**
     * @param  array<string,mixed>  $explicitHistorical
     * @return array{
     *   historical:array<string,mixed>,
     *   provenance:array<string,mixed>,
     *   warnings:list<string>
     * }
     */
    private function resolveHistorical(
        Business $business,
        string $asOfDate,
        array $explicitHistorical,
    ): array {
        $businessId = (string) $business->getKey();

        $snapshot = DB::table('financial_snapshots')
            ->where('business_id', $businessId)
            ->whereDate('as_of_date', '<=', $asOfDate)
            ->orderByDesc('as_of_date')
            ->orderByDesc('created_at')
            ->first();

        $assets = DB::table('business_assets')
            ->where('business_id', $businessId)
            ->orderBy('id')
            ->get(['id', 'name', 'estimated_value']);

        $liabilities = DB::table('business_liabilities')
            ->where('business_id', $businessId)
            ->orderBy('id')
            ->get(['id', 'name', 'outstanding_amount']);

        $assetTotal = $assets->isEmpty()
            ? null
            : $this->sumMoney($assets->pluck('estimated_value')->all());

        $liabilityTotal = $liabilities->isEmpty()
            ? ($assets->isEmpty() ? null : '0.00')
            : $this->sumMoney($liabilities->pluck('outstanding_amount')->all());

        $historical = [
            'ebitda' => $explicitHistorical['ebitda'] ?? null,
            'owner_earnings' => $explicitHistorical['owner_earnings'] ?? null,
            'free_cash_flow' => $explicitHistorical['free_cash_flow'] ?? null,
            'cash' => $snapshot?->cash,
            'debt' => $explicitHistorical['debt'] ?? null,
            'total_assets' => $assetTotal,
            'total_liabilities' => $liabilityTotal,
        ];

        $explicitFields = collect(self::EXPLICIT_HISTORICAL_KEYS)
            ->filter(
                static fn (string $key): bool => array_key_exists(
                    $key,
                    $explicitHistorical,
                ) && $explicitHistorical[$key] !== null
                    && $explicitHistorical[$key] !== '',
            )
            ->values()
            ->all();

        $provenance = [
            'financialSnapshot' => $snapshot === null
                ? null
                : [
                    'id' => (string) $snapshot->id,
                    'asOfDate' => (string) $snapshot->as_of_date,
                    'values' => [
                        'revenue' => (string) $snapshot->revenue,
                        'expenses' => (string) $snapshot->expenses,
                        'cash' => (string) $snapshot->cash,
                        'receivables' => (string) $snapshot->receivables,
                        'payables' => (string) $snapshot->payables,
                    ],
                ],
            'assets' => $assets
                ->map(static fn (object $row): array => [
                    'id' => (string) $row->id,
                    'name' => (string) $row->name,
                    'value' => (string) $row->estimated_value,
                ])
                ->values()
                ->all(),
            'liabilities' => $liabilities
                ->map(static fn (object $row): array => [
                    'id' => (string) $row->id,
                    'name' => (string) $row->name,
                    'value' => (string) $row->outstanding_amount,
                ])
                ->values()
                ->all(),
            'explicitHistoricalFields' => $explicitFields,
        ];

        $warnings = [];

        if ($snapshot === null) {
            $warnings[] = 'No financial snapshot on or before the valuation date; cash-dependent methods may be unavailable.';
        }

        if (! $assets->isEmpty() && $liabilities->isEmpty()) {
            $warnings[] = 'No liability records are recorded; Asset-Based uses zero recorded liabilities.';
        }

        return [
            'historical' => $historical,
            'provenance' => $provenance,
            'warnings' => $warnings,
        ];
    }

    /**
     * @param  list<mixed>  $values
     */
    private function sumMoney(array $values): string
    {
        $minorUnits = 0;

        foreach ($values as $value) {
            $raw = trim((string) $value);

            if (preg_match('/\A(\d+)(?:\.(\d{1,2}))?\z/', $raw, $matches) !== 1) {
                throw new InvalidArgumentException(
                    'Stored Business Valuation money source is invalid.',
                );
            }

            $minorUnits += ((int) $matches[1] * 100)
                + (int) str_pad($matches[2] ?? '', 2, '0');
        }

        return intdiv($minorUnits, 100).'.'.str_pad(
            (string) ($minorUnits % 100),
            2,
            '0',
            STR_PAD_LEFT,
        );
    }

    /**
     * @param  array<string,mixed>  $input
     */
    private function assertHistoricalKeys(array $input): void
    {
        $unexpected = array_diff(
            array_keys($input),
            self::EXPLICIT_HISTORICAL_KEYS,
        );

        if ($unexpected !== []) {
            throw new InvalidArgumentException(
                'Unexpected explicit historical Business Valuation input: '
                .implode(', ', $unexpected),
            );
        }
    }

    private function assertDate(string $date): void
    {
        $parsed = DateTimeImmutable::createFromFormat('!Y-m-d', $date);

        if ($parsed === false || $parsed->format('Y-m-d') !== $date) {
            throw new InvalidArgumentException(
                'Business valuation as-of date must use YYYY-MM-DD.',
            );
        }
    }

    private function assertReviewState(string $state): void
    {
        if (! in_array($state, ['draft', 'reviewed'], true)) {
            throw new InvalidArgumentException(
                'Business valuation review state must be Draft or Reviewed.',
            );
        }
    }

    private function assertExistingBusiness(Business $business): void
    {
        if (
            $business->origin_type
            !== BusinessOriginType::ExistingBusinessImportedIntoPbr
        ) {
            throw new InvalidArgumentException(
                'Business Valuation belongs to the Existing Business journey.',
            );
        }
    }
}
