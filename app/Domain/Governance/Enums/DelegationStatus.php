<?php

declare(strict_types=1);

namespace App\Domain\Governance\Enums;

enum DelegationStatus: string
{
    case Active = 'active';
    case Revoked = 'revoked';
}
