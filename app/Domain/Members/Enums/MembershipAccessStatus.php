<?php

namespace App\Domain\Members\Enums;

enum MembershipAccessStatus: string
{
    case Active = 'active';
    case Suspended = 'suspended';
    case Revoked = 'revoked';
}
