<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Application\Access\ProvisionStandardAccessProfiles;
use App\Infrastructure\Persistence\Eloquent\Businesses\Business;
use App\Infrastructure\Persistence\Eloquent\Members\Membership;
use Illuminate\Console\Command;
use Throwable;

final class ProvisionBusinessAccessProfilesCommand extends Command
{
    protected $signature = 'pbr:access:provision-standard-profiles
        {business : Business UUID}
        {--workspace-owner-membership= : Optional active Membership UUID to receive the Workspace Owner system profile}';

    protected $description =
        'Provision Master-Spec standard system-access profiles for one Business.';

    public function handle(
        ProvisionStandardAccessProfiles $profiles,
    ): int {
        $businessId = (string) $this->argument('business');

        $business = Business::query()->whereKey($businessId)->first();

        if ($business === null) {
            $this->error('Business not found.');

            return self::FAILURE;
        }

        $ownerMembership = null;
        $ownerMembershipId =
            $this->option('workspace-owner-membership');

        if (is_string($ownerMembershipId) && $ownerMembershipId !== '') {
            $ownerMembership = Membership::query()
                ->where('business_id', $business->getKey())
                ->whereKey($ownerMembershipId)
                ->first();

            if ($ownerMembership === null) {
                $this->error(
                    'Workspace Owner Membership not found in this Business.',
                );

                return self::FAILURE;
            }
        }

        try {
            $created = $profiles->execute(
                $business,
                $ownerMembership,
            );
        } catch (Throwable $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }

        $this->info(
            sprintf(
                'Provisioned %d standard access profiles for %s.',
                count($created),
                $business->name,
            ),
        );

        if ($ownerMembership !== null) {
            $this->info(
                'Workspace Owner system profile assigned to Membership '
                .$ownerMembership->getKey().'.',
            );
        }

        $this->warn(
            'No governance authority, ownership right, or document permission was created by this command.',
        );

        return self::SUCCESS;
    }
}
