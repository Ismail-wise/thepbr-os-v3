<?php

declare(strict_types=1);

namespace Tests\Feature\Partnership;

use App\Application\Partnership\ContributionSetupWorkflow;
use App\Application\Partnership\GetContributionSetupReadModel;
use App\Domain\Access\CapabilityCatalog;
use App\Domain\Records\Exceptions\StaleRevision;
use App\Infrastructure\Persistence\Eloquent\Access\Permission;
use App\Infrastructure\Persistence\Eloquent\Access\PermissionGrant;
use App\Infrastructure\Persistence\Eloquent\Businesses\Business;
use App\Infrastructure\Persistence\Eloquent\Identity\User;
use App\Infrastructure\Persistence\Eloquent\Members\Membership;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Tests\TestCase;

final class Chapter2ContributionSetupTest extends TestCase
{
    use RefreshDatabase;

    public function test_setup_uses_active_same_business_members_and_optimistic_revision_without_granting_authority(): void
    {
        [$user, $business, $membership] =
            $this->businessContext('owner');

        $approver = $this->membership(
            $business,
            'approver',
        );

        $this->grant(
            $business,
            $membership,
            CapabilityCatalog::CONTRIBUTIONS_MANAGE,
        );
        $this->grant(
            $business,
            $membership,
            CapabilityCatalog::CONTRIBUTIONS_VIEW,
        );

        $workflow = $this->app->make(
            ContributionSetupWorkflow::class,
        );

        $created = $workflow->save(
            $user,
            $business,
            0,
            [
                'valuationDate' => '2026-10-06',
                'currency' => 'USD',
                'periodStart' => '2026-01-01',
                'periodEnd' => '2026-12-31',
                'valuationOwnerMembershipId' => (string) $membership->getKey(),
                'approverMembershipIds' => [
                    (string) $approver->getKey(),
                ],
            ],
        );

        self::assertNotNull($created);
        self::assertTrue($created['created']);
        self::assertSame(1, $created['revision']);

        $this->assertDatabaseHas(
            'contribution_setups',
            [
                'business_id' => $business->getKey(),
                'currency' => 'USD',
                'revision' => 1,
                'valuation_owner_membership_id' => $membership->getKey(),
            ],
        );

        $this->assertDatabaseHas(
            'contribution_setup_approvers',
            [
                'business_id' => $business->getKey(),
                'membership_id' => $approver->getKey(),
            ],
        );

        $model = $this->app->make(
            GetContributionSetupReadModel::class,
        )->execute(
            $user,
            $business,
        );

        self::assertNotNull($model);
        self::assertTrue($model['configured']);
        self::assertFalse(
            $model['semantics'][
                'designationCreatesAuthority'
            ],
        );

        $updated = $workflow->save(
            $user,
            $business,
            1,
            [
                'valuationDate' => '2026-10-07',
                'currency' => 'USD',
                'periodStart' => '2026-01-01',
                'periodEnd' => '2026-12-31',
                'valuationOwnerMembershipId' => (string) $approver->getKey(),
                'approverMembershipIds' => [
                    (string) $membership->getKey(),
                ],
            ],
        );

        self::assertNotNull($updated);
        self::assertFalse($updated['created']);
        self::assertSame(2, $updated['revision']);

        $this->expectException(
            StaleRevision::class,
        );

        $workflow->save(
            $user,
            $business,
            1,
            [
                'valuationDate' => '2026-10-08',
                'currency' => 'USD',
                'periodStart' => '2026-01-01',
                'periodEnd' => '2026-12-31',
                'valuationOwnerMembershipId' => (string) $membership->getKey(),
                'approverMembershipIds' => [
                    (string) $approver->getKey(),
                ],
            ],
        );
    }

    public function test_setup_rejects_cross_business_owner_and_approver_memberships(): void
    {
        [$user, $business, $membership] =
            $this->businessContext('tenant-a');

        [, $otherBusiness] =
            $this->businessContext('tenant-b');

        $foreignMember = $this->membership(
            $otherBusiness,
            'foreign',
        );

        $this->grant(
            $business,
            $membership,
            CapabilityCatalog::CONTRIBUTIONS_MANAGE,
        );

        $this->expectException(
            InvalidArgumentException::class,
        );

        $this->app->make(
            ContributionSetupWorkflow::class,
        )->save(
            $user,
            $business,
            0,
            [
                'valuationDate' => '2026-10-06',
                'currency' => 'USD',
                'periodStart' => '2026-01-01',
                'periodEnd' => '2026-12-31',
                'valuationOwnerMembershipId' => (string)
                        $foreignMember->getKey(),
                'approverMembershipIds' => [
                    (string)
                        $foreignMember->getKey(),
                ],
            ],
        );
    }

    /**
     * @return array{User,Business,Membership}
     */
    private function businessContext(
        string $prefix,
    ): array {
        $business = Business::query()->create([
            'name' => 'C2 Setup '.Str::uuid7(),
            'origin_type' => 'started_through_pbr',
            'business_stage' => 'planning',
            'setup_phase' => 'formation',
            'workspace_status' => 'active',
            'base_currency' => 'USD',
        ]);

        $user = User::query()->create([
            'email' => $prefix.'-'.Str::uuid7()
                .'@example.test',
            'password' => 'not-a-real-hash',
            'status' => 'active',
            'password_changed_at' => now(),
        ]);

        $membership = Membership::query()->create([
            'user_id' => $user->getKey(),
            'business_id' => $business->getKey(),
            'access_status' => 'active',
        ]);

        return [
            $user,
            $business,
            $membership,
        ];
    }

    private function membership(
        Business $business,
        string $prefix,
    ): Membership {
        $user = User::query()->create([
            'email' => $prefix.'-'.Str::uuid7()
                .'@example.test',
            'password' => 'not-a-real-hash',
            'status' => 'active',
            'password_changed_at' => now(),
        ]);

        return Membership::query()->create([
            'user_id' => $user->getKey(),
            'business_id' => $business->getKey(),
            'access_status' => 'active',
        ]);
    }

    private function grant(
        Business $business,
        Membership $membership,
        string $capability,
    ): void {
        $permission =
            Permission::query()
                ->firstOrCreate([
                    'key' => $capability,
                ]);

        PermissionGrant::query()
            ->firstOrCreate(
                [
                    'business_id' => $business->getKey(),
                    'membership_id' => $membership->getKey(),
                    'permission_id' => $permission->getKey(),
                ],
                [
                    'effect' => 'allow',
                ],
            );
    }
}
