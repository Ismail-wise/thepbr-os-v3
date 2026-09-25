<?php

declare(strict_types=1);

namespace Tests\Feature\Access;

use App\Application\Access\ResolveMembershipCapabilities;
use App\Application\Businesses\CreateBusiness;
use App\Domain\Access\CapabilityCatalog;
use App\Domain\Access\Enums\StandardAccessProfile;
use App\Domain\Access\ValueObjects\Capability;
use App\Domain\Businesses\Enums\BusinessOriginType;
use App\Domain\Businesses\Enums\BusinessStage;
use App\Domain\Identity\Enums\AccountStatus;
use App\Domain\Members\Enums\MembershipAccessStatus;
use App\Infrastructure\Persistence\Eloquent\Access\PermissionProfile;
use App\Infrastructure\Persistence\Eloquent\Identity\User;
use App\Infrastructure\Persistence\Eloquent\Members\Membership;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

final class F3StandardAccessProfilesTest extends TestCase
{
    use RefreshDatabase;

    public function test_new_business_gets_master_aligned_system_profiles_and_creator_workspace_owner_assignment(): void
    {
        $user = User::query()->create([
            'email' => 'f3-owner@example.test',
            'password' => 'not-a-real-hash',
            'status' => AccountStatus::Active,
            'password_changed_at' => now(),
        ]);

        $business = $this->app->make(CreateBusiness::class)->handle(
            $user,
            'F3 Profile Business',
            BusinessOriginType::StartedThroughPbr,
            BusinessStage::Planning,
            'USD',
        );

        $membership = Membership::query()
            ->where('business_id', $business->getKey())
            ->where('user_id', $user->getKey())
            ->firstOrFail();

        $this->assertSame(
            MembershipAccessStatus::Active,
            $membership->access_status,
        );

        $profileNames = PermissionProfile::query()
            ->where('business_id', $business->getKey())
            ->orderBy('name')
            ->pluck('name')
            ->all();

        $expected = array_map(
            static fn (StandardAccessProfile $profile): string =>
                $profile->value,
            StandardAccessProfile::cases(),
        );

        sort($expected);

        $this->assertSame($expected, $profileNames);

        $workspaceOwnerProfile = PermissionProfile::query()
            ->where('business_id', $business->getKey())
            ->where('name', StandardAccessProfile::WorkspaceOwner->value)
            ->firstOrFail();

        $this->assertDatabaseHas('membership_permission_profiles', [
            'business_id' => $business->getKey(),
            'membership_id' => $membership->getKey(),
            'permission_profile_id' => $workspaceOwnerProfile->getKey(),
        ]);

        $capabilities = $this->app->make(
            ResolveMembershipCapabilities::class,
        );

        foreach ([
            CapabilityCatalog::ACCESS_ADMIN_MANAGE,
            CapabilityCatalog::FORMATION_AUTHORITY_BOOTSTRAP,
            CapabilityCatalog::GOVERNANCE_RECORDS_VIEW,
            CapabilityCatalog::GOVERNANCE_RECORDS_MANAGE,
            CapabilityCatalog::GOVERNANCE_SIGNATURE_ACT,
            CapabilityCatalog::GOVERNANCE_ACTION_MANAGE,
            CapabilityCatalog::RECORDS_MANAGE,
        ] as $capability) {
            $this->assertTrue(
                $capabilities->decide(
                    $membership,
                    new Capability($capability),
                )->allowed,
                $capability,
            );
        }

        $this->assertDatabaseCount('document_access_grants', 0);
        $this->assertDatabaseCount('formation_authority_establishments', 0);
        $this->assertDatabaseCount('decisions', 0);
        $this->assertDatabaseCount('decision_participants', 0);
        $this->assertDatabaseCount('authority_snapshots', 0);
    }

    public function test_profile_templates_do_not_assign_workspace_owner_to_unselected_memberships(): void
    {
        $user = User::query()->create([
            'email' => 'f3-owner-second@example.test',
            'password' => 'not-a-real-hash',
            'status' => AccountStatus::Active,
            'password_changed_at' => now(),
        ]);

        $business = $this->app->make(CreateBusiness::class)->handle(
            $user,
            'F3 Second Membership Business',
            BusinessOriginType::StartedThroughPbr,
            BusinessStage::Planning,
            'USD',
        );

        $other = User::query()->create([
            'email' => 'f3-member@example.test',
            'password' => 'not-a-real-hash',
            'status' => AccountStatus::Active,
            'password_changed_at' => now(),
        ]);

        $otherMembership = Membership::query()->create([
            'user_id' => $other->getKey(),
            'business_id' => $business->getKey(),
            'access_status' => MembershipAccessStatus::Active,
        ]);

        $ownerProfileId = PermissionProfile::query()
            ->where('business_id', $business->getKey())
            ->where('name', StandardAccessProfile::WorkspaceOwner->value)
            ->value('id');

        $this->assertNotNull($ownerProfileId);

        $this->assertFalse(
            DB::table('membership_permission_profiles')
                ->where('business_id', $business->getKey())
                ->where('membership_id', $otherMembership->getKey())
                ->where('permission_profile_id', $ownerProfileId)
                ->exists(),
        );
    }
}
