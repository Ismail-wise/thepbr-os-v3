<?php

declare(strict_types=1);

namespace Tests\Unit\Domain\Closure;

use App\Domain\Closure\Enums\ClosureCaseStatus;
use App\Domain\Closure\Services\ClosureCaseStateMachine;
use PHPUnit\Framework\TestCase;

final class ClosureCaseStateMachineTest extends TestCase
{
    public function test_primary_closure_path_is_explicit(): void
    {
        $machine = new ClosureCaseStateMachine;

        $path = [
            ClosureCaseStatus::Draft,
            ClosureCaseStatus::UnderGovernance,
            ClosureCaseStatus::Approved,
            ClosureCaseStatus::WindDownActive,
            ClosureCaseStatus::ResidualReady,
            ClosureCaseStatus::LegalClosureReady,
            ClosureCaseStatus::LegallyClosed,
            ClosureCaseStatus::Completed,
        ];

        for ($index = 0; $index < count($path) - 1; $index++) {
            self::assertTrue(
                $machine->allows($path[$index], $path[$index + 1]),
                $path[$index]->value.' -> '.$path[$index + 1]->value,
            );
        }
    }

    public function test_closure_cannot_jump_from_draft_to_wind_down_or_effectivity(): void
    {
        $machine = new ClosureCaseStateMachine;

        self::assertFalse(
            $machine->allows(
                ClosureCaseStatus::Draft,
                ClosureCaseStatus::WindDownActive,
            ),
        );
        self::assertFalse(
            $machine->allows(
                ClosureCaseStatus::Draft,
                ClosureCaseStatus::LegallyClosed,
            ),
        );
        self::assertFalse(
            $machine->allows(
                ClosureCaseStatus::Approved,
                ClosureCaseStatus::LegallyClosed,
            ),
        );
    }

    public function test_pre_governance_withdrawal_and_cancellation_are_explicit(): void
    {
        $machine = new ClosureCaseStateMachine;

        self::assertTrue(
            $machine->allows(
                ClosureCaseStatus::Draft,
                ClosureCaseStatus::Withdrawn,
            ),
        );
        self::assertTrue(
            $machine->allows(
                ClosureCaseStatus::Draft,
                ClosureCaseStatus::Cancelled,
            ),
        );
        self::assertFalse(
            $machine->allows(
                ClosureCaseStatus::Approved,
                ClosureCaseStatus::Withdrawn,
            ),
        );
    }

    public function test_terminal_closure_states_have_no_outbound_transition(): void
    {
        $machine = new ClosureCaseStateMachine;

        foreach ([
            ClosureCaseStatus::Completed,
            ClosureCaseStatus::Rejected,
            ClosureCaseStatus::Withdrawn,
            ClosureCaseStatus::Cancelled,
        ] as $terminal) {
            foreach (ClosureCaseStatus::cases() as $candidate) {
                if ($terminal === $candidate) {
                    self::assertTrue(
                        $machine->allows($terminal, $candidate),
                    );

                    continue;
                }

                self::assertFalse(
                    $machine->allows($terminal, $candidate),
                    $terminal->value.' -> '.$candidate->value,
                );
            }
        }
    }
}
