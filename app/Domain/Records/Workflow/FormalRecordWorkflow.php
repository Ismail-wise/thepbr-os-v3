<?php

declare(strict_types=1);

namespace App\Domain\Records\Workflow;

use App\Domain\Records\Enums\FormalRecordState;
use App\Domain\Records\Exceptions\InvalidWorkflowTransition;
use App\Domain\Records\ValueObjects\RequirementResult;

final class FormalRecordWorkflow
{
    public function canTransition(
        FormalRecordState $from,
        FormalRecordState $to,
    ): bool {
        return in_array(
            $to,
            match ($from) {
                FormalRecordState::Draft => [
                    FormalRecordState::ReadyForReview,
                ],
                FormalRecordState::ReadyForReview => [
                    FormalRecordState::UnderReview,
                ],
                FormalRecordState::UnderReview => [
                    FormalRecordState::ChangesRequested,
                    FormalRecordState::Rejected,
                    FormalRecordState::Approved,
                ],
                FormalRecordState::Approved => [
                    FormalRecordState::ReadyForEffect,
                ],
                FormalRecordState::ReadyForEffect => [
                    FormalRecordState::Effective,
                ],
                FormalRecordState::Effective => [
                    FormalRecordState::Superseded,
                ],
                FormalRecordState::Rejected,
                FormalRecordState::Superseded => [
                    FormalRecordState::Archived,
                ],
                FormalRecordState::ChangesRequested,
                FormalRecordState::Archived => [],
            },
            true,
        );
    }

    public function assertCanTransition(
        FormalRecordState $from,
        FormalRecordState $to,
    ): void {
        if (! $this->canTransition($from, $to)) {
            throw new InvalidWorkflowTransition(
                sprintf(
                    'Invalid formal-record transition from %s to %s.',
                    $from->value,
                    $to->value,
                ),
            );
        }
    }

    /**
     * @param  array<RequirementResult>  $results
     */
    public function assertRequirements(array $results): void
    {
        foreach ($results as $result) {
            if (! $result instanceof RequirementResult) {
                throw new InvalidWorkflowTransition(
                    'Lifecycle requirements must be RequirementResult values.',
                );
            }

            if ($result->blocksTransition()) {
                throw new InvalidWorkflowTransition(
                    sprintf(
                        'Requirement %s blocks this transition.',
                        $result->code,
                    ),
                );
            }
        }
    }
}
