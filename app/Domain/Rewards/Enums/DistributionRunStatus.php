<?php

declare(strict_types=1);

namespace App\Domain\Rewards\Enums;

enum DistributionRunStatus: string
{
    case Draft = 'draft';
    case GovernancePending = 'governance_pending';
    case Approved = 'approved';
    case PaymentScheduled = 'payment_scheduled';
    case Completed = 'completed';
    case Rejected = 'rejected';
    case Cancelled = 'cancelled';
}
