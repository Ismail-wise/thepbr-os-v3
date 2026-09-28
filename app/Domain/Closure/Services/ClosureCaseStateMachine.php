<?php

declare(strict_types=1);

namespace App\Domain\Closure\Services;

use App\Domain\Closure\Enums\ClosureCaseStatus;

final class ClosureCaseStateMachine
{
    public function allows(ClosureCaseStatus $from, ClosureCaseStatus $to): bool
    {
        if ($from === $to) {
            return true;
        }

        return in_array($to, $this->allowedTargets($from), true);
    }

    /** @return list<ClosureCaseStatus> */
    private function allowedTargets(ClosureCaseStatus $from): array
    {
        return match ($from) {
            ClosureCaseStatus::Draft => [
                ClosureCaseStatus::UnderGovernance,
                ClosureCaseStatus::Withdrawn,
                ClosureCaseStatus::Cancelled,
            ],
            ClosureCaseStatus::UnderGovernance => [
                ClosureCaseStatus::Approved,
                ClosureCaseStatus::Rejected,
            ],
            ClosureCaseStatus::Approved => [ClosureCaseStatus::WindDownActive],
            ClosureCaseStatus::WindDownActive => [ClosureCaseStatus::ResidualReady],
            ClosureCaseStatus::ResidualReady => [ClosureCaseStatus::LegalClosureReady],
            ClosureCaseStatus::LegalClosureReady => [ClosureCaseStatus::LegallyClosed],
            ClosureCaseStatus::LegallyClosed => [ClosureCaseStatus::Completed],
            ClosureCaseStatus::Completed,
            ClosureCaseStatus::Rejected,
            ClosureCaseStatus::Withdrawn,
            ClosureCaseStatus::Cancelled => [],
        };
    }
}
