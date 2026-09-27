<?php

declare(strict_types=1);

namespace App\Domain\Conflict\Services;

use App\Domain\Conflict\Enums\ConflictCaseStage;
use App\Domain\Conflict\Enums\ConflictCaseStatus;
use InvalidArgumentException;

final class ConflictCaseStateMachine
{
    /**
     * @return list<ConflictCaseStage>
     */
    public function allowedNextStages(
        ConflictCaseStage $stage,
        ConflictCaseStatus $status,
    ): array {
        if (in_array($status, [
            ConflictCaseStatus::Resolved,
            ConflictCaseStatus::Closed,
        ], true)) {
            return [];
        }

        return match ($stage) {
            ConflictCaseStage::Intake => [
                ConflictCaseStage::DirectDiscussion,
                ConflictCaseStage::FormalDecision,
                ConflictCaseStage::Deadlock,
                ConflictCaseStage::MisconductInvestigation,
                ConflictCaseStage::UrgentRisk,
            ],
            ConflictCaseStage::DirectDiscussion => [
                ConflictCaseStage::Mediation,
                ConflictCaseStage::FormalDecision,
                ConflictCaseStage::Escalation,
                ConflictCaseStage::Resolved,
            ],
            ConflictCaseStage::Mediation => [
                ConflictCaseStage::FormalDecision,
                ConflictCaseStage::Settlement,
                ConflictCaseStage::Escalation,
                ConflictCaseStage::Resolved,
            ],
            ConflictCaseStage::FormalDecision => [
                ConflictCaseStage::Settlement,
                ConflictCaseStage::Escalation,
                ConflictCaseStage::Deadlock,
                ConflictCaseStage::ExitLegal,
                ConflictCaseStage::Resolved,
            ],
            ConflictCaseStage::Escalation => [
                ConflictCaseStage::Mediation,
                ConflictCaseStage::FormalDecision,
                ConflictCaseStage::Deadlock,
                ConflictCaseStage::Settlement,
                ConflictCaseStage::ExitLegal,
                ConflictCaseStage::Resolved,
            ],
            ConflictCaseStage::Deadlock => [
                ConflictCaseStage::Mediation,
                ConflictCaseStage::FormalDecision,
                ConflictCaseStage::Settlement,
                ConflictCaseStage::ExitLegal,
                ConflictCaseStage::Resolved,
            ],
            ConflictCaseStage::MisconductInvestigation => [
                ConflictCaseStage::FormalDecision,
                ConflictCaseStage::Settlement,
                ConflictCaseStage::ExitLegal,
                ConflictCaseStage::Resolved,
            ],
            ConflictCaseStage::UrgentRisk => [
                ConflictCaseStage::FormalDecision,
                ConflictCaseStage::Settlement,
                ConflictCaseStage::Escalation,
                ConflictCaseStage::ExitLegal,
                ConflictCaseStage::Resolved,
            ],
            ConflictCaseStage::Settlement => [
                ConflictCaseStage::Resolved,
                ConflictCaseStage::Escalation,
                ConflictCaseStage::ExitLegal,
            ],
            ConflictCaseStage::ExitLegal => [
                ConflictCaseStage::Resolved,
            ],
            ConflictCaseStage::Resolved => [],
        };
    }

    public function assertStageTransition(
        ConflictCaseStage $from,
        ConflictCaseStatus $status,
        ConflictCaseStage $to,
    ): void {
        if (! in_array($to, $this->allowedNextStages($from, $status), true)) {
            throw new InvalidArgumentException(
                "Conflict Case stage transition {$from->value} -> {$to->value} is invalid.",
            );
        }
    }

    public function statusForStage(
        ConflictCaseStage $stage,
    ): ConflictCaseStatus {
        return match ($stage) {
            ConflictCaseStage::Intake => ConflictCaseStatus::Open,
            ConflictCaseStage::Resolved => ConflictCaseStatus::Resolved,
            default => ConflictCaseStatus::InProgress,
        };
    }

    public function assertClose(
        ConflictCaseStage $stage,
        ConflictCaseStatus $status,
    ): void {
        if (
            $stage !== ConflictCaseStage::Resolved
            || $status !== ConflictCaseStatus::Resolved
        ) {
            throw new InvalidArgumentException(
                'Only a resolved Conflict Case may be closed.',
            );
        }
    }
}
