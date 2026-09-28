<?php

declare(strict_types=1);

namespace App\Domain\Import\Enums;

enum ImportedRecordStatus: string
{
    case Observed = 'observed';
    case Valid = 'valid';
    case Invalid = 'invalid';
    case Conflict = 'conflict';
    case Confirmed = 'confirmed';
    case ConfirmationFailed = 'confirmation_failed';
}
