<?php

namespace App\Presentation\Http\Controllers\Auth;

use App\Application\Identity\AuthenticateAccount;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

final class AuthenticatedSessionController
{
    private const INVALID_CREDENTIALS = 'The provided credentials are incorrect.';

    public function show(): Response
    {
        return Inertia::render('Auth/Login');
    }

    public function store(
        Request $request,
        AuthenticateAccount $authenticateAccount,
    ): RedirectResponse {
        $email = $request->input('email');
        $password = $request->input('password');

        if (
            ! is_string($email)
            || ! is_string($password)
            || ! $authenticateAccount->handle($email, $password)
        ) {
            throw ValidationException::withMessages([
                'email' => self::INVALID_CREDENTIALS,
            ]);
        }

        $request->session()->regenerate();

        return redirect()->intended(route('home'));
    }

    public function destroy(Request $request): RedirectResponse
    {
        Auth::guard('web')->logout();

        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route('login');
    }
}
