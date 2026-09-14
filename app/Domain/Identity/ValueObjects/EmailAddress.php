<?php

namespace App\Domain\Identity\ValueObjects;

use InvalidArgumentException;
use Stringable;

final readonly class EmailAddress implements Stringable
{
    private function __construct(
        private string $value,
    ) {}

    public static function from(string $value): self
    {
        $canonical = strtolower(trim($value));

        if (
            $canonical === ''
            || strlen($canonical) > 254
            || filter_var($canonical, FILTER_VALIDATE_EMAIL) === false
        ) {
            throw new InvalidArgumentException('Invalid email address.');
        }

        return new self($canonical);
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
