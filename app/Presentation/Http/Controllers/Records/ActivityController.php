<?php

declare(strict_types=1);

namespace App\Presentation\Http\Controllers\Records;

use App\Application\Activity\ListAuthorizedBusinessActivity;
use App\Domain\Access\ValueObjects\Capability;
use App\Http\Middleware\EnsureCurrentBusinessContext;
use App\Infrastructure\Persistence\Eloquent\Businesses\Business;
use App\Infrastructure\Persistence\Eloquent\Identity\User;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use InvalidArgumentException;
use Symfony\Component\HttpKernel\Exception\HttpException;

final class ActivityController
{
    public function __invoke(
        Request $request,
        ListAuthorizedBusinessActivity $listAuthorizedBusinessActivity,
    ): Response {
        $user = $request->user();
        $currentBusiness = $request->attributes->get(
            EnsureCurrentBusinessContext::ATTRIBUTE_KEY,
        );

        if (! $user instanceof User || ! $currentBusiness instanceof Business) {
            abort(403);
        }

        try {
            $activity = $listAuthorizedBusinessActivity->execute(
                $user,
                $currentBusiness,
                new Capability('records.activity.view'),
                $request->query('cursor'),
            );
        } catch (InvalidArgumentException $exception) {
            throw new HttpException(422, $exception->getMessage());
        }

        abort_if($activity === null, 403);

        return Inertia::render('Records/Activity', [
            'activity' => $activity,
        ]);
    }
}
