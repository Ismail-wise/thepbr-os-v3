<?php

namespace App\Presentation\Http\Middleware;

use App\Domain\Identity\Enums\AccountStatus;
use App\Infrastructure\Persistence\Eloquent\Identity\User;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

final class EnsureActiveAccount
{
    public function handle(Request $request, Closure $next): Response
    {
        $authenticatedUser = $request->user();

        if (! $authenticatedUser instanceof User) {
            return $this->terminateSession($request);
        }

        $currentUser = User::query()->find($authenticatedUser->getAuthIdentifier());

        if (
            ! $currentUser instanceof User
            || $currentUser->status !== AccountStatus::Active
        ) {
            return $this->terminateSession($request);
        }

        return $next($request);
    }

    private function terminateSession(Request $request): Response
    {
        Auth::guard('web')->logout();

        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route('login');
    }
}
