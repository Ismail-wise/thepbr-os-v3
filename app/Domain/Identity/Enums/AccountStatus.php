<?php

namespace App\Domain\Identity\Enums;

enum AccountStatus: string
{
    case Provisioned = 'provisioned';
    case Active = 'active';
    case Suspended = 'suspended';
    case Disabled = 'disabled';

    public function canTransitionTo(self $target): bool
    {
        return match ($this) {
            self::Provisioned => in_array($target, [
                self::Active,
                self::Disabled,
            ], true),

            self::Active => in_array($target, [
                self::Suspended,
                self::Disabled,
            ], true),

            self::Suspended => in_array($target, [
                self::Active,
                self::Disabled,
            ], true),

            self::Disabled => $target === self::Active,
        };
    }
}
