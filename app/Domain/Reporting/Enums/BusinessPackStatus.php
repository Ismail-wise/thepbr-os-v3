<?php

declare(strict_types=1);

namespace App\Domain\Reporting\Enums;

enum BusinessPackStatus: string
{
    case Requested = 'requested';
    case ManifestFrozen = 'manifest_frozen';
    case Generating = 'generating';
    case Verifying = 'verifying';
    case Available = 'available';
    case Failed = 'failed';
}
