<?php

declare(strict_types=1);

namespace App\Application\Formation;

use InvalidArgumentException;

final class BusinessValuationCalculator
{
    public const string FORMULA_VERSION = 'business-valuation-v1';

    private const array HISTORICAL_KEYS = [
        'ebitda',
        'owner_earnings',
        'free_cash_flow',
        'cash',
        'debt',
        'total_assets',
        'total_liabilities',
    ];

    private const array ASSUMPTION_KEYS = [
        'ebitda_multiple',
        'sde_multiple',
        'growth_rate_percent',
        'discount_rate_percent',
        'terminal_growth_rate_percent',
    ];

    /**
     * @param  array<string,mixed>  $historical
     * @param  array<string,mixed>  $assumptions
     * @return array<string,mixed>
     */
    public function calculate(array $historical, array $assumptions): array
    {
        $this->assertOnlyKeys($historical, self::HISTORICAL_KEYS, 'historical');
        $this->assertOnlyKeys($assumptions, self::ASSUMPTION_KEYS, 'assumption');

        $historical = $this->normalizeHistorical($historical);
        $assumptions = $this->normalizeAssumptions($assumptions);
        $methods = [];
        $warnings = [];

        $this->calculateEbitda($historical, $assumptions, $methods, $warnings);
        $this->calculateSde($historical, $assumptions, $methods, $warnings);
        $this->calculateAssetBased($historical, $methods, $warnings);
        $this->calculateDcf($historical, $assumptions, $methods, $warnings);

        $values = array_map(
            static fn (array $method): float => (float) $method['value'],
            $methods,
        );
        sort($values);

        if ($values === []) {
            return [
                'status' => 'insufficient',
                'formulaVersion' => self::FORMULA_VERSION,
                'historical' => $historical,
                'assumptions' => $assumptions,
                'methods' => [],
                'range' => null,
                'confidence' => [
                    'level' => 'low',
                    'usableMethodCount' => 0,
                ],
                'warnings' => array_values(array_unique($warnings)),
                'semantics' => $this->semantics(),
            ];
        }

        $base = $this->median($values);
        $low = $values[0];
        $high = $values[array_key_last($values)];
        $methodCount = count($values);

        if ($methodCount === 1) {
            $warnings[] = 'Only one valuation method is usable; the range is method-constrained.';
        }

        return [
            'status' => 'ready',
            'formulaVersion' => self::FORMULA_VERSION,
            'historical' => $historical,
            'assumptions' => $assumptions,
            'methods' => $methods,
            'range' => [
                'low' => $this->moneyString($low),
                'base' => $this->moneyString($base),
                'high' => $this->moneyString($high),
                'basis' => 'available_method_spread',
            ],
            'confidence' => [
                'level' => match (true) {
                    $methodCount >= 3 => 'high',
                    $methodCount === 2 => 'medium',
                    default => 'low',
                },
                'usableMethodCount' => $methodCount,
            ],
            'warnings' => array_values(array_unique($warnings)),
            'semantics' => $this->semantics(),
        ];
    }

    private function calculateEbitda(
        array $historical,
        array $assumptions,
        array &$methods,
        array &$warnings,
    ): void {
        $required = [
            $historical['ebitda'],
            $historical['cash'],
            $historical['debt'],
            $assumptions['ebitda_multiple'],
        ];

        if ($this->hasMissing($required)) {
            $warnings[] = 'EBITDA Multiple skipped because required historical data or the multiple is missing.';

            return;
        }

        if ((float) $historical['ebitda'] <= 0 || (float) $assumptions['ebitda_multiple'] <= 0) {
            $warnings[] = 'EBITDA Multiple skipped because EBITDA and its multiple must be positive.';

            return;
        }

        $value = max(
            0,
            ((float) $historical['ebitda'] * (float) $assumptions['ebitda_multiple'])
                + (float) $historical['cash']
                - (float) $historical['debt'],
        );

        if ($value <= 0) {
            $warnings[] = 'EBITDA Multiple did not produce a positive indicative value.';

            return;
        }

        $methods['ebitda_multiple'] = [
            'label' => 'EBITDA Multiple',
            'value' => $this->moneyString($value),
            'formula' => '(EBITDA x multiple) + cash - debt',
        ];
    }

