<?php

namespace Tests\Unit\Domain\Businesses;

use App\Domain\Businesses\Enums\BusinessStage;
use PHPUnit\Framework\TestCase;

final class BusinessStageTest extends TestCase
{
    public function test_business_stages_are_exact(): void
    {
        $this->assertSame([
            'idea',
            'validation',
            'planning',
            'pre_launch',
            'operating',
            'growth',
            'restructuring',
            'exit',
        ], array_map(
            static fn (BusinessStage $stage): string => $stage->value,
            BusinessStage::cases(),
        ));
    }
}
