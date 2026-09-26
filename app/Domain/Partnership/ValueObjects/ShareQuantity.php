<?php

declare(strict_types=1);

namespace App\Domain\Partnership\ValueObjects;

use InvalidArgumentException;

final readonly class ShareQuantity
{
    private const SCALE = 8;

    public function __construct(
        private string $value,
    ) {
        if (! preg_match('/^\d+(?:\.\d{1,8})?$/', $value)) {
            throw new InvalidArgumentException(
                'Share quantity must be a non-negative decimal with at most 8 decimal places.',
            );
        }
    }

    public static function fromAcceptedValue(
        int $acceptedMinorUnits,
        int $shareValueMinorUnits,
    ): self {
        if ($acceptedMinorUnits < 0) {
            throw new InvalidArgumentException(
                'Accepted Contribution Value cannot be negative.',
            );
        }

        if ($shareValueMinorUnits <= 0) {
            throw new InvalidArgumentException(
                'Share Value must be greater than zero.',
            );
        }

        $whole = intdiv(
            $acceptedMinorUnits,
            $shareValueMinorUnits,
        );

        $remainder = $acceptedMinorUnits % $shareValueMinorUnits;

        if ($remainder === 0) {
            return new self((string) $whole);
        }

        $digits = '';
        $remaining = $remainder;

        for ($position = 0; $position < self::SCALE; $position++) {
            if ($remaining > intdiv(PHP_INT_MAX, 10)) {
                throw new InvalidArgumentException(
                    'Share conversion exceeds supported integer precision.',
                );
            }

            $remaining *= 10;

            $digit = intdiv(
                $remaining,
                $shareValueMinorUnits,
            );

            $digits .= (string) $digit;

            $remaining %= $shareValueMinorUnits;
        }

        if ($remaining !== 0) {
            throw new InvalidArgumentException(
                'Share conversion exceeds supported 8-decimal precision. Adjust the approved share-value/share-structure rule instead of silently rounding.',
            );
        }

        return new self(
            $whole.'.'.rtrim($digits, '0'),
        );
    }

    public function value(): string
    {
        return $this->value;
    }
}
