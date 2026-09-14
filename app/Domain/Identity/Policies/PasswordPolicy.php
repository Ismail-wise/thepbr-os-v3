<?php

namespace App\Domain\Identity\Policies;

use InvalidArgumentException;

final class PasswordPolicy
{
    public const int MIN_LENGTH = 12;

    public const int MAX_LENGTH = 128;

    public static function accepts(string $password): bool
    {
        $length = preg_match_all('/./us', $password);

        if ($length === false) {
            return false;
        }

        return $length >= self::MIN_LENGTH
            && $length <= self::MAX_LENGTH
            && preg_match('/\S/u', $password) === 1;
    }

    public static function assert(string $password): void
    {
        if (! self::accepts($password)) {
            throw new InvalidArgumentException(sprintf(
                'Password must be %d to %d characters and contain at least one non-whitespace character.',
                self::MIN_LENGTH,
                self::MAX_LENGTH,
            ));
        }
    }
}