    private function calculateSde(
        array $historical,
        array $assumptions,
        array &$methods,
        array &$warnings,
    ): void {
        $required = [
            $historical['owner_earnings'],
            $historical['cash'],
            $historical['debt'],
            $assumptions['sde_multiple'],
        ];

        if ($this->hasMissing($required)) {
            $warnings[] = 'Owner Earnings / SDE skipped because required historical data or the multiple is missing.';

            return;
        }

        if ((float) $historical['owner_earnings'] <= 0 || (float) $assumptions['sde_multiple'] <= 0) {
            $warnings[] = 'Owner Earnings / SDE skipped because earnings and its multiple must be positive.';

            return;
        }

        $value = max(
            0,
            ((float) $historical['owner_earnings'] * (float) $assumptions['sde_multiple'])
                + (float) $historical['cash']
                - (float) $historical['debt'],
        );

        if ($value <= 0) {
            $warnings[] = 'Owner Earnings / SDE did not produce a positive indicative value.';

            return;
        }

        $methods['owner_earnings_sde'] = [
            'label' => 'Owner Earnings / SDE',
            'value' => $this->moneyString($value),
            'formula' => '(Owner Earnings / SDE x multiple) + cash - debt',
        ];
    }

    private function calculateAssetBased(
        array $historical,
        array &$methods,
        array &$warnings,
    ): void {
        if (
            $historical['total_assets'] === null
            || $historical['total_liabilities'] === null
        ) {
            $warnings[] = 'Asset-Based skipped because recorded asset or liability totals are incomplete.';

            return;
        }

        if ((float) $historical['total_assets'] <= 0) {
            $warnings[] = 'Asset-Based skipped because recorded assets are not positive.';

            return;
        }

        $value = max(
            0,
            (float) $historical['total_assets']
                - (float) $historical['total_liabilities'],
        );

        if ($value <= 0) {
            $warnings[] = 'Asset-Based did not produce a positive indicative value.';

            return;
        }

        $methods['asset_based'] = [
            'label' => 'Asset-Based',
            'value' => $this->moneyString($value),
            'formula' => 'total assets - total liabilities',
        ];
    }

    private function calculateDcf(
        array $historical,
        array $assumptions,
        array &$methods,
        array &$warnings,
    ): void {
        $required = [
            $historical['free_cash_flow'],
            $historical['cash'],
            $historical['debt'],
            $assumptions['growth_rate_percent'],
            $assumptions['discount_rate_percent'],
            $assumptions['terminal_growth_rate_percent'],
        ];

        if ($this->hasMissing($required)) {
            $warnings[] = 'DCF skipped because required historical data or assumptions are missing.';

            return;
        }

        $fcf = (float) $historical['free_cash_flow'];
        $growth = (float) $assumptions['growth_rate_percent'];
        $discount = (float) $assumptions['discount_rate_percent'];
        $terminalGrowth = (float) $assumptions['terminal_growth_rate_percent'];

        if ($fcf <= 0) {
            $warnings[] = 'DCF skipped because Free Cash Flow must be positive.';

            return;
        }

        if ($growth < -20 || $growth > 30) {
            $warnings[] = 'DCF skipped because growth must be between -20% and 30% for this formula version.';

            return;
        }

        if ($discount <= 0 || $discount > 60) {
            $warnings[] = 'DCF skipped because discount rate must be above 0% and no more than 60%.';

            return;
        }

        if ($terminalGrowth < 0 || $terminalGrowth > 15) {
            $warnings[] = 'DCF skipped because terminal growth must be between 0% and 15%.';

            return;
        }

        if ($discount <= $terminalGrowth) {
            $warnings[] = 'DCF skipped because discount rate must be greater than terminal growth.';

            return;
        }

        $growth /= 100;
        $discount /= 100;
        $terminalGrowth /= 100;

        $presentValue = 0.0;
        $futureCashFlow = $fcf;

        for ($year = 1; $year <= 5; $year++) {
            $futureCashFlow *= 1 + $growth;
            $presentValue += $futureCashFlow / pow(1 + $discount, $year);
        }

        $terminalValue = (
            $futureCashFlow * (1 + $terminalGrowth)
        ) / ($discount - $terminalGrowth);

        $value = max(
            0,
            $presentValue
                + ($terminalValue / pow(1 + $discount, 5))
                + (float) $historical['cash']
                - (float) $historical['debt'],
        );

        if ($value <= 0) {
            $warnings[] = 'DCF did not produce a positive indicative value.';

            return;
        }

        $methods['discounted_cash_flow'] = [
            'label' => 'Discounted Cash Flow',
            'value' => $this->moneyString($value),
            'formula' => '5-year FCF projection + terminal value + cash - debt',
        ];
    }

