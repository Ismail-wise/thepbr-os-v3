<?php

declare(strict_types=1);

namespace App\Presentation\Http\Controllers\Dashboard;

use App\Application\Dashboard\GetBusinessControlCenter;
use App\Http\Middleware\EnsureCurrentBusinessContext;
use App\Infrastructure\Persistence\Eloquent\Businesses\Business;
use App\Infrastructure\Persistence\Eloquent\Identity\User;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

final class BusinessControlCenterController
{
    public function __invoke(
        Request $request,
        GetBusinessControlCenter $controlCenter,
    ): Response {
        $user = $request->user();
        $business = $request->attributes->get(
            EnsureCurrentBusinessContext::ATTRIBUTE_KEY,
        );

        if (! $user instanceof User || ! $business instanceof Business) {
            abort(403);
        }

        return Inertia::render('Business/ControlCenter', [
            'controlCenter' => $controlCenter->execute(
                $user,
                $business,
            ),
        ]);
    }
}
