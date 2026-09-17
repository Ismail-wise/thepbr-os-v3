<?php

declare(strict_types=1);

namespace App\Domain\Records\Enums;

enum RequirementOutcome: string
{
    case Met = 'met';
    case Warning = 'warning';
    case Blocked = 'blocked';
}
