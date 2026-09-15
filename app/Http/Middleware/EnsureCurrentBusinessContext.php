<?php

namespace App\Http\Middleware;

use App\Application\Businesses\ResolveCurrentBusiness;
use App\Infrastructure\Persistence\Eloquent\Identity\User;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

final class EnsureCurrentBusinessContext
{
    public const string SESSION_KEY = 'current_business_id';

    public const string ATTRIBUTE_KEY = 'current_business';

    public function __construct(
        private readonly ResolveCurrentBusiness $resolveCurrentBusiness,
    ) {}

    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        abort_unless($user instanceof User, 403);

        $businessId = $request->session()->get(self::SESSION_KEY);

        if (! is_string($businessId) || ! Str::isUuid($businessId)) {
            $request->session()->forget(self::SESSION_KEY);

            abort(403);
        }

        $business = $this->resolveCurrentBusiness->handle($user, $businessId);

        if ($business === null) {
            $request->session()->forget(self::SESSION_KEY);

            abort(403);
        }

        $request->attributes->set(self::ATTRIBUTE_KEY, $business);

        return $next($request);
    }
}
