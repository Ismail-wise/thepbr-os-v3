<?php

declare(strict_types=1);

namespace App\Domain\Partnership\Enums;

enum OwnershipRegisterStatus: string
{
    case PendingEffect = 'pending_effect';
    case Effective = 'effective';
    case Superseded = 'superseded';
    case Archived = 'archived';
}
