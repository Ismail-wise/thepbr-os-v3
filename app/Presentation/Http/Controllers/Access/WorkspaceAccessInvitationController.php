<?php

declare(strict_types=1);

namespace App\Presentation\Http\Controllers\Access;

use App\Application\Access\CreateBusinessAccessInvitation;
use App\Application\Access\RevokeBusinessAccessInvitation;
use App\Http\Middleware\EnsureCurrentBusinessContext;
use App\Infrastructure\Persistence\Eloquent\Businesses\Business;
use App\Infrastructure\Persistence\Eloquent\Identity\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;

final class WorkspaceAccessInvitationController
{
    public function store(
        Request $request,
        CreateBusinessAccessInvitation $createInvitation,
    ): RedirectResponse {
        [$user, $business] = $this->context($request);

        $data = $request->validate([
            'email' => ['required', 'email', 'max:254'],
            'permission_profile_id' => ['required', 'uuid'],
            'expires_in_hours' => [
                'required',
                'integer',
                'min:1',
                'max:168',
            ],
        ]);

        try {
            $invitation = $createInvitation->execute(
                $user,
                $business,
                $data['email'],
                $data['permission_profile_id'],
                (int) $data['expires_in_hours'],
            );
        } catch (InvalidArgumentException $exception) {
            throw ValidationException::withMessages([
                'invitation' => $exception->getMessage(),
            ]);
        }

        abort_if($invitation === null, 404);

        return back()->with([
            'access_invitation_token' => $invitation['token'],
            'access_invitation_id' => $invitation['id'],
        ]);
    }

    public function revoke(
        Request $request,
        string $invitation,
        RevokeBusinessAccessInvitation $revokeInvitation,
    ): RedirectResponse {
        [$user, $business] = $this->context($request);

        $revoked = $revokeInvitation->execute(
            $user,
            $business,
            $invitation,
        );

        abort_if($revoked === null, 404);

        if (! $revoked) {
            throw ValidationException::withMessages([
                'invitation' => 'Only a pending invitation can be revoked.',
            ]);
        }

        return back();
    }

    /**
     * @return array{User, Business}
     */
    private function context(Request $request): array
    {
        $user = $request->user();

        $business = $request->attributes->get(
            EnsureCurrentBusinessContext::ATTRIBUTE_KEY,
        );

        if (
            ! $user instanceof User
            || ! $business instanceof Business
        ) {
            abort(403);
        }

        return [$user, $business];
    }
}
