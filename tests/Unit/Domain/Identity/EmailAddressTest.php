<?php

namespace Tests\Unit\Domain\Identity;

use App\Domain\Identity\ValueObjects\EmailAddress;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class EmailAddressTest extends TestCase
{
    public function test_email_is_trimmed_and_canonicalized_to_lowercase(): void
    {
        $email = EmailAddress::from('  Person.Example@EXAMPLE.COM  ');

        $this->assertSame('person.example@example.com', $email->value());
        $this->assertSame('person.example@example.com', (string) $email);
    }

    public function test_invalid_email_is_rejected(): void
    {
        $this->expectException(InvalidArgumentException::class);

        EmailAddress::from('not-an-email');
    }

    public function test_email_longer_than_254_bytes_is_rejected(): void
    {
        $this->expectException(InvalidArgumentException::class);

        EmailAddress::from(str_repeat('a', 245).'@example.com');
    }
}
