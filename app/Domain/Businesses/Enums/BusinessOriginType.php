<?php

namespace App\Domain\Businesses\Enums;

enum BusinessOriginType: string
{
    case StartedThroughPbr = 'started_through_pbr';
    case ExistingBusinessImportedIntoPbr = 'existing_business_imported_into_pbr';
}
