<?php

declare(strict_types=1);

namespace App\Domain\Governance\Enums;

enum VoteChoice: string
{
    case For = 'for';
    case Against = 'against';
    case Abstain = 'abstain';
    case Recused = 'recused';
}
