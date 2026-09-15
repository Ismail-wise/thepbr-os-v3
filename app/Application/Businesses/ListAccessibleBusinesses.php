<?php

namespace App\Application\Businesses;

use App\Domain\Members\Enums\MembershipAccessStatus;
use App\Infrastructure\Persistence\Eloquent\Businesses\Business;
use App\Infrastructure\Persistence\Eloquent\Identity\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;

final class ListAccessibleBusinesses
{
    /**
     * @return Collection<int, Business>
     */
    public function handle(User $user): Collection
    {
        return Business::query()
            ->whereHas('memberships', function (Builder $query) use ($user): void {
                $query
                    ->where('user_id', $user->getKey())
                    ->where(
                        'access_status',
                        MembershipAccessStatus::Active->value,
                    );
            })
            ->orderBy('name')
            ->orderBy('id')
            ->get();
    }
}
