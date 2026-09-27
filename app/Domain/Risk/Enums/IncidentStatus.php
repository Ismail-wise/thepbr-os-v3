<?php

declare(strict_types=1);

namespace App\Domain\Risk\Enums;

enum IncidentStatus: string
{
    case Open = 'open';
    case Investigating = 'investigating';
    case Contained = 'contained';
    case CorrectiveAction = 'corrective_action';
    case Resolved = 'resolved';
    case Closed = 'closed';
}
