<?php

declare(strict_types=1);

namespace App\Domain\Partnership\ValueObjects;

use InvalidArgumentException;

final readonly class ContributionValue
{
    public string $amount;

    public function __construct(string $amount)
    {
        $amount = trim($amount);

        if (
            preg_match(
                '/\A(?:0|[1-9][0-9]{0,15})(?:\.[0-9]{1,2})?\z/',
                $amount,
            ) !== 1
        ) {
            throw new InvalidArgumentException(
                'Contribution value must use non-negative fixed two-decimal precision.',
            );
        }

        [$whole, $fraction] = array_pad(
            explode('.', $amount, 2),
            2,
            '',
        );

        $this->amount = $whole.'.'.str_pad(
            $fraction,
            2,
            '0',
        );
    }

    public function minorUnits(): int
    {
        [$whole, $fraction] = explode('.', $this->amount);

        return ((int) $whole * 100) + (int) $fraction;
    }

    public function lessThanOrEqual(self $other): bool
    {
        return $this->minorUnits() <= $other->minorUnits();
    }
}
