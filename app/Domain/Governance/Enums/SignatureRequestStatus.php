<?php

declare(strict_types=1);

namespace App\Domain\Governance\Enums;

enum SignatureRequestStatus: string
{
    case Draft = 'draft';
    case Sent = 'sent';
    case PartiallySigned = 'partially_signed';
    case FullySigned = 'fully_signed';
    case Completed = 'completed';
    case Expired = 'expired';
    case Cancelled = 'cancelled';
    case Declined = 'declined';
}