    /**
     * @param  array<string,mixed>  $input
     * @return array<string,?string>
     */
    private function normalizeHistorical(array $input): array
    {
        $normalized = [];

        foreach (self::HISTORICAL_KEYS as $key) {
            $normalized[$key] = $this->decimal(
                $input[$key] ?? null,
                $key,
                allowNegative: false,
            );
        }

        return $normalized;
    }

    /**
     * @param  array<string,mixed>  $input
     * @return array<string,?string>
     */
    private function normalizeAssumptions(array $input): array
    {
        $normalized = [];

        foreach (self::ASSUMPTION_KEYS as $key) {
            $normalized[$key] = $this->decimal(
                $input[$key] ?? null,
                $key,
                allowNegative: $key === 'growth_rate_percent',
                scale: 4,
            );
        }

        return $normalized;
    }

    private function decimal(
        mixed $value,
        string $field,
        bool $allowNegative,
        int $scale = 2,
    ): ?string {
        if ($value === null || $value === '') {
            return null;
        }

        $raw = trim((string) $value);
        $pattern = $allowNegative
            ? '/\A-?\d+(?:\.\d+)?\z/'
            : '/\A\d+(?:\.\d+)?\z/';

        if (preg_match($pattern, $raw) !== 1) {
            throw new InvalidArgumentException(
                "Business valuation {$field} must be numeric.",
            );
        }

        $number = (float) $raw;

        if (! $allowNegative && $number < 0) {
            throw new InvalidArgumentException(
                "Business valuation {$field} cannot be negative.",
            );
        }

        return number_format($number, $scale, '.', '');
    }

    /**
     * @param  array<string,mixed>  $input
     * @param  list<string>  $allowed
     */
    private function assertOnlyKeys(
        array $input,
        array $allowed,
        string $kind,
    ): void {
        $unexpected = array_diff(array_keys($input), $allowed);

        if ($unexpected !== []) {
            throw new InvalidArgumentException(
                'Unexpected business valuation '.$kind.' input: '.implode(', ', $unexpected),
            );
        }
    }

    /**
     * @param  list<?string>  $values
     */
    private function hasMissing(array $values): bool
    {
        return in_array(null, $values, true);
    }

    /**
     * @param  list<float>  $values
     */
    private function median(array $values): float
    {
        $count = count($values);
        $middle = intdiv($count, 2);

        if ($count % 2 === 1) {
            return $values[$middle];
        }

        return ($values[$middle - 1] + $values[$middle]) / 2;
    }

    private function moneyString(float $value): string
    {
        return number_format(round($value, 2), 2, '.', '');
    }

    /**
     * @return array<string,bool>
     */
    private function semantics(): array
    {
        return [
            'indicativeEstimate' => true,
            'guaranteedMarketValue' => false,
            'independentCertifiedValuation' => false,
            'transactionPrice' => false,
            'ownershipTruth' => false,
            'contributionValuation' => false,
        ];
    }
}
