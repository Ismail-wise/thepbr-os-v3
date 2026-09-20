<?php

declare(strict_types=1);

namespace App\Presentation\Http\Controllers\Access;

use App\Application\Access\ListWorkspaceAccess;
use App\Application\Businesses\ResolveCurrentBusiness;
use App\Http\Middleware\EnsureCurrentBusinessContext;
use App\Infrastructure\Persistence\Eloquent\Businesses\Business;
use App\Infrastructure\Persistence\Eloquent\Identity\User;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

final class WorkspaceAccessController
{
    public function __invoke(
        Request $request,
        ResolveCurrentBusiness $resolveCurrentBusiness,
        ListWorkspaceAccess $listWorkspaceAccess,
    ): Response {
        $user = $request->user();

        abort_unless($user instanceof User, 404);

        $businessId = $request->session()->get(
            EnsureCurrentBusinessContext::SESSION_KEY,
        );

        abort_unless(is_string($businessId), 404);

        $currentBusiness = $resolveCurrentBusiness->handle(
            $user,
            $businessId,
        );

        abort_unless($currentBusiness instanceof Business, 404);

        $access = $listWorkspaceAccess->execute(
            $user,
            $currentBusiness,
        );

        abort_if($access === null, 404);

        return Inertia::render('Access/Index', [
            'access' => $access,
        ]);
    }
}
