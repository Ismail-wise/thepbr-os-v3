<?php

declare(strict_types=1);

namespace Tests\Unit\Domain\Capital;

use App\Domain\Capital\CapitalActionPlanContract;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class CapitalActionPlanContractTest extends TestCase
{
    public function test_custom_action_fields_are_bounded_and_dates_are_calendar_dates(): void
    {
        $contract = new CapitalActionPlanContract;

        self::assertSame(
            'Prepare bank documents',
            $contract->normalizeTitle('  Prepare bank documents  '),
        );
        self::assertSame(
            'Supporting detail',
            $contract->normalizeDescription(' Supporting detail '),
        );
        self::assertNull($contract->normalizeDescription('   '));
        self::assertSame(
            '2027-02-28',
            $contract->normalizeDueDate('2027-02-28'),
        );
        self::assertNull($contract->normalizeDueDate(null));

        $this->expectException(InvalidArgumentException::class);
        $contract->normalizeDueDate('2027-02-30');
    }

    public function test_suggestion_keys_are_closed_to_the_capital_contract(): void
    {
        $contract = new CapitalActionPlanContract;

        foreach (CapitalActionPlanContract::suggestionKeys() as $key) {
            self::assertSame($key, $contract->assertSuggestionKey($key));
        }

        $this->expectException(InvalidArgumentException::class);
        $contract->assertSuggestionKey('execute_capital_call');
    }
}
