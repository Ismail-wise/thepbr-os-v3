<?php

namespace Tests\Unit\Domain\Identity;

use App\Domain\Identity\Enums\SecurityEventType;
use PHPUnit\Framework\TestCase;

final class SecurityEventTypeTest extends TestCase
{
    public function test_values_are_exact(): void
    {
        $this->assertSame([
            'account.provisioned',
            'account.status_changed',
            'account.password_changed',
        ], array_map(
            static fn (SecurityEventType $type): string => $type->value,
            SecurityEventType::cases(),
        ));
    }
}
