<?php

declare(strict_types=1);

namespace App\Domain\Records\ValueObjects;

use DateTimeImmutable;
use InvalidArgumentException;

final readonly class LifecycleMoments
{
    /**
     * effectiveUntil is a planned/contractual end known before freeze.
     * Actual supersession termination is represented separately by the
     * immutable supersession relation and never by mutating frozen history.
     */
    public function __construct(
        public DateTimeImmutable $createdAt,
        public ?DateTimeImmutable $approvedAt = null,
        public ?DateTimeImmutable $signedAt = null,
        public ?DateTimeImmutable $effectiveFrom = null,
        public ?DateTimeImmutable $effectiveUntil = null,
    ) {
        if ($effectiveUntil !== null && $effectiveFrom === null) {
            throw new InvalidArgumentException(
                'An effective-until value requires an effective-from value.',
            );
        }

        if (
            $effectiveFrom !== null
            && $effectiveUntil !== null
            && $effectiveUntil <= $effectiveFrom
        ) {
            throw new InvalidArgumentException(
                'Effective-until must be later than effective-from.',
            );
        }
    }
}
