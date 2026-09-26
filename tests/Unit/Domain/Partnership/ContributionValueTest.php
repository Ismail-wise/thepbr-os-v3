<?php

declare(strict_types=1);

namespace Tests\Unit\Domain\Partnership;

use App\Domain\Partnership\ValueObjects\ContributionValue;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class ContributionValueTest extends TestCase
{
    public function test_value_normalizes_to_two_decimal_places(): void
    {
        self::assertSame(
            '1250.00',
            (new ContributionValue('1250'))->amount,
        );

        self::assertSame(
            '1250.50',
            (new ContributionValue('1250.5'))->amount,
        );
    }

    public function test_value_rejects_negative_or_excess_precision(): void
    {
        foreach (['-1', '1.001'] as $invalid) {
            try {
                new ContributionValue($invalid);
                self::fail(
                    "Expected {$invalid} to be rejected.",
                );
            } catch (InvalidArgumentException) {
                self::assertTrue(true);
            }
        }
    }

    public function test_fixed_precision_comparison_does_not_use_float_logic(): void
    {
        $accepted = new ContributionValue('999.99');
        $approved = new ContributionValue('1000.00');

        self::assertTrue(
            $accepted->lessThanOrEqual($approved),
        );
    }
}
