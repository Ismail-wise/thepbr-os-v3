<?php

namespace Tests\Unit\Domain\Identity;

use App\Domain\Identity\Enums\AccountStatus;
use PHPUnit\Framework\TestCase;

final class AccountStatusTest extends TestCase
{
    public function test_values_are_exact(): void
    {
        $this->assertSame([
            'provisioned',
            'active',
            'suspended',
            'disabled',
        ], array_map(
            static fn (AccountStatus $status): string => $status->value,
            AccountStatus::cases(),
        ));
    }

    public function test_allowed_transitions_are_explicit(): void
    {
        $this->assertTrue(AccountStatus::Provisioned->canTransitionTo(AccountStatus::Active));
        $this->assertTrue(AccountStatus::Provisioned->canTransitionTo(AccountStatus::Disabled));

        $this->assertTrue(AccountStatus::Active->canTransitionTo(AccountStatus::Suspended));
        $this->assertTrue(AccountStatus::Active->canTransitionTo(AccountStatus::Disabled));

        $this->assertTrue(AccountStatus::Suspended->canTransitionTo(AccountStatus::Active));
        $this->assertTrue(AccountStatus::Suspended->canTransitionTo(AccountStatus::Disabled));

        $this->assertTrue(AccountStatus::Disabled->canTransitionTo(AccountStatus::Active));
    }

    public function test_unapproved_transitions_are_rejected(): void
    {
        $this->assertFalse(AccountStatus::Provisioned->canTransitionTo(AccountStatus::Suspended));
        $this->assertFalse(AccountStatus::Active->canTransitionTo(AccountStatus::Provisioned));
        $this->assertFalse(AccountStatus::Suspended->canTransitionTo(AccountStatus::Provisioned));
        $this->assertFalse(AccountStatus::Disabled->canTransitionTo(AccountStatus::Provisioned));

        foreach (AccountStatus::cases() as $status) {
            $this->assertFalse($status->canTransitionTo($status));
        }
    }
}
