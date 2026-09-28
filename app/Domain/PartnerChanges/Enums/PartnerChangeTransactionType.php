<?php

declare(strict_types=1);

namespace App\Domain\PartnerChanges\Enums;

enum PartnerChangeTransactionType: string
{
    case Admission = 'admission';
    case TransferExisting = 'transfer_existing';
    case IssueNew = 'issue_new';

    public function changesOwnership(): bool
    {
        return $this !== self::Admission;
    }
}
