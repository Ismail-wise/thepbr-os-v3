<?php

namespace Tests\Feature\Access;

use App\Application\Access\AuthorizeBusinessCapability;
use App\Application\Access\ResolveMembershipCapabilities;
use App\Domain\Access\Enums\PermissionEffect;
use App\Domain\Access\ValueObjects\Capability;
use App\Domain\Businesses\Enums\BusinessOriginType;
use App\Domain\Businesses\Enums\BusinessStage;
use App\Domain\Businesses\Enums\SetupPhase;
use App\Domain\Businesses\Enums\WorkspaceStatus;
use App\Domain\Identity\Enums\AccountStatus;
use App\Domain\Members\Enums\MembershipAccessStatus;
use App\Infrastructure\Persistence\Eloquent\Access\AccessPolicy;
use App\Infrastructure\Persistence\Eloquent\Access\Permission;
use App\Infrastructure\Persistence\Eloquent\Access\PermissionGrant;
use App\Infrastructure\Persistence\Eloquent\Access\PermissionProfile;
use App\Infrastructure\Persistence\Eloquent\Access\RecordAccessRule;
use App\Infrastructure\Persistence\Eloquent\Businesses\Business;
use App\Infrastructure\Persistence\Eloquent\Identity\User;
use App\Infrastructure\Persistence\Eloquent\Members\Membership;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

final class BusinessAuthorizationTest extends TestCase
{
    use RefreshDatabase;

    public function test_capability_defaults_to_deny_without_grant(): void
    {
        [$user, $business, $membership] = $this->createContext();
        $this->createPermission('records.view');

        $decision = $this->authorizer()->decide(
            $user,
            $business,
            $business,
            new Capability('records.view'),
        );

        $this->assertFalse($decision->allowed);
        $this->assertSame(
            'capability_default_deny',
            $decision->reason,
        );
        $this->assertSame(
            MembershipAccessStatus::Active,
            $membership->access_status,
        );
    }

    public function test_profile_can_grant_capability(): void
    {
        [$user, $business, $membership] = $this->createContext();
        $permission = $this->createPermission('records.view');
        $profile = PermissionProfile::query()->create([
            'business_id' => $business->id,
            'name' => 'Viewer',
        ]);

        $now = now();

        DB::table('permission_profile_permissions')->insert([
            'business_id' => $business->id,
            'permission_profile_id' => $profile->id,
            'permission_id' => $permission->id,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        DB::table('membership_permission_profiles')->insert([
            'business_id' => $business->id,
            'membership_id' => $membership->id,
            'permission_profile_id' => $profile->id,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        $decision = $this->authorizer()->decide(
            $user,
            $business,
            $business,
            new Capability('records.view'),
        );

        $this->assertTrue($decision->allowed);
        $this->assertSame('capability_granted', $decision->reason);
    }

    public function test_direct_deny_wins_over_profile_allow(): void
    {
        [$user, $business, $membership] = $this->createContext();
        $permission = $this->createPermission('records.view');
        $profile = PermissionProfile::query()->create([
            'business_id' => $business->id,
            'name' => 'Viewer',
        ]);

        $now = now();

        DB::table('permission_profile_permissions')->insert([
            'business_id' => $business->id,
            'permission_profile_id' => $profile->id,
            'permission_id' => $permission->id,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        DB::table('membership_permission_profiles')->insert([
            'business_id' => $business->id,
            'membership_id' => $membership->id,
            'permission_profile_id' => $profile->id,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        PermissionGrant::query()->create([
            'business_id' => $business->id,
            'membership_id' => $membership->id,
            'permission_id' => $permission->id,
            'effect' => PermissionEffect::Deny,
        ]);

        $decision = $this->authorizer()->decide(
            $user,
            $business,
            $business,
            new Capability('records.view'),
        );

        $this->assertFalse($decision->allowed);
        $this->assertSame(
            'direct_permission_denied',
            $decision->reason,
        );
    }

    public function test_resource_visibility_defaults_to_deny(): void
    {
        [$user, $business, $membership] = $this->createContext();
        $permission = $this->createPermission('records.view');

        PermissionGrant::query()->create([
            'business_id' => $business->id,
            'membership_id' => $membership->id,
            'permission_id' => $permission->id,
            'effect' => PermissionEffect::Allow,
        ]);

        $decision = $this->authorizer()->decide(
            $user,
            $business,
            $business,
            new Capability('records.view'),
            'risk',
            (string) Str::uuid7(),
        );

        $this->assertFalse($decision->allowed);
        $this->assertSame(
            'resource_visibility_default_deny',
            $decision->reason,
        );
    }

    public function test_record_deny_wins_over_class_policy_allow(): void
    {
        [$user, $business, $membership] = $this->createContext();
        $permission = $this->createPermission('records.view');

        PermissionGrant::query()->create([
            'business_id' => $business->id,
            'membership_id' => $membership->id,
            'permission_id' => $permission->id,
            'effect' => PermissionEffect::Allow,
        ]);

        AccessPolicy::query()->create([
            'business_id' => $business->id,
            'membership_id' => $membership->id,
            'permission_profile_id' => null,
            'permission_id' => $permission->id,
            'resource_type' => 'risk',
            'effect' => PermissionEffect::Allow,
        ]);

        $resourceId = (string) Str::uuid7();

        RecordAccessRule::query()->create([
            'business_id' => $business->id,
            'membership_id' => $membership->id,
            'permission_profile_id' => null,
            'permission_id' => $permission->id,
            'resource_type' => 'risk',
            'resource_id' => $resourceId,
            'effect' => PermissionEffect::Deny,
        ]);

        $decision = $this->authorizer()->decide(
            $user,
            $business,
            $business,
            new Capability('records.view'),
            'risk',
            $resourceId,
        );

        $this->assertFalse($decision->allowed);
        $this->assertSame('record_access_denied', $decision->reason);
    }

    private function authorizer(): AuthorizeBusinessCapability
    {
        return new AuthorizeBusinessCapability(
            new ResolveMembershipCapabilities,
        );
    }

    /**
     * @return array{User, Business, Membership}
     */
    private function createContext(): array
    {
        $user = User::query()->create([
            'email' => sprintf('%s@example.com', Str::uuid7()),
            'password' => 'not-a-real-hash',
            'status' => AccountStatus::Active,
            'password_changed_at' => now(),
        ]);

        $business = Business::query()->create([
            'name' => sprintf('Authorization %s', Str::uuid7()),
            'origin_type' => BusinessOriginType::StartedThroughPbr,
            'business_stage' => BusinessStage::Idea,
            'setup_phase' => SetupPhase::Formation,
            'workspace_status' => WorkspaceStatus::Active,
            'base_currency' => 'USD',
        ]);

        $membership = Membership::query()->create([
            'user_id' => $user->id,
            'business_id' => $business->id,
            'access_status' => MembershipAccessStatus::Active,
        ]);

        return [$user, $business, $membership];
    }

    private function createPermission(string $key): Permission
    {
        return Permission::query()->create([
            'key' => $key,
        ]);
    }
}
