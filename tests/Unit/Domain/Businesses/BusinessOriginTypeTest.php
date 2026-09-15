<?php

namespace Tests\Unit\Domain\Businesses;

use App\Domain\Businesses\Enums\BusinessOriginType;
use PHPUnit\Framework\TestCase;

final class BusinessOriginTypeTest extends TestCase
{
    public function test_origin_types_are_exact(): void
    {
        $this->assertSame([
            'started_through_pbr',
            'existing_business_imported_into_pbr',
        ], array_map(
            static fn (BusinessOriginType $origin): string => $origin->value,
            BusinessOriginType::cases(),
        ));
    }
}
