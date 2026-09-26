<?php

declare(strict_types=1);

namespace App\Domain\Partnership\Enums;

enum ContributionStatus: string
{
    case Proposed = 'proposed';
    case Reviewed = 'reviewed';
    case Approved = 'approved';
    case Delivered = 'delivered';
    case Accepted = 'accepted';
    case Rejected = 'rejected';
    case Cancelled = 'cancelled';
    case Defaulted = 'defaulted';

    public function isTerminal(): bool
    {
        return in_array(
            $this,
            [
                self::Accepted,
                self::Rejected,
                self::Cancelled,
                self::Defaulted,
            ],
            true,
        );
    }
}
