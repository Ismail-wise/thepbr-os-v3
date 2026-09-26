<?php

declare(strict_types=1);

namespace Tests\Unit\Domain\Partnership;

use App\Domain\Partnership\ValueObjects\ShareQuantity;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class ShareQuantityTest extends TestCase
{
    public function test_share_quantity_derives_from_accepted_contribution_and_approved_share_value(): void
    {
        self::assertSame(
            '5',
            ShareQuantity::fromAcceptedValue(
                50_000_000,
                10_000_000,
            )->value(),
        );
    }

    public function test_share_quantity_preserves_exact_supported_fractional_shares(): void
    {
        self::assertSame(
            '1.25',
            ShareQuantity::fromAcceptedValue(
                12_500_000,
                10_000_000,
            )->value(),
        );
    }

    public function test_share_conversion_refuses_silent_rounding_beyond_supported_precision(): void
    {
        $this->expectException(InvalidArgumentException::class);

        $this->expectExceptionMessage(
            'Share conversion exceeds supported 8-decimal precision',
        );

        ShareQuantity::fromAcceptedValue(
            1,
            3,
        );
    }

    public function test_share_value_must_be_positive(): void
    {
        $this->expectException(InvalidArgumentException::class);

        ShareQuantity::fromAcceptedValue(
            100,
            0,
        );
    }
}
