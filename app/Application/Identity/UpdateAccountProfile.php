<?php

namespace App\Application\Identity;

use App\Domain\Identity\Enums\LanguageMode;
use App\Domain\Identity\ValueObjects\TimezoneIdentifier;
use App\Infrastructure\Persistence\Eloquent\Identity\UserProfile;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

final class UpdateAccountProfile
{
    public function handle(
        string $userId,
        string $displayName,
        LanguageMode $languageMode,
        string $timezone,
    ): UserProfile {
        $displayName = trim($displayName);

        if ($displayName === '') {
            throw new InvalidArgumentException('Display name is required.');
        }

        if (mb_strlen($displayName) > 120) {
            throw new InvalidArgumentException('Display name may not be greater than 120 characters.');
        }

        $canonicalTimezone = TimezoneIdentifier::from($timezone)->value();

        return DB::transaction(function () use (
            $userId,
            $displayName,
            $languageMode,
            $canonicalTimezone,
        ): UserProfile {
            $profile = UserProfile::query()
                ->whereKey($userId)
                ->lockForUpdate()
                ->first();

            if (! $profile instanceof UserProfile) {
                throw new InvalidArgumentException('Account profile not found.');
            }

            $profile->display_name = $displayName;
            $profile->language_mode = $languageMode;
            $profile->timezone = $canonicalTimezone;
            $profile->save();

            return $profile->refresh();
        });
    }
}
