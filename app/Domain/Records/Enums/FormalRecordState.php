<?php

declare(strict_types=1);

namespace App\Domain\Records\Enums;

enum FormalRecordState: string
{
    case Draft = 'draft';
    case ReadyForReview = 'ready_for_review';
    case UnderReview = 'under_review';
    case ChangesRequested = 'changes_requested';
    case Rejected = 'rejected';
    case Approved = 'approved';
    case ReadyForEffect = 'ready_for_effect';
    case Effective = 'effective';
    case Superseded = 'superseded';
    case Archived = 'archived';

    public function requiresFrozenVersion(): bool
    {
        return $this !== self::Draft;
    }

    public function isAuthorityBearingPrimitive(): bool
    {
        return in_array(
            $this,
            [
                self::Approved,
                self::ReadyForEffect,
                self::Effective,
                self::Superseded,
            ],
            true,
        );
    }

    public function isHistorical(): bool
    {
        return in_array(
            $this,
            [self::Superseded, self::Archived],
            true,
        );
    }
}
