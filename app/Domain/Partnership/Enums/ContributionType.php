<?php

declare(strict_types=1);

namespace App\Domain\Partnership\Enums;

enum ContributionType: string
{
    case Cash = 'cash';
    case TimeSkill = 'time_skill';
    case PropertyAsset = 'property_asset';
    case IpIntangible = 'ip_intangible';
}
