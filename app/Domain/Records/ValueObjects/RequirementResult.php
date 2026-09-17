<?php

declare(strict_types=1);

namespace App\Domain\Records\ValueObjects;

use App\Domain\Records\Enums\RequirementOutcome;
use InvalidArgumentException;

final readonly class RequirementResult
{
    public function __construct(
        public string $code,
        public RequirementOutcome $outcome,
        public ?string $message = null,
    ) {
        if (trim($code) === '') {
            throw new InvalidArgumentException('Requirement code must not be empty.');
        }
    }

    public static function met(string $code, ?string $message = null): self
    {
        return new self($code, RequirementOutcome::Met, $message);
    }

    public static function warning(string $code, ?string $message = null): self
    {
        return new self($code, RequirementOutcome::Warning, $message);
    }

    public static function blocked(string $code, ?string $message = null): self
    {
        return new self($code, RequirementOutcome::Blocked, $message);
    }

    public function blocksTransition(): bool
    {
        return $this->outcome === RequirementOutcome::Blocked;
    }
}
