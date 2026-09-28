<?php

declare(strict_types=1);

namespace Tests\Unit\Domain\Exit;

use App\Domain\Exit\Enums\ExitCaseStatus;
use App\Domain\Exit\Services\ExitCaseStateMachine;
use PHPUnit\Framework\TestCase;

final class ExitCaseStateMachineTest extends TestCase
{
    public function test_primary_exit_path_is_explicit(): void
    {
        $machine = new ExitCaseStateMachine;

        foreach ([
            [ExitCaseStatus::Draft, ExitCaseStatus::NoticeRecorded],
            [ExitCaseStatus::NoticeRecorded, ExitCaseStatus::TreatmentReady],
            [ExitCaseStatus::TreatmentReady, ExitCaseStatus::TermsReady],
            [ExitCaseStatus::TermsReady, ExitCaseStatus::UnderGovernance],
            [ExitCaseStatus::UnderGovernance, ExitCaseStatus::Approved],
            [ExitCaseStatus::Approved, ExitCaseStatus::ReadyForEffect],
            [ExitCaseStatus::ReadyForEffect, ExitCaseStatus::Effective],
            [ExitCaseStatus::Effective, ExitCaseStatus::SettlementPending],
            [ExitCaseStatus::SettlementPending, ExitCaseStatus::Completed],
        ] as [$from, $to]) {
            self::assertTrue($machine->allows($from, $to));
        }
    }

    public function test_opening_exit_cannot_jump_to_effective_or_formal_completion(): void
    {
        $machine = new ExitCaseStateMachine;

        self::assertFalse($machine->allows(
            ExitCaseStatus::Draft,
            ExitCaseStatus::Approved,
        ));
        self::assertFalse($machine->allows(
            ExitCaseStatus::NoticeRecorded,
            ExitCaseStatus::Effective,
        ));
        self::assertFalse($machine->allows(
            ExitCaseStatus::UnderGovernance,
            ExitCaseStatus::ReadyForEffect,
        ));
    }

    public function test_withdrawal_and_cancellation_are_explicit_pre_effect_terminal_paths(): void
    {
        $machine = new ExitCaseStateMachine;

        foreach ([
            ExitCaseStatus::Draft,
            ExitCaseStatus::NoticeRecorded,
            ExitCaseStatus::TreatmentReady,
            ExitCaseStatus::TermsReady,
        ] as $open) {
            self::assertTrue(
                $machine->allows($open, ExitCaseStatus::Withdrawn),
            );
            self::assertTrue(
                $machine->allows($open, ExitCaseStatus::Cancelled),
            );
        }

        self::assertFalse($machine->allows(
            ExitCaseStatus::Approved,
            ExitCaseStatus::Withdrawn,
        ));
        self::assertFalse($machine->allows(
            ExitCaseStatus::Effective,
            ExitCaseStatus::Cancelled,
        ));
    }

    public function test_terminal_exit_states_have_no_outbound_transition(): void
    {
        $machine = new ExitCaseStateMachine;

        foreach ([
            ExitCaseStatus::Completed,
            ExitCaseStatus::Rejected,
            ExitCaseStatus::Withdrawn,
            ExitCaseStatus::Cancelled,
        ] as $terminal) {
            foreach (ExitCaseStatus::cases() as $target) {
                self::assertSame(
                    $terminal === $target,
                    $machine->allows($terminal, $target),
                );
            }
        }
    }
}
