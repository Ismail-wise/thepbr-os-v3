<?php

declare(strict_types=1);

namespace App\Domain\Partnership\ValueObjects;

use InvalidArgumentException;

final readonly class EntitlementRatio
{
    public function __construct(
        private string $value,
    ) {
        if (
            ! preg_match('/^\d+(?:\.\d{1,8})?$/', $value)
            || (float) $value < 0
        ) {
            throw new InvalidArgumentException(
                'Entitlement ratio must be a non-negative fixed-precision decimal.',
            );
        }
    }

    public function value(): string
    {
        return $this->value;
    }
}
