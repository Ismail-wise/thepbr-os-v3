<?php

declare(strict_types=1);

namespace Tests\Unit\Domain\Records;

use App\Domain\Records\Enums\FormalRecordState;
use App\Domain\Records\Exceptions\InvalidWorkflowTransition;
use App\Domain\Records\ValueObjects\RequirementResult;
use App\Domain\Records\Workflow\FormalRecordWorkflow;
use PHPUnit\Framework\TestCase;

final class FormalRecordWorkflowTest extends TestCase
{
    public function test_valid_mechanical_transitions_are_explicit(): void
    {
        $workflow = new FormalRecordWorkflow;

        $this->assertTrue(
            $workflow->canTransition(
                FormalRecordState::Draft,
                FormalRecordState::ReadyForReview,
            ),
        );
        $this->assertTrue(
            $workflow->canTransition(
                FormalRecordState::UnderReview,
                FormalRecordState::Approved,
            ),
        );
        $this->assertTrue(
            $workflow->canTransition(
                FormalRecordState::ReadyForEffect,
                FormalRecordState::Effective,
            ),
        );
    }

    public function test_invalid_transition_is_denied(): void
    {
        $this->expectException(InvalidWorkflowTransition::class);

        (new FormalRecordWorkflow)->assertCanTransition(
            FormalRecordState::Draft,
            FormalRecordState::Effective,
        );
    }

    public function test_warning_does_not_become_met_or_block_transition(): void
    {
        (new FormalRecordWorkflow)->assertRequirements([
            RequirementResult::warning('review-date'),
        ]);

        $this->addToAssertionCount(1);
    }

    public function test_blocked_requirement_denies_transition(): void
    {
        $this->expectException(InvalidWorkflowTransition::class);

        (new FormalRecordWorkflow)->assertRequirements([
            RequirementResult::blocked('required-fact'),
        ]);
    }
}
