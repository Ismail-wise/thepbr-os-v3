<?php

declare(strict_types=1);

namespace App\Domain\Conflict\Enums;

enum ConflictSpecialPathStatus: string
{
    case Open = 'open';
    case InProgress = 'in_progress';
    case Completed = 'completed';
    case Referred = 'referred';
    case Closed = 'closed';
}
