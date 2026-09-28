<?php

declare(strict_types=1);

namespace App\Domain\Health\Enums;

enum HealthRequirementState: string
{
    case Met = 'met';
    case Warning = 'warning';
    case Blocked = 'blocked';
    case Unknown = 'unknown';
}
