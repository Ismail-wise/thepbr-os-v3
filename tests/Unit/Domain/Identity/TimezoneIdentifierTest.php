<?php

namespace Tests\Unit\Domain\Identity;

use App\Domain\Identity\ValueObjects\TimezoneIdentifier;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class TimezoneIdentifierTest extends TestCase
{
    public function test_utc_is_accepted(): void
    {
        $this->assertSame('UTC', TimezoneIdentifier::from('UTC')->value());
    }

    public function test_asia_yangon_is_accepted(): void
    {
        $this->assertSame(
            'Asia/Yangon',
            TimezoneIdentifier::from('Asia/Yangon')->value(),
        );
    }

    public function test_asia_bangkok_is_accepted(): void
    {
        $this->assertSame(
            'Asia/Bangkok',
            TimezoneIdentifier::from('Asia/Bangkok')->value(),
        );
    }

    public function test_invalid_non_iana_timezone_is_rejected(): void
    {
        $this->expectException(InvalidArgumentException::class);

        TimezoneIdentifier::from('Mars/Olympus_Mons');
    }
}
