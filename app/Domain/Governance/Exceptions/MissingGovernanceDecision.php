<?php

declare(strict_types=1);

namespace App\Domain\Governance\Exceptions;

use InvalidArgumentException;

final class MissingGovernanceDecision extends InvalidArgumentException
{
    public function __construct()
    {
        parent::__construct(
            'A Governance Decision for this frozen proposal must be decided before this action can continue.',
        );
    }
}
