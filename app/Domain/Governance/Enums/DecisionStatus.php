<?php

declare(strict_types=1);

namespace App\Domain\Governance\Enums;

enum DecisionStatus: string
{
    case Open = 'open';
    case Decided = 'decided';
    case Cancelled = 'cancelled';
}
