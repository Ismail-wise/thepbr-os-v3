<?php

declare(strict_types=1);

namespace App\Domain\Finance\Enums;

enum FinanceExceptionStatus: string
{
    case Open = 'open';
    case CompensatingReview = 'compensating_review';
    case Cleared = 'cleared';
    case Blocked = 'blocked';
    case Resolved = 'resolved';
}
