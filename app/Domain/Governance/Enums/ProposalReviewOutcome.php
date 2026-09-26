<?php

declare(strict_types=1);

namespace App\Domain\Governance\Enums;

enum ProposalReviewOutcome: string
{
    case Approved = 'approved';
    case ChangesRequested = 'changes_requested';
    case Rejected = 'rejected';
}
