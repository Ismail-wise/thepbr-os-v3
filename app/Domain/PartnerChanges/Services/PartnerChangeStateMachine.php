<?php

declare(strict_types=1);

namespace App\Domain\PartnerChanges\Services;

use App\Domain\PartnerChanges\Enums\PartnerChangeStatus;

final class PartnerChangeStateMachine
{
    public function allows(
        PartnerChangeStatus $from,
        PartnerChangeStatus $to,
    ): bool {
        if ($from === $to) {
            return true;
        }

        return in_array(
            $to,
            $this->allowedTargets($from),
            true,
        );
    }

    /** @return list<PartnerChangeStatus> */
    private function allowedTargets(PartnerChangeStatus $from): array
    {
        return match ($from) {
            PartnerChangeStatus::Draft => [
                PartnerChangeStatus::EligibilityReview,
                PartnerChangeStatus::Withdrawn,
            ],
            PartnerChangeStatus::EligibilityReview => [
                PartnerChangeStatus::Blocked,
                PartnerChangeStatus::Eligible,
                PartnerChangeStatus::Withdrawn,
            ],
            PartnerChangeStatus::Blocked => [
                PartnerChangeStatus::EligibilityReview,
                PartnerChangeStatus::Withdrawn,
            ],
            PartnerChangeStatus::Eligible => [
                PartnerChangeStatus::Rofr,
                PartnerChangeStatus::TermsReady,
                PartnerChangeStatus::Withdrawn,
            ],
            PartnerChangeStatus::Rofr => [
                PartnerChangeStatus::TermsReady,
                PartnerChangeStatus::Blocked,
                PartnerChangeStatus::Withdrawn,
            ],
            PartnerChangeStatus::TermsReady => [
                PartnerChangeStatus::UnderGovernance,
                PartnerChangeStatus::Withdrawn,
            ],
            PartnerChangeStatus::UnderGovernance => [
                PartnerChangeStatus::Approved,
                PartnerChangeStatus::Rejected,
            ],
            PartnerChangeStatus::Approved => [
                PartnerChangeStatus::ReadyForEffect,
            ],
            PartnerChangeStatus::ReadyForEffect => [
                PartnerChangeStatus::Effective,
            ],
            PartnerChangeStatus::Effective => [
                PartnerChangeStatus::Completed,
            ],
            PartnerChangeStatus::Completed,
            PartnerChangeStatus::Rejected,
            PartnerChangeStatus::Withdrawn => [],
        };
    }
}
