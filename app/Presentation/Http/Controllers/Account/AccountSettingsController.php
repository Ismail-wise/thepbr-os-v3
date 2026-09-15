<?php

namespace App\Presentation\Http\Controllers\Account;

use App\Application\Identity\UpdateAccountProfile;
use App\Domain\Identity\Enums\LanguageMode;
use App\Domain\Identity\ValueObjects\TimezoneIdentifier;
use App\Infrastructure\Persistence\Eloquent\Identity\User;
use App\Infrastructure\Persistence\Eloquent\Identity\UserProfile;
use DateTimeZone;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rules\Enum;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use InvalidArgumentException;

final class AccountSettingsController
{
    public function show(Request $request): Response
    {
        $user = $this->authenticatedUser($request);

        $profile = UserProfile::query()
            ->whereKey((string) $user->getAuthIdentifier())
            ->firstOrFail();

        return Inertia::render('Account/Settings', [
            'account' => [
                'email' => $user->email,
                'profile' => [
                    'display_name' => $profile->display_name,
                    'language_mode' => $profile->language_mode->value,
                    'timezone' => $profile->timezone,
                ],
            ],
            'languageOptions' => array_map(
                static fn (LanguageMode $mode): string => $mode->value,
                LanguageMode::cases(),
            ),
            'timezoneOptions' => DateTimeZone::listIdentifiers(DateTimeZone::ALL_WITH_BC),
        ]);
    }

    public function update(
        Request $request,
        UpdateAccountProfile $updateAccountProfile,
    ): RedirectResponse {
        $user = $this->authenticatedUser($request);

        $displayName = $request->input('display_name');

        if (is_string($displayName)) {
            $request->merge([
                'display_name' => trim($displayName),
            ]);
        }

        $validated = $request->validate([
            'display_name' => ['required', 'string', 'max:120'],
            'language_mode' => ['required', new Enum(LanguageMode::class)],
            'timezone' => ['required', 'string', 'max:64'],
        ]);

        try {
            $canonicalTimezone = TimezoneIdentifier::from(
                (string) $validated['timezone'],
            )->value();
        } catch (InvalidArgumentException) {
            throw ValidationException::withMessages([
                'timezone' => 'The selected timezone is invalid.',
            ]);
        }

        $updateAccountProfile->handle(
            userId: (string) $user->getAuthIdentifier(),
            displayName: (string) $validated['display_name'],
            languageMode: LanguageMode::from((string) $validated['language_mode']),
            timezone: $canonicalTimezone,
        );

        return redirect()->route('account.settings.show');
    }

    private function authenticatedUser(Request $request): User
    {
        $user = $request->user();

        if (! $user instanceof User) {
            abort(403);
        }

        return $user;
    }
}
