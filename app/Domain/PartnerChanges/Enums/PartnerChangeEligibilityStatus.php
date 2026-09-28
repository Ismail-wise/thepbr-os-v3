<?php

declare(strict_types=1);

namespace App\Domain\PartnerChanges\Enums;

enum PartnerChangeEligibilityStatus: string
{
    case Pending = 'pending';
    case Met = 'met';
    case Blocked = 'blocked';
    case NotApplicable = 'not_applicable';
}
