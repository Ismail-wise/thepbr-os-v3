<?php

declare(strict_types=1);

namespace Tests\Unit\Domain\Records;

use App\Domain\Records\Enums\FormalRecordState;
use PHPUnit\Framework\TestCase;

final class FormalRecordStateTest extends TestCase
{
    public function test_all_lifecycle_states_are_explicit(): void
    {
        $this->assertSame(
            [
                'draft',
                'ready_for_review',
                'under_review',
                'changes_requested',
                'rejected',
                'approved',
                'ready_for_effect',
                'effective',
                'superseded',
                'archived',
            ],
            array_map(
                static fn (FormalRecordState $state): string => $state->value,
                FormalRecordState::cases(),
            ),
        );
    }

    public function test_authority_bearing_states_are_identified_without_granting_authority(): void
    {
        $this->assertTrue(
            FormalRecordState::Approved->isAuthorityBearingPrimitive(),
        );
        $this->assertTrue(
            FormalRecordState::ReadyForEffect->isAuthorityBearingPrimitive(),
        );
        $this->assertTrue(
            FormalRecordState::Effective->isAuthorityBearingPrimitive(),
        );
        $this->assertFalse(
            FormalRecordState::Draft->isAuthorityBearingPrimitive(),
        );
    }
}
