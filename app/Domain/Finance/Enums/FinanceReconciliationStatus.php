<?php

declare(strict_types=1);

namespace App\Domain\Finance\Enums;

enum FinanceReconciliationStatus: string
{
    case Open = 'open';
    case Completed = 'completed';
    case Exception = 'exception';
}
