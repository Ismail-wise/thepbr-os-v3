<?php

declare(strict_types=1);

namespace App\Domain\Closure\Enums;

enum ClosureClaimStatus: string
{
    case Identified = 'identified';
    case Verified = 'verified';
    case Disputed = 'disputed';
    case Settled = 'settled';
    case Waived = 'waived';
}
