<?php

namespace Tests\Unit\Domain\Identity;

use App\Domain\Identity\Enums\LanguageMode;
use PHPUnit\Framework\TestCase;

final class LanguageModeTest extends TestCase
{
    public function test_language_modes_are_exact(): void
    {
        $this->assertSame([
            'en',
            'my',
            'mixed',
        ], array_map(
            static fn (LanguageMode $mode): string => $mode->value,
            LanguageMode::cases(),
        ));
    }
}
