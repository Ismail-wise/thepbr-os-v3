<?php

declare(strict_types=1);

namespace App\Domain\Partnership\Enums;

enum OwnershipScenarioStatus: string
{
    case Draft = 'draft';
    case Frozen = 'frozen';
    case Proposed = 'proposed';
    case Retired = 'retired';
}
