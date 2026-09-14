<?php

namespace App\Domain\Identity\ValueObjects;

use DateTimeZone;
use InvalidArgumentException;
use Stringable;

final readonly class TimezoneIdentifier implements Stringable
{
    private function __construct(
        private string $value,
    ) {}

    public static function from(string $value): self
    {
        if (! in_array(
            $value,
            DateTimeZone::listIdentifiers(DateTimeZone::ALL_WITH_BC),
            true,
        )) {
            throw new InvalidArgumentException('Invalid IANA timezone identifier.');
        }

        return new self($value);
    }

    public function value(): string
    {
        return $this->value;
    }

    public function __toString(): string
    {
        return $this->value;
    }
}
