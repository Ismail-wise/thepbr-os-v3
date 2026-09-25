<?php

declare(strict_types=1);

namespace App\Domain\Governance\Enums;

enum ReviewOutcome: string
{
    case RemainsValid = 'remains_valid';
    case AmendmentRequired = 'amendment_required';
    case NoLongerApplicable = 'no_longer_applicable';
}
