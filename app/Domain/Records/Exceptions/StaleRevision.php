<?php

declare(strict_types=1);

namespace App\Domain\Records\Exceptions;

use RuntimeException;

final class StaleRevision extends RuntimeException
{
    public function __construct(
        public readonly int $expectedRevision,
        public readonly int $actualRevision,
    ) {
        parent::__construct(
            sprintf(
                'Stale revision: expected %d, current revision is %d.',
                $expectedRevision,
                $actualRevision,
            ),
        );
    }
}
