<?php

namespace Tests\Unit\Domain\Access;

use App\Domain\Access\ValueObjects\Capability;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class CapabilityTest extends TestCase
{
    public function test_preserves_exact_capability_key(): void
    {
        $capability = new Capability('records.view');

        $this->assertSame('records.view', $capability->value());
        $this->assertSame('records.view', (string) $capability);
    }

    public function test_rejects_blank_capability(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new Capability('');
    }

    public function test_rejects_leading_or_trailing_whitespace(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new Capability(' records.view ');
    }

    public function test_rejects_overlong_capability(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new Capability(str_repeat('a', 161));
    }
}
