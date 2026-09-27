<?php

declare(strict_types=1);

namespace Tests\Unit\Domain\Conflict;

use App\Domain\Conflict\Enums\ConflictCaseStage;
use App\Domain\Conflict\Enums\ConflictCaseStatus;
use App\Domain\Conflict\Services\ConflictCaseStateMachine;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class ConflictCaseStateMachineTest extends TestCase
{
    public function test_primary_and_special_paths_are_explicit(): void
    {
        $machine = new ConflictCaseStateMachine;

        self::assertContains(
            ConflictCaseStage::DirectDiscussion,
            $machine->allowedNextStages(
                ConflictCaseStage::Intake,
                ConflictCaseStatus::Open,
            ),
        );
        self::assertContains(
            ConflictCaseStage::UrgentRisk,
            $machine->allowedNextStages(
                ConflictCaseStage::Intake,
                ConflictCaseStatus::Open,
            ),
        );
        self::assertContains(
            ConflictCaseStage::MisconductInvestigation,
            $machine->allowedNextStages(
                ConflictCaseStage::Intake,
                ConflictCaseStatus::Open,
            ),
        );
        self::assertContains(
            ConflictCaseStage::Deadlock,
            $machine->allowedNextStages(
                ConflictCaseStage::FormalDecision,
                ConflictCaseStatus::InProgress,
            ),
        );
        self::assertContains(
            ConflictCaseStage::Settlement,
            $machine->allowedNextStages(
                ConflictCaseStage::Mediation,
                ConflictCaseStatus::InProgress,
            ),
        );
    }

    public function test_resolved_or_closed_case_cannot_continue(): void
    {
        $machine = new ConflictCaseStateMachine;

        self::assertSame(
            [],
            $machine->allowedNextStages(
                ConflictCaseStage::Resolved,
                ConflictCaseStatus::Resolved,
            ),
        );
        self::assertSame(
            [],
            $machine->allowedNextStages(
                ConflictCaseStage::Resolved,
                ConflictCaseStatus::Closed,
            ),
        );
    }

    public function test_invalid_transition_is_rejected(): void
    {
        $machine = new ConflictCaseStateMachine;

        $this->expectException(InvalidArgumentException::class);

        $machine->assertStageTransition(
            ConflictCaseStage::Intake,
            ConflictCaseStatus::Open,
            ConflictCaseStage::Settlement,
        );
    }

    public function test_only_resolved_case_can_close(): void
    {
        $machine = new ConflictCaseStateMachine;

        $machine->assertClose(
            ConflictCaseStage::Resolved,
            ConflictCaseStatus::Resolved,
        );
        self::addToAssertionCount(1);

        try {
            $machine->assertClose(
                ConflictCaseStage::Settlement,
                ConflictCaseStatus::InProgress,
            );
            self::fail('Unresolved case must not close.');
        } catch (InvalidArgumentException) {
            self::addToAssertionCount(1);
        }
    }

    public function test_stage_status_projection_is_not_an_approval_boolean(): void
    {
        $machine = new ConflictCaseStateMachine;

        self::assertSame(
            ConflictCaseStatus::Open,
            $machine->statusForStage(ConflictCaseStage::Intake),
        );
        self::assertSame(
            ConflictCaseStatus::InProgress,
            $machine->statusForStage(ConflictCaseStage::Mediation),
        );
        self::assertSame(
            ConflictCaseStatus::Resolved,
            $machine->statusForStage(ConflictCaseStage::Resolved),
        );
    }
}
