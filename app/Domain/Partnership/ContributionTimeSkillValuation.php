<?php

declare(strict_types=1);

namespace App\Domain\Partnership;

use App\Domain\Partnership\ValueObjects\ContributionValue;
use InvalidArgumentException;

final class ContributionTimeSkillValuation
{
    /**
     * @return array{
     *   fairWorkValue:string,
     *   cashCompensationReceived:string,
     *   sweatContributionValue:string,
     *   discrepancy:?string
     * }
     */
    public function calculate(
        string $hoursPerMonth,
        ContributionValue $fairMarketRate,
        int $numberOfMonths,
        ?ContributionValue $cashCompensationReceived = null,
    ): array {
        $hoursScaled = $this->scaledHundredths(
            $hoursPerMonth,
        );

        if ($numberOfMonths < 1) {
            throw new InvalidArgumentException(
                'Number of Months must be at least 1.',
            );
        }

        $rateMinor = $fairMarketRate->minorUnits();
        $cashMinor = (
            $cashCompensationReceived
            ?? new ContributionValue('0')
        )->minorUnits();

        if (
            $hoursScaled !== 0
            && $rateMinor > intdiv(
                PHP_INT_MAX,
                $hoursScaled,
            )
        ) {
            throw new InvalidArgumentException(
                'Time and Skill calculation exceeds exact integer capacity.',
            );
        }

        $monthlyScaledMinor =
            $hoursScaled * $rateMinor;

        if (
            $numberOfMonths !== 0
            && $monthlyScaledMinor > intdiv(
                PHP_INT_MAX,
                $numberOfMonths,
            )
        ) {
            throw new InvalidArgumentException(
                'Time and Skill calculation exceeds exact integer capacity.',
            );
        }

        $grossHundredthMinor =
            $monthlyScaledMinor * $numberOfMonths;
        $grossMinor = intdiv(
            $grossHundredthMinor,
            100,
        );

        if (($grossHundredthMinor % 100) >= 50) {
            $grossMinor++;
        }

        $netMinor = $grossMinor - $cashMinor;
        $discrepancy = $netMinor < 0
            ? 'cash_compensation_exceeds_fair_work_value'
            : null;
        $netMinor = max(0, $netMinor);

        return [
            'fairWorkValue' => $this->decimal(
                $grossMinor,
            ),
            'cashCompensationReceived' => $this->decimal($cashMinor),
            'sweatContributionValue' => $this->decimal($netMinor),
            'discrepancy' => $discrepancy,
        ];
    }

    private function scaledHundredths(
        string $value,
    ): int {
        $value = trim($value);

        if (
            preg_match(
                '/\A(?:0|[1-9][0-9]{0,7})(?:\.[0-9]{1,2})?\z/',
                $value,
            ) !== 1
        ) {
            throw new InvalidArgumentException(
                'Hours per Month must be a non-negative decimal with at most 2 decimal places.',
            );
        }

        [$whole, $fraction] = array_pad(
            explode('.', $value, 2),
            2,
            '',
        );

        return ((int) $whole * 100)
            + (int) str_pad(
                $fraction,
                2,
                '0',
            );
    }

    private function decimal(
        int $minorUnits,
    ): string {
        return intdiv(
            $minorUnits,
            100,
        ).'.'.str_pad(
            (string) ($minorUnits % 100),
            2,
            '0',
            STR_PAD_LEFT,
        );
    }
}
