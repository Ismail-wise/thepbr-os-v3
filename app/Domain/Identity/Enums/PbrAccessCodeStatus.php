<?php

declare(strict_types=1);

namespace App\Domain\Identity\Enums;

enum PbrAccessCodeStatus: string
{
    case Pending = 'pending';
    case Redeemed = 'redeemed';
    case Revoked = 'revoked';
}
