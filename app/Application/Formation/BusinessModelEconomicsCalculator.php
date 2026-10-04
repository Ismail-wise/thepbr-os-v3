<?php

declare(strict_types=1);

namespace App\Application\Formation;

final class BusinessModelEconomicsCalculator
{
    /**
     * @param  array<string,mixed>|null  $profile
     * @return array<string,mixed>
     */
    public function calculate(?array $profile): array
    {
        if ($profile === null) {
            return $this->emptyResult('incomplete');
        }

        $price = $this->money($profile['average_selling_price'] ?? null);
        $variable = $this->money($profile['variable_cost_per_unit'] ?? null);
        $fixed = $this->money($profile['monthly_fixed_cost'] ?? null);
        $units = $this->quantity($profile['expected_monthly_units'] ?? null);

        if ($price === null || $variable === null || $fixed === null) {
            return $this->emptyResult('incomplete');
        }

        $contribution = $price - $variable;

        if ($price <= 0) {
            return [
                ...$this->emptyResult('invalid_price'),
                'contributionMarginPerUnit' => $this->moneyString($contribution),
            ];
        }

        $grossMarginPercent = round(($contribution / $price) * 100, 2);

        if ($contribution <= 0) {
            return [
                ...$this->emptyResult('non_positive_margin'),
                'contributionMarginPerUnit' => $this->moneyString($contribution),
                'grossMarginPercent' => $grossMarginPercent,
            ];
        }

        $breakEvenUnits = round($fixed / $contribution, 2);
        $breakEvenRevenue = (int) round($breakEvenUnits * $price);

        $result = [
            'status' => 'ready',
            'contributionMarginPerUnit' => $this->moneyString($contribution),
            'grossMarginPercent' => $grossMarginPercent,
            'breakEvenUnits' => $breakEvenUnits,
            'breakEvenRevenue' => $this->moneyString($breakEvenRevenue),
            'expectedMonthlyRevenue' => null,
            'expectedMonthlyGrossProfit' => null,
            'expectedMonthlyOperatingProfit' => null,
        ];

        if ($units === null) {
            return $result;
        }

        $expectedRevenue = (int) round($price * $units);
        $expectedGrossProfit = (int) round($contribution * $units);
        $expectedOperatingProfit = $expectedGrossProfit - $fixed;

        return [
            ...$result,
            'expectedMonthlyRevenue' => $this->moneyString($expectedRevenue),
            'expectedMonthlyGrossProfit' => $this->moneyString(
                $expectedGrossProfit,
            ),
            'expectedMonthlyOperatingProfit' => $this->moneyString(
                $expectedOperatingProfit,
            ),
        ];
    }

    /**
     * Convert a decimal money value into integer minor units.
     */
    private function money(mixed $value): ?int
    {
        if ($value === null || $value === '') {
            return null;
        }

        $value = trim((string) $value);

        if (preg_match('/\A(\d+)(?:\.(\d{1,2}))?\z/', $value, $matches) !== 1) {
            return null;
        }

        $whole = (int) $matches[1];
        $fraction = str_pad($matches[2] ?? '', 2, '0');

        return ($whole * 100) + (int) $fraction;
    }

    private function quantity(mixed $value): ?float
    {
        if ($value === null || $value === '') {
            return null;
        }

        if (! is_numeric($value)) {
            return null;
        }

        $quantity = (float) $value;

        return $quantity >= 0 ? $quantity : null;
    }

    private function moneyString(int $minorUnits): string
    {
        $negative = $minorUnits < 0;
        $absolute = abs($minorUnits);
        $formatted = intdiv($absolute, 100).'.'.str_pad(
            (string) ($absolute % 100),
            2,
            '0',
            STR_PAD_LEFT,
        );

        return $negative ? '-'.$formatted : $formatted;
    }

    /**
     * @return array<string,mixed>
     */
    private function emptyResult(string $status): array
    {
        return [
            'status' => $status,
            'contributionMarginPerUnit' => null,
            'grossMarginPercent' => null,
            'breakEvenUnits' => null,
            'breakEvenRevenue' => null,
            'expectedMonthlyRevenue' => null,
            'expectedMonthlyGrossProfit' => null,
            'expectedMonthlyOperatingProfit' => null,
        ];
    }
}
