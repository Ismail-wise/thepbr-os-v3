<?php

declare(strict_types=1);

namespace App\Presentation\Http\Controllers\Access;

use App\Application\Identity\RedeemPbrAccessCode;
use App\Domain\Identity\Enums\AccountStatus;
use App\Domain\Identity\Enums\LanguageMode;
use App\Infrastructure\Persistence\Eloquent\Identity\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use InvalidArgumentException;

final class PbrAccessCodeRedemptionController
{
    public function show(Request $request): Response
    {
        $user = $this->activeUserOrGuest($request);

        return Inertia::render('Auth/RedeemAccessCode', [
            'email' => $user instanceof User
                ? (string) $user->email
                : '',
            'authenticated' => $user instanceof User,
        ]);
    }

    public function store(
        Request $request,
        RedeemPbrAccessCode $redeem,
    ): RedirectResponse {
        $data = $request->validate([
            'token' => ['required', 'string', 'max:64'],
            'email' => ['required', 'email', 'max:254'],
            'display_name' => ['nullable', 'string', 'max:160'],
            'password' => ['nullable', 'string', 'confirmed'],
            'language_mode' => [
                'required',
                Rule::in(array_map(
                    static fn (LanguageMode $mode): string => $mode->value,
                    LanguageMode::cases(),
                )),
            ],
            'timezone' => ['required', 'string', 'max:64'],
        ]);

        $authenticatedUser = $this->activeUserOrGuest($request);

        try {
            $user = $redeem->handle(
                $data['token'],
                $data['email'],
                $authenticatedUser,
                $data['display_name'] ?? '',
                $data['password'] ?? '',
                LanguageMode::from($data['language_mode']),
                $data['timezone'],
            );
        } catch (InvalidArgumentException $exception) {
            throw ValidationException::withMessages([
                'access_code' => $exception->getMessage(),
            ]);
        }

        Auth::guard('web')->login($user);
        $request->session()->regenerate();

        return redirect()
            ->route('businesses.create')
            ->with('success', 'PBR access activated.');
    }

    private function activeUserOrGuest(Request $request): ?User
    {
        $authenticatedUser = $request->user();

        if (! $authenticatedUser instanceof User) {
            return null;
        }

        $freshUser = User::query()->find(
            $authenticatedUser->getAuthIdentifier(),
        );

        if (
            $freshUser instanceof User
            && $freshUser->status === AccountStatus::Active
        ) {
            return $freshUser;
        }

        Auth::guard('web')->logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return null;
    }
}
