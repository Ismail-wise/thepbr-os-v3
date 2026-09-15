<?php

namespace App\Http\Controllers;

use App\Application\Businesses\ResolveCurrentBusiness;
use App\Http\Middleware\EnsureCurrentBusinessContext;
use App\Infrastructure\Persistence\Eloquent\Identity\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

final class SelectCurrentBusinessController
{
    public function __invoke(
        Request $request,
        ResolveCurrentBusiness $resolveCurrentBusiness,
    ): RedirectResponse {
        $validated = $request->validate([
            'business_id' => ['required', 'string', 'uuid'],
        ]);

        $user = $request->user();

        abort_unless($user instanceof User, 403);

        $business = $resolveCurrentBusiness->handle(
            $user,
            $validated['business_id'],
        );

        abort_if($business === null, 403);

        $request->session()->put(
            EnsureCurrentBusinessContext::SESSION_KEY,
            $business->getKey(),
        );

        return back();
    }
}
