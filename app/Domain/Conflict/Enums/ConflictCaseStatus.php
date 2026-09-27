<?php

declare(strict_types=1);

namespace App\Domain\Conflict\Enums;

enum ConflictCaseStatus: string
{
    case Open = 'open';
    case InProgress = 'in_progress';
    case Resolved = 'resolved';
    case Closed = 'closed';
}
