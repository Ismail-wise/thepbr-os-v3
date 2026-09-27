<?php

declare(strict_types=1);

namespace App\Domain\Conflict\Enums;

enum MediationResponseOutcome: string
{
    case Accepted = 'accepted';
    case Rejected = 'rejected';
    case NeedsChanges = 'needs_changes';
}
