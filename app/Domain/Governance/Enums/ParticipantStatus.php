<?php

declare(strict_types=1);

namespace App\Domain\Governance\Enums;

enum ParticipantStatus: string
{
    case Eligible = 'eligible';
    case Recused = 'recused';
}
