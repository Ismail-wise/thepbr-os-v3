<?php

namespace App\Application\Businesses;

use App\Domain\Members\Enums\MembershipAccessStatus;
use App\Infrastructure\Persistence\Eloquent\Businesses\Business;
use App\Infrastructure\Persistence\Eloquent\Identity\User;
use App\Infrastructure\Persistence\Eloquent\Members\Membership;
use Illuminate\Support\Str;

final class ResolveCurrentBusiness
{
    public function handle(User $user, string $businessId): ?Business
    {
        if (! Str::isUuid($businessId)) {
            return null;
        }

        $membership = Membership::query()
            ->with('business')
            ->where('user_id', $user->getKey())
            ->where('business_id', $businessId)
            ->where('access_status', MembershipAccessStatus::Active->value)
            ->first();

        if ($membership === null) {
            return null;
        }

        $business = $membership->business;

        return $business instanceof Business ? $business : null;
    }
}
