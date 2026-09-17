<?php

namespace App\Domain\Access\ValueObjects;

use InvalidArgumentException;

final readonly class Capability
{
    private const int MAX_LENGTH = 160;

    public function __construct(private string $value)
    {
        if ($value === '') {
            throw new InvalidArgumentException(
                'Capability must not be empty.',
            );
        }

        if ($value !== trim($value)) {
            throw new InvalidArgumentException(
                'Capability must not contain leading or trailing whitespace.',
            );
        }

        if (strlen($value) > self::MAX_LENGTH) {
            throw new InvalidArgumentException(
                'Capability exceeds the maximum length.',
            );
        }
    }

    public function value(): string
    {
        return $this->value;
    }

    public function equals(self $other): bool
    {
        return $this->value === $other->value;
    }

    public function __toString(): string
    {
        return $this->value;
    }
}
