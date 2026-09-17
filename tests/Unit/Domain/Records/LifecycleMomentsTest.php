<?php

declare(strict_types=1);

namespace Tests\Unit\Domain\Records;

use App\Domain\Records\ValueObjects\LifecycleMoments;
use DateTimeImmutable;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class LifecycleMomentsTest extends TestCase
{
    public function test_created_approved_signed_and_effective_moments_are_separate(): void
    {
        $moments = new LifecycleMoments(
            createdAt: new DateTimeImmutable('2026-01-01T00:00:00+00:00'),
            approvedAt: new DateTimeImmutable('2026-01-02T00:00:00+00:00'),
            signedAt: new DateTimeImmutable('2026-01-03T00:00:00+00:00'),
            effectiveFrom: new DateTimeImmutable('2026-01-10T00:00:00+00:00'),
            effectiveUntil: new DateTimeImmutable('2027-01-10T00:00:00+00:00'),
        );

        $this->assertNotEquals($moments->approvedAt, $moments->signedAt);
        $this->assertNotEquals($moments->signedAt, $moments->effectiveFrom);
    }

    public function test_planned_effective_end_requires_a_valid_start(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new LifecycleMoments(
            createdAt: new DateTimeImmutable,
            effectiveUntil: new DateTimeImmutable('+1 day'),
        );
    }

    public function test_planned_effective_end_must_follow_start(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new LifecycleMoments(
            createdAt: new DateTimeImmutable,
            effectiveFrom: new DateTimeImmutable('2026-02-02T00:00:00+00:00'),
            effectiveUntil: new DateTimeImmutable('2026-02-01T00:00:00+00:00'),
        );
    }
}
