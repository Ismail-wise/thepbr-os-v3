<?php

namespace Tests\Unit\Domain\Access;

use App\Domain\Access\Enums\PermissionEffect;
use PHPUnit\Framework\TestCase;

final class PermissionEffectTest extends TestCase
{
    public function test_effect_values_are_explicit(): void
    {
        $this->assertSame('allow', PermissionEffect::Allow->value);
        $this->assertSame('deny', PermissionEffect::Deny->value);
    }
}
