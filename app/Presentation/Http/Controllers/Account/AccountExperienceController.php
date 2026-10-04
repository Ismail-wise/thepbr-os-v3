<?php

declare(strict_types=1);

namespace App\Presentation\Http\Controllers\Account;

use App\Application\Account\GetAccountGovernanceInbox;
use App\Application\Account\GetAccountHome;
use App\Application\Account\GetAccountWork;
use App\Application\Businesses\ListAccessibleBusinesses;
use App\Application\Identity\HasBusinessCreationEntitlement;
use App\Infrastructure\Persistence\Eloquent\Businesses\Business;
use App\Infrastructure\Persistence\Eloquent\Identity\User;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

final class AccountExperienceController
{
    public function home(
        Request $request,
        GetAccountHome $home,
        HasBusinessCreationEntitlement $entitlement,
    ): Response {
        $user = $this->user($request);

        return Inertia::render('AccountHome', [
            ...$home->execute($user),
            'canCreateBusiness' => $entitlement->handle($user),
        ]);
    }

    public function businesses(
        Request $request,
        ListAccessibleBusinesses $businesses,
        HasBusinessCreationEntitlement $entitlement,
    ): Response {
        $user = $this->user($request);

        return Inertia::render('Account/Businesses', [
            'businesses' => $businesses
                ->handle($user)
                ->map(
                    static fn (Business $business): array => [
                        'businessId' => (string) $business->getKey(),
                        'name' => (string) $business->name,
                        'stage' => $business->business_stage->value,
                        'setupPhase' => $business->setup_phase?->value,
                        'workspaceStatus' => $business->workspace_status->value,
                        'baseCurrency' => (string) $business->base_currency,
                    ],
                )
                ->values()
                ->all(),
            'canCreateBusiness' => $entitlement->handle($user),
        ]);
    }

    public function work(
        Request $request,
        GetAccountWork $work,
    ): Response {
        return Inertia::render('Account/Work', [
            'items' => $work->execute($this->user($request)),
        ]);
    }

    public function notifications(
        Request $request,
        GetAccountGovernanceInbox $inbox,
    ): Response {
        $data = $inbox->execute($this->user($request));

        return Inertia::render('Account/Notifications', [
            'notifications' => $data['notifications'],
        ]);
    }

    public function approvals(
        Request $request,
        GetAccountGovernanceInbox $inbox,
    ): Response {
        $data = $inbox->execute($this->user($request));

        return Inertia::render('Account/Approvals', [
            'approvals' => $data['approvals'],
        ]);
    }

    public function signatures(
        Request $request,
        GetAccountGovernanceInbox $inbox,
    ): Response {
        $data = $inbox->execute($this->user($request));

        return Inertia::render('Account/Signatures', [
            'signatures' => $data['signatures'],
        ]);
    }

    private function user(Request $request): User
    {
        $user = $request->user();

        abort_unless($user instanceof User, 403);

        return $user;
    }
}
