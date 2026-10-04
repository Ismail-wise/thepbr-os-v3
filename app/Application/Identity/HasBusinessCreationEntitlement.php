<?php

declare(strict_types=1);

namespace App\Application\Identity;

use App\Infrastructure\Persistence\Eloquent\Identity\AccountEntitlement;
use App\Infrastructure\Persistence\Eloquent\Identity\User;

final class HasBusinessCreationEntitlement
{
    public const ENTITLEMENT = 'business.create';

    public function handle(User $user): bool
    {
        return AccountEntitlement::query()
            ->where('user_id', $user->getKey())
            ->where('entitlement_key', self::ENTITLEMENT)
            ->exists();
    }
}
