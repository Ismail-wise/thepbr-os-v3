<?php

declare(strict_types=1);

namespace App\Presentation\Http\Controllers\Access;

use App\Application\Access\RedeemBusinessAccessInvitation;
use App\Domain\Identity\Enums\AccountStatus;
use App\Domain\Identity\Enums\LanguageMode;
use App\Http\Middleware\EnsureCurrentBusinessContext;
use App\Infrastructure\Persistence\Eloquent\Identity\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use InvalidArgumentException;

final class BusinessAccessInvitationRedemptionController
{
    public function show(Request $request): Response
    {
        $user = $this->activeUserOrGuest($request);

        return Inertia::render('Auth/RedeemInvitation', [
            'email' => $user instanceof User
                ? (string) $user->email
                : '',
            'authenticated' => $user instanceof User,
        ]);
    }

    public function store(
        Request $request,
        RedeemBusinessAccessInvitation $redeemInvitation,
    ): RedirectResponse {
        $data = $request->validate([
            'token' => ['required', 'string', 'max:64'],
            'email' => ['required', 'email', 'max:254'],
            'display_name' => ['nullable', 'string', 'max:160'],
            'password' => [
                'nullable',
                'string',
                'confirmed',
            ],
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
            $result = $redeemInvitation->execute(
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
                'invitation' => $exception->getMessage(),
            ]);
        }

        Auth::guard('web')->login($result['user']);
        $request->session()->regenerate();
        $request->session()->put(
            EnsureCurrentBusinessContext::SESSION_KEY,
            (string) $result['business']->getKey(),
        );

        return redirect()
            ->route('home')
            ->with(
                'success',
                'Business access invitation redeemed.',
            );
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
