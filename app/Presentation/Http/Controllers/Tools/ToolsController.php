<?php

declare(strict_types=1);

namespace App\Presentation\Http\Controllers\Tools;

use App\Application\Partnership\PartnershipActorContext;
use App\Domain\Access\CapabilityCatalog;
use App\Domain\Partnership\EquityScenarioSimulator;
use App\Http\Middleware\EnsureCurrentBusinessContext;
use App\Infrastructure\Persistence\Eloquent\Businesses\Business;
use App\Infrastructure\Persistence\Eloquent\Identity\User;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use InvalidArgumentException;

final class ToolsController
{
    public function index(
        Request $request,
        PartnershipActorContext $actor,
        EquityScenarioSimulator $simulator,
    ): Response {
        $user = $request->user();
        $business = $request->attributes->get(
            EnsureCurrentBusinessContext::ATTRIBUTE_KEY,
        );

        abort_unless($user instanceof User && $business instanceof Business, 403);
        abort_unless(
            $actor->allows($user, $business, CapabilityCatalog::OWNERSHIP_VIEW),
            403,
        );

        $simulation = null;
        $simulationError = null;

        if ($request->boolean('simulate')) {
            try {
                $weights = json_decode(
                    (string) $request->query('weights', '{}'),
                    true,
                    512,
                    JSON_THROW_ON_ERROR,
                );
                $partners = json_decode(
                    (string) $request->query('partners', '[]'),
                    true,
                    512,
                    JSON_THROW_ON_ERROR,
                );

                if (! is_array($weights) || ! is_array($partners)) {
                    throw new InvalidArgumentException('Scenario input is invalid.');
                }

                $simulation = $simulator->calculate($weights, $partners);
            } catch (\JsonException|InvalidArgumentException $exception) {
                $simulationError = $exception->getMessage();
            }
        }

        return Inertia::render('Tools/Index', [
            'defaults' => [
                'weights' => [
                    'capital' => '40',
                    'work' => '30',
                    'expertise' => '15',
                    'risk' => '15',
                ],
            ],
            'simulation' => $simulation,
            'simulationError' => $simulationError,
        ]);
    }
}
