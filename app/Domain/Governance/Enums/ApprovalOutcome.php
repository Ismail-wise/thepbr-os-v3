<?php

declare(strict_types=1);

namespace App\Domain\Governance\Enums;

enum ApprovalOutcome: string
{
    case Approved = 'approved';
    case Rejected = 'rejected';
}
