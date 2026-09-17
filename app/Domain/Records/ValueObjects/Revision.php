<?php

declare(strict_types=1);

namespace App\Domain\Records\ValueObjects;

use InvalidArgumentException;

final readonly class Revision
{
    public function __construct(
        public int $value,
    ) {
        if ($value < 1) {
            throw new InvalidArgumentException('Revision must be at least 1.');
        }
    }

    public function matches(int $expected): bool
    {
        return $this->value === $expected;
    }

    public function next(): self
    {
        return new self($this->value + 1);
    }
}
