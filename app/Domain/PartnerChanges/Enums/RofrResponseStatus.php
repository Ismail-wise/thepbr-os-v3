<?php

declare(strict_types=1);

namespace App\Domain\PartnerChanges\Enums;

enum RofrResponseStatus: string
{
    case Pending = 'pending';
    case Accept = 'accept';
    case Decline = 'decline';
    case Waive = 'waive';
}
