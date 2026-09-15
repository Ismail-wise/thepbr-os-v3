<?php

namespace Tests\Unit\Domain\Members;

use App\Domain\Members\Enums\MembershipAccessStatus;
use PHPUnit\Framework\TestCase;

final class MembershipAccessStatusTest extends TestCase
{
    public function test_active_is_the_only_frozen_a7_membership_access_status(): void
    {
        $this->assertSame(
            [MembershipAccessStatus::Active],
            MembershipAccessStatus::cases(),
        );

        $this->assertSame('active', MembershipAccessStatus::Active->value);
    }
}
