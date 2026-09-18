<?php

declare(strict_types=1);

namespace App\Domain\Events\ValueObjects;

use InvalidArgumentException;

final readonly class OccurrenceTarget
{
    public function __construct(
        public string $businessId,
        public string $resourceType,
        public string $resourceId,
        public ?string $resourceVersionId = null,
    ) {
        if ($businessId === '' || $resourceId === '') {
            throw new InvalidArgumentException(
                'Occurrence target requires Business and resource identity.',
            );
        }

        if (
            preg_match('/\A[a-z][a-z0-9_.]{0,95}\z/', $resourceType) !== 1
        ) {
            throw new InvalidArgumentException(
                'Occurrence target type uses an invalid identifier.',
            );
        }

        if ($resourceVersionId === '') {
            throw new InvalidArgumentException(
                'Occurrence target version identity cannot be empty.',
            );
        }
    }
}
