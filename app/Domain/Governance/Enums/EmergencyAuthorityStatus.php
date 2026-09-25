<?php

declare(strict_types=1);

namespace App\Domain\Governance\Enums;

enum EmergencyAuthorityStatus: string
{
    case Active = 'active';
    case Revoked = 'revoked';
}
