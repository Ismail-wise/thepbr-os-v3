<?php

namespace App\Application\Identity;

use App\Domain\Identity\Enums\LanguageMode;
use App\Infrastructure\Persistence\Eloquent\Identity\User;
use App\Infrastructure\Persistence\Eloquent\Identity\UserProfile;

final class ResolveUiLanguageMode
{
    public function handle(?User $user): LanguageMode
    {
        if (! $user instanceof User) {
            return LanguageMode::English;
        }

        $profile = UserProfile::query()
            ->whereKey((string) $user->getAuthIdentifier())
            ->first();

        if (! $profile instanceof UserProfile) {
            return LanguageMode::English;
        }

        return $profile->language_mode;
    }
}
