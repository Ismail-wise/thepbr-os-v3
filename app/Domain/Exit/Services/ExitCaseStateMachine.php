<?php

declare(strict_types=1);

namespace App\Domain\Exit\Services;

use App\Domain\Exit\Enums\ExitCaseStatus;

final class ExitCaseStateMachine
{
    public function allows(
        ExitCaseStatus $from,
        ExitCaseStatus $to,
    ): bool {
        if ($from === $to) {
            return true;
        }

        return in_array($to, $this->allowedTargets($from), true);
    }

    /** @return list<ExitCaseStatus> */
    private function allowedTargets(ExitCaseStatus $from): array
    {
        return match ($from) {
            ExitCaseStatus::Draft => [
                ExitCaseStatus::NoticeRecorded,
                ExitCaseStatus::Withdrawn,
                ExitCaseStatus::Cancelled,
            ],
            ExitCaseStatus::NoticeRecorded => [
                ExitCaseStatus::TreatmentReady,
                ExitCaseStatus::Withdrawn,
                ExitCaseStatus::Cancelled,
            ],
            ExitCaseStatus::TreatmentReady => [
                ExitCaseStatus::TermsReady,
                ExitCaseStatus::Withdrawn,
                ExitCaseStatus::Cancelled,
            ],
            ExitCaseStatus::TermsReady => [
                ExitCaseStatus::UnderGovernance,
                ExitCaseStatus::Withdrawn,
                ExitCaseStatus::Cancelled,
            ],
            ExitCaseStatus::UnderGovernance => [
                ExitCaseStatus::Approved,
                ExitCaseStatus::Rejected,
            ],
            ExitCaseStatus::Approved => [
                ExitCaseStatus::ReadyForEffect,
            ],
            ExitCaseStatus::ReadyForEffect => [
                ExitCaseStatus::Effective,
            ],
            ExitCaseStatus::Effective => [
                ExitCaseStatus::SettlementPending,
                ExitCaseStatus::Completed,
            ],
            ExitCaseStatus::SettlementPending => [
                ExitCaseStatus::Completed,
            ],
            ExitCaseStatus::Completed,
            ExitCaseStatus::Rejected,
            ExitCaseStatus::Withdrawn,
            ExitCaseStatus::Cancelled => [],
        };
    }
}
