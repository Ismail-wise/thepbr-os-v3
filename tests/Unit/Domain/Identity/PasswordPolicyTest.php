<?php

namespace Tests\Unit\Domain\Identity;

use App\Domain\Identity\Policies\PasswordPolicy;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class PasswordPolicyTest extends TestCase
{
    public function test_password_length_boundaries_are_exact(): void
    {
        $this->assertFalse(PasswordPolicy::accepts(str_repeat('a', 11)));
        $this->assertTrue(PasswordPolicy::accepts(str_repeat('a', 12)));
        $this->assertTrue(PasswordPolicy::accepts(str_repeat('a', 128)));
        $this->assertFalse(PasswordPolicy::accepts(str_repeat('a', 129)));
    }

    public function test_spaces_inside_a_valid_passphrase_are_accepted(): void
    {
        $this->assertTrue(PasswordPolicy::accepts('correct horse battery staple'));
    }

    public function test_leading_and_trailing_spaces_are_not_silently_trimmed(): void
    {
        $this->assertTrue(PasswordPolicy::accepts(str_repeat(' ', 10).'ab'));
        $this->assertTrue(PasswordPolicy::accepts('ab'.str_repeat(' ', 10)));
    }

    public function test_whitespace_only_password_is_rejected(): void
    {
        $this->assertFalse(PasswordPolicy::accepts(str_repeat(' ', 12)));
    }

    public function test_assert_rejects_out_of_range_password(): void
    {
        $this->expectException(InvalidArgumentException::class);

        PasswordPolicy::assert('too-short');
    }
}
