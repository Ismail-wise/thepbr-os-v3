<?php

declare(strict_types=1);

namespace App\Domain\Governance\Enums;

enum DecisionMethod: string
{
    case Approval = 'approval';
    case Vote = 'vote';
    case ApprovalAndVote = 'approval_and_vote';
}
