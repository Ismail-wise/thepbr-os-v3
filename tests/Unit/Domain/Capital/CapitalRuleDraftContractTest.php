<?php

declare(strict_types=1);

namespace Tests\Unit\Domain\Capital;

use App\Domain\Capital\CapitalRuleDraftContract;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class CapitalRuleDraftContractTest extends TestCase
{
    public function test_shortfall_responses_preserve_meaningful_priority_order(): void
    {
        $result = (new CapitalRuleDraftContract)->normalize([
            'shortfallResponses' => [
                'capital_call',
                'reduce_scope',
                'borrow',
                'delay',
            ],
            'allocationNotes' => '  Protect the working-capital buffer first.  ',
            'shortfallRuleNotes' => '  Review before launch.  ',
            'capitalCallRuleNote' => '  Consider only after scope review.  ',
        ]);

        self::assertSame(
            [
                'capital_call',
                'reduce_scope',
                'borrow',
                'delay',
            ],
            $result['shortfallResponses'],
        );
        self::assertSame(
            'Protect the working-capital buffer first.',
            $result['allocationNotes'],
        );
        self::assertSame(
            'Review before launch.',
            $result['shortfallRuleNotes'],
        );
        self::assertSame(
            'Consider only after scope review.',
            $result['capitalCallRuleNote'],
        );
    }

    public function test_empty_responses_are_valid_when_no_shortfall_action_is_required(): void
    {
        $result = (new CapitalRuleDraftContract)->normalize([
            'shortfallResponses' => [],
            'allocationNotes' => null,
            'shortfallRuleNotes' => null,
            'capitalCallRuleNote' => null,
        ]);

        self::assertSame([], $result['shortfallResponses']);
        self::assertNull($result['allocationNotes']);
        self::assertNull($result['shortfallRuleNotes']);
        self::assertNull($result['capitalCallRuleNote']);
    }

    public function test_duplicate_or_unsupported_responses_fail_safely(): void
    {
        foreach ([
            ['reduce_scope', 'reduce_scope'],
            ['reduce_scope', 'issue_shares'],
        ] as $responses) {
            try {
                (new CapitalRuleDraftContract)->normalize([
                    'shortfallResponses' => $responses,
                ]);

                self::fail('Expected invalid shortfall responses to fail.');
            } catch (InvalidArgumentException) {
                self::assertTrue(true);
            }
        }
    }

    public function test_capital_call_note_requires_capital_call_response(): void
    {
        $this->expectException(InvalidArgumentException::class);

        (new CapitalRuleDraftContract)->normalize([
            'shortfallResponses' => ['borrow'],
            'capitalCallRuleNote' => 'Ask partners later.',
        ]);
    }
}
