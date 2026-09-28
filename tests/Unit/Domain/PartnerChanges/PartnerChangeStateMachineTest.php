<?php

declare(strict_types=1);

namespace Tests\Unit\Domain\PartnerChanges;

use App\Domain\PartnerChanges\Enums\PartnerChangeStatus;
use App\Domain\PartnerChanges\Services\PartnerChangeStateMachine;
use PHPUnit\Framework\TestCase;

final class PartnerChangeStateMachineTest extends TestCase
{
    public function test_primary_partner_change_path_is_explicit(): void
    {
        $machine = new PartnerChangeStateMachine;

        self::assertTrue($machine->allows(
            PartnerChangeStatus::Draft,
            PartnerChangeStatus::EligibilityReview,
        ));
        self::assertTrue($machine->allows(
            PartnerChangeStatus::EligibilityReview,
            PartnerChangeStatus::Eligible,
        ));
        self::assertTrue($machine->allows(
            PartnerChangeStatus::Eligible,
            PartnerChangeStatus::Rofr,
        ));
        self::assertTrue($machine->allows(
            PartnerChangeStatus::Eligible,
            PartnerChangeStatus::TermsReady,
        ));
        self::assertTrue($machine->allows(
            PartnerChangeStatus::Rofr,
            PartnerChangeStatus::TermsReady,
        ));
        self::assertTrue($machine->allows(
            PartnerChangeStatus::TermsReady,
            PartnerChangeStatus::UnderGovernance,
        ));
        self::assertTrue($machine->allows(
            PartnerChangeStatus::UnderGovernance,
            PartnerChangeStatus::Approved,
        ));
        self::assertTrue($machine->allows(
            PartnerChangeStatus::Approved,
            PartnerChangeStatus::ReadyForEffect,
        ));
        self::assertTrue($machine->allows(
            PartnerChangeStatus::ReadyForEffect,
            PartnerChangeStatus::Effective,
        ));
        self::assertTrue($machine->allows(
            PartnerChangeStatus::Effective,
            PartnerChangeStatus::Completed,
        ));
    }

    public function test_blocked_case_must_return_through_eligibility_review(): void
    {
        $machine = new PartnerChangeStateMachine;

        self::assertTrue($machine->allows(
            PartnerChangeStatus::EligibilityReview,
            PartnerChangeStatus::Blocked,
        ));
        self::assertTrue($machine->allows(
            PartnerChangeStatus::Blocked,
            PartnerChangeStatus::EligibilityReview,
        ));
        self::assertFalse($machine->allows(
            PartnerChangeStatus::Blocked,
            PartnerChangeStatus::TermsReady,
        ));
    }

    public function test_rejected_withdrawn_and_completed_are_terminal(): void
    {
        $machine = new PartnerChangeStateMachine;

        foreach ([
            PartnerChangeStatus::Rejected,
            PartnerChangeStatus::Withdrawn,
            PartnerChangeStatus::Completed,
        ] as $terminal) {
            foreach (PartnerChangeStatus::cases() as $target) {
                self::assertSame(
                    $terminal === $target,
                    $machine->allows($terminal, $target),
                );
            }
        }
    }

    public function test_direct_apply_scenario_style_shortcuts_are_not_allowed(): void
    {
        $machine = new PartnerChangeStateMachine;

        self::assertFalse($machine->allows(
            PartnerChangeStatus::Draft,
            PartnerChangeStatus::Effective,
        ));
        self::assertFalse($machine->allows(
            PartnerChangeStatus::Eligible,
            PartnerChangeStatus::Approved,
        ));
        self::assertFalse($machine->allows(
            PartnerChangeStatus::UnderGovernance,
            PartnerChangeStatus::Effective,
        ));
    }
}
