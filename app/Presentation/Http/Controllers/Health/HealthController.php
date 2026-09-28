<?php

declare(strict_types=1);

namespace App\Presentation\Http\Controllers\Health;

use App\Application\Health\GetBusinessHealth;
use App\Http\Middleware\EnsureCurrentBusinessContext;
use App\Infrastructure\Persistence\Eloquent\Businesses\Business;
use App\Infrastructure\Persistence\Eloquent\Identity\User;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

final class HealthController
{
    public function __invoke(
        Request $request,
        GetBusinessHealth $health,
    ): Response {
        $user = $request->user();
        $business = $request->attributes->get(
            EnsureCurrentBusinessContext::ATTRIBUTE_KEY,
        );

        if (! $user instanceof User || ! $business instanceof Business) {
            abort(403);
        }

        $payload = $health->execute($user, $business);

        abort_if($payload === null, 404);

        return Inertia::render('Health/Index', [
            'businessHealth' => $payload,
        ]);
    }
}
