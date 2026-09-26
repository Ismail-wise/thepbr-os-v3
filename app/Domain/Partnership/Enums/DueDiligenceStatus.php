<?php

declare(strict_types=1);

namespace App\Domain\Partnership\Enums;

enum DueDiligenceStatus: string
{
    case Draft = 'draft';
    case InReview = 'in_review';
    case Completed = 'completed';
    case Blocked = 'blocked';

    public function isTerminal(): bool
    {
        return in_array(
            $this,
            [self::Completed, self::Blocked],
            true,
        );
    }
}
