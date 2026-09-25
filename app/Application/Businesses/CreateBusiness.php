<?php

namespace App\Application\Businesses;

use App\Application\Access\ProvisionStandardAccessProfiles;
use App\Domain\Businesses\Enums\BusinessOriginType;
use App\Domain\Businesses\Enums\BusinessStage;
use App\Domain\Members\Enums\MembershipAccessStatus;
use App\Infrastructure\Persistence\Eloquent\Businesses\Business;
use App\Infrastructure\Persistence\Eloquent\Identity\User;
use App\Infrastructure\Persistence\Eloquent\Members\Membership;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

final class CreateBusiness
{
    public function __construct(
        private readonly ProvisionStandardAccessProfiles $accessProfiles,
    ) {}

    public function handle(
        User $user,
        string $name,
        BusinessOriginType $originType,
        BusinessStage $businessStage,
        string $baseCurrency,
    ): Business {
        $name = trim($name);

        if ($name === '') {
            throw new InvalidArgumentException('Business name is required.');
        }

        if (mb_strlen($name) > 160) {
            throw new InvalidArgumentException('Business name must not exceed 160 characters.');
        }

        if (preg_match('/\A[A-Z]{3}\z/', $baseCurrency) !== 1) {
            throw new InvalidArgumentException(
                'Base currency must be a three-letter uppercase currency code.',
            );
        }

        return DB::transaction(function () use (
            $user,
            $name,
            $originType,
            $businessStage,
            $baseCurrency,
        ): Business {
            $business = Business::query()->create([
                'name' => $name,
                'origin_type' => $originType,
                'business_stage' => $businessStage,
                'setup_phase' => null,
                'base_currency' => $baseCurrency,
            ]);

            $membership = Membership::query()->create([
                'user_id' => $user->getKey(),
                'business_id' => $business->getKey(),
                'access_status' => MembershipAccessStatus::Active,
            ]);

            $this->accessProfiles->execute(
                $business,
                $membership,
            );

            return $business->refresh();
        });
    }
}
