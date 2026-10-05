<?php

declare(strict_types=1);

namespace Tests\Unit\Domain\Capital;

use App\Domain\Capital\CapitalApprovalContract;
use PHPUnit\Framework\TestCase;

final class CapitalApprovalContractTest extends TestCase
{
    public function test_content_hash_is_deterministic_for_associative_key_order(): void
    {
        $contract = new CapitalApprovalContract;

        $a = [
            'contractVersion' => 'capital-approval-v1',
            'preferredPlan' => 'base',
            'sources' => [
                'capitalRule' => ['revision' => 2, 'id' => 'rule'],
                'capitalPlanning' => ['revision' => 3, 'id' => 'plan'],
            ],
            'responses' => ['reduce_scope', 'borrow'],
        ];

        $b = [
            'responses' => ['reduce_scope', 'borrow'],
            'sources' => [
                'capitalPlanning' => ['id' => 'plan', 'revision' => 3],
                'capitalRule' => ['id' => 'rule', 'revision' => 2],
            ],
            'preferredPlan' => 'base',
            'contractVersion' => 'capital-approval-v1',
        ];

        self::assertSame(
            $contract->contentHash($a),
            $contract->contentHash($b),
        );
        self::assertMatchesRegularExpression(
            '/^[0-9a-f]{64}$/',
            $contract->contentHash($a),
        );
    }

    public function test_list_order_remains_part_of_the_frozen_content_identity(): void
    {
        $contract = new CapitalApprovalContract;

        self::assertNotSame(
            $contract->contentHash([
                'responses' => ['reduce_scope', 'borrow'],
            ]),
            $contract->contentHash([
                'responses' => ['borrow', 'reduce_scope'],
            ]),
        );
    }
}
