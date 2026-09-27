<?php

declare(strict_types=1);

namespace App\Domain\Continuity\Enums;

enum EmergencyAccessActivationStatus: string
{
    case Requested = 'requested';
    case Active = 'active';
    case Expired = 'expired';
    case Revoked = 'revoked';
    case Closed = 'closed';
}
