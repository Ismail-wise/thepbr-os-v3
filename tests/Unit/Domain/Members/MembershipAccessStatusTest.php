<?php

namespace Tests\Unit\Domain\Members;

use App\Domain\Members\Enums\MembershipAccessStatus;
use PHPUnit\Framework\TestCase;

final class MembershipAccessStatusTest extends TestCase
{
    public function test_f7_membership_access_lifecycle_contract_is_exact(): void
    {
        $this->assertSame(
            [
                MembershipAccessStatus::Active,
                MembershipAccessStatus::Suspended,
                MembershipAccessStatus::Revoked,
            ],
            MembershipAccessStatus::cases(),
        );

        $this->assertSame('active', MembershipAccessStatus::Active->value);
        $this->assertSame(
            'suspended',
            MembershipAccessStatus::Suspended->value,
        );
        $this->assertSame('revoked', MembershipAccessStatus::Revoked->value);
    }
}
