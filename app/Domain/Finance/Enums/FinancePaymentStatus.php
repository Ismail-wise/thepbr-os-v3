<?php

declare(strict_types=1);

namespace App\Domain\Finance\Enums;

enum FinancePaymentStatus: string
{
    case Draft = 'draft';
    case GovernancePending = 'governance_pending';
    case Authorized = 'authorized';
    case Paid = 'paid';
    case Completed = 'completed';
    case Rejected = 'rejected';
    case Cancelled = 'cancelled';
}
