<?php

namespace Tests\Unit\Domain\Businesses;

use App\Domain\Businesses\Enums\SetupPhase;
use PHPUnit\Framework\TestCase;

final class SetupPhaseTest extends TestCase
{
    public function test_setup_phases_are_exact(): void
    {
        $this->assertSame([
            'formation',
        ], array_map(
            static fn (SetupPhase $phase): string => $phase->value,
            SetupPhase::cases(),
        ));
    }
}
