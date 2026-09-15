<?php

namespace App\Presentation\Http\Middleware;

use App\Application\Businesses\ListAccessibleBusinesses;
use App\Application\Businesses\ResolveCurrentBusiness;
use App\Http\Middleware\EnsureCurrentBusinessContext;
use App\Infrastructure\Persistence\Eloquent\Businesses\Business;
use App\Infrastructure\Persistence\Eloquent\Identity\User;
use Illuminate\Http\Request;
use Inertia\Middleware;

final class HandleInertiaRequests extends Middleware
{
    /**
     * The root template loaded for the first Inertia page visit.
     *
     * @var string
     */
    protected $rootView = 'app';

    public function __construct(
        private readonly ListAccessibleBusinesses $listAccessibleBusinesses,
        private readonly ResolveCurrentBusiness $resolveCurrentBusiness,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function share(Request $request): array
    {
        return [
            ...parent::share($request),
            'workspace' => $this->workspace($request),
        ];
    }

    /**
     * @return array{
     *     businesses: list<array{id: string, name: string}>,
     *     currentBusiness: array{id: string, name: string}|null
     * }|null
     */
    private function workspace(Request $request): ?array
    {
        $user = $request->user();

        if (! $user instanceof User) {
            return null;
        }

        $businesses = $this->listAccessibleBusinesses
            ->handle($user)
            ->map(
                static fn (Business $business): array => [
                    'id' => (string) $business->getKey(),
                    'name' => $business->name,
                ],
            )
            ->values()
            ->all();

        $currentBusiness = null;
        $businessId = $request->session()->get(
            EnsureCurrentBusinessContext::SESSION_KEY,
        );

        if (is_string($businessId)) {
            $resolvedBusiness = $this->resolveCurrentBusiness->handle(
                $user,
                $businessId,
            );

            if ($resolvedBusiness instanceof Business) {
                $currentBusiness = [
                    'id' => (string) $resolvedBusiness->getKey(),
                    'name' => $resolvedBusiness->name,
                ];
            }
        }

        return [
            'businesses' => $businesses,
            'currentBusiness' => $currentBusiness,
        ];
    }
}
