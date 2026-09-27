<?php

declare(strict_types=1);

namespace App\Domain\Rewards\Services;

use InvalidArgumentException;

final class DistributionCalculator
{
    /**
     * @return array{
     *   approved_net_profit_minor_units:int,
     *   tax_due_minor_units:int,
     *   debt_due_minor_units:int,
     *   required_reserve_minor_units:int,
     *   reinvestment_minor_units:int,
     *   adjustments_minor_units:int,
     *   distributable_profit_minor_units:int
     * }
     */
    public function waterfall(
        int $approvedNetProfit,
        int $taxDue,
        int $debtDue,
        int $requiredReserve,
        int $reinvestment,
        int $adjustments,
    ): array {
        foreach ([
            $taxDue,
            $debtDue,
            $requiredReserve,
            $reinvestment,
        ] as $nonNegative) {
            if ($nonNegative < 0) {
                throw new InvalidArgumentException(
                    'Distribution deductions must not be negative.',
                );
            }
        }

        $distributable = $approvedNetProfit
            - $taxDue
            - $debtDue
            - $requiredReserve
            - $reinvestment
            + $adjustments;

        return [
            'approved_net_profit_minor_units' => $approvedNetProfit,
            'tax_due_minor_units' => $taxDue,
            'debt_due_minor_units' => $debtDue,
            'required_reserve_minor_units' => $requiredReserve,
            'reinvestment_minor_units' => $reinvestment,
            'adjustments_minor_units' => $adjustments,
            'distributable_profit_minor_units' => max(0, $distributable),
        ];
    }

    /**
     * @param  array<string,string>  $weights  exact non-negative decimal weights
     * @return array<string,int>
     */
    public function allocate(int $poolMinorUnits, array $weights): array
    {
        if ($poolMinorUnits < 0 || $weights === []) {
            throw new InvalidArgumentException(
                'Distribution pool/weights are invalid.',
            );
        }

        $scaled = [];
        $total = 0;

        foreach ($weights as $key => $value) {
            if (! preg_match('/^(?:0|[1-9][0-9]*)(?:\.[0-9]{1,8})?$/', $value)) {
                throw new InvalidArgumentException(
                    'Distribution weights require non-negative 8-decimal values.',
                );
            }

            [$whole, $fraction] = array_pad(explode('.', $value, 2), 2, '');
            $units = ((int) $whole * 100000000)
                + (int) str_pad($fraction, 8, '0');

            $scaled[$key] = $units;
            $total += $units;
        }

        if ($total <= 0) {
            throw new InvalidArgumentException(
                'Distribution weights must contain positive entitlement.',
            );
        }

        ksort($scaled);
        $allocated = [];
        $remainders = [];
        $used = 0;

        foreach ($scaled as $key => $weight) {
            $numerator = $poolMinorUnits * $weight;
            $base = intdiv($numerator, $total);
            $allocated[$key] = $base;
            $remainders[$key] = $numerator % $total;
            $used += $base;
        }

        $remaining = $poolMinorUnits - $used;

        if ($remaining > 0) {
            uksort($remainders, static function (string $a, string $b) use ($remainders): int {
                $byRemainder = $remainders[$b] <=> $remainders[$a];

                return $byRemainder !== 0 ? $byRemainder : strcmp($a, $b);
            });

            foreach (array_keys($remainders) as $key) {
                if ($remaining === 0) {
                    break;
                }

                $allocated[$key]++;
                $remaining--;
            }
        }

        ksort($allocated);

        return $allocated;
    }
}
