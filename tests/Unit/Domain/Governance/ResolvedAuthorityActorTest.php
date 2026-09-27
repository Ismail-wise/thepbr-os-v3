<?php

declare(strict_types=1);

namespace Tests\Unit\Domain\Governance;

use App\Domain\Governance\ValueObjects\ResolvedAuthorityActor;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class ResolvedAuthorityActorTest extends TestCase
{
    public function test_delegation_substitutes_membership_without_expanding_authority(): void
    {
        $base = new ResolvedAuthorityActor(
            'membership-a',
            'Governance Approver',
            true,
            false,
            true,
        );

        $delegated = $base->delegatedTo('membership-b');

        self::assertSame('membership-b', $delegated->membershipId);
        self::assertSame(
            'Delegated: Governance Approver',
            $delegated->capacity,
        );
        self::assertTrue($delegated->canApprove);
        self::assertFalse($delegated->canVote);
        self::assertTrue($delegated->canSign);
        self::assertSame('delegation', $delegated->source);
    }

    public function test_actor_without_any_governance_capability_is_rejected(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new ResolvedAuthorityActor(
            'membership-a',
            'Observer',
            false,
            false,
            false,
        );
    }

    public function test_delegation_cannot_point_back_to_same_membership(): void
    {
        $base = new ResolvedAuthorityActor(
            'membership-a',
            'Approver',
            true,
            false,
            false,
        );

        $this->expectException(InvalidArgumentException::class);

        $base->delegatedTo('membership-a');
    }
}
