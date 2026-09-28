<?php

declare(strict_types=1);

namespace App\Domain\Import\Enums;

enum ImportBatchStatus: string
{
    case Staged = 'staged';
    case Parsed = 'parsed';
    case ReviewReady = 'review_ready';
    case Confirming = 'confirming';
    case Completed = 'completed';
    case CompletedWithErrors = 'completed_with_errors';
    case Failed = 'failed';
}
