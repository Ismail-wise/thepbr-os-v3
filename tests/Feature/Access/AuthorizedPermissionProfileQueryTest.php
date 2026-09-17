<?php

declare(strict_types=1);

namespace Tests\Feature\Access;

use App\Application\Access\FindAuthorizedPermissionProfile;
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
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use ReflectionClass;
use ReflectionMethod;
use Tests\TestCase;

final class AuthorizedPermissionProfileQueryTest extends TestCase
{
    use RefreshDatabase;

    private const CAPABILITY = 'permission_profiles.view';

    public function test_same_business_authorized_profile_lookup_succeeds(): void
    {
        [$user, $business, $membership, $permission] =
            $this->authorizedMembership();

        $profile = $this->createProfile(
            $business,
            'Authorized Profile',
        );

        $this->allowProfileClass(
            $business,
            $membership,
            $permission,
        );

        $resolved = $this->useCase()->execute(
            $user,
            $business,
            new Capability(self::CAPABILITY),
            (string) $profile->getKey(),
        );

        $this->assertNotNull($resolved);
        $this->assertSame(
            (string) $profile->getKey(),
            (string) $resolved->getKey(),
        );
        $this->assertSame(
            (string) $business->getKey(),
            (string) $resolved->business_id,
        );
    }

    public function test_cross_business_and_nonexistent_ids_fail_closed_equally(): void
    {
        [$user, $businessA, $membershipA, $permission] =
            $this->authorizedMembership();

        $businessB = $this->createBusiness('Business B');
        $profileB = $this->createProfile(
            $businessB,
            'Business B Profile',
        );

        $this->allowProfileClass(
            $businessA,
            $membershipA,
            $permission,
        );

        $crossBusiness = $this->useCase()->execute(
            $user,
            $businessA,
            new Capability(self::CAPABILITY),
            (string) $profileB->getKey(),
        );

        $nonexistent = $this->useCase()->execute(
            $user,
            $businessA,
            new Capability(self::CAPABILITY),
            (string) Str::uuid7(),
        );

        $this->assertNull($crossBusiness);
        $this->assertNull($nonexistent);
        $this->assertSame($crossBusiness, $nonexistent);
    }

    public function test_valid_nonexistent_uuid_never_becomes_authorized(): void
    {
        [$user, $business, $membership, $permission] =
            $this->authorizedMembership();

        $this->allowProfileClass(
            $business,
            $membership,
            $permission,
        );

        $this->assertNull(
            $this->useCase()->execute(
                $user,
                $business,
                new Capability(self::CAPABILITY),
                (string) Str::uuid7(),
            ),
        );
    }

    public function test_missing_active_membership_denies_access(): void
    {
        $user = $this->createUser();
        $business = $this->createBusiness(
            'No Membership Business',
        );
        $profile = $this->createProfile(
            $business,
            'Protected Profile',
        );

        $this->assertSame(
            ['active'],
            array_map(
                static fn (
                    MembershipAccessStatus $status,
                ): string => $status->value,
                MembershipAccessStatus::cases(),
            ),
        );

        $this->assertNull(
            $this->useCase()->execute(
                $user,
                $business,
                new Capability(self::CAPABILITY),
                (string) $profile->getKey(),
            ),
        );
    }

    public function test_missing_capability_denies_access(): void
    {
        $user = $this->createUser();
        $business = $this->createBusiness(
            'No Capability Business',
        );
        $membership = $this->createMembership(
            $user,
            $business,
        );
        $permission = $this->createPermission();
        $profile = $this->createProfile(
            $business,
            'Protected Profile',
        );

        $this->allowProfileClass(
            $business,
            $membership,
            $permission,
        );

        $this->assertNull(
            $this->useCase()->execute(
                $user,
                $business,
                new Capability(self::CAPABILITY),
                (string) $profile->getKey(),
            ),
        );
    }

    public function test_matching_class_access_policy_can_permit_resource(): void
    {
        [$user, $business, $membership, $permission] =
            $this->authorizedMembership();

        $profile = $this->createProfile(
            $business,
            'Class Policy Profile',
        );

        $this->allowProfileClass(
            $business,
            $membership,
            $permission,
        );

        $this->assertNotNull(
            $this->useCase()->execute(
                $user,
                $business,
                new Capability(self::CAPABILITY),
                (string) $profile->getKey(),
            ),
        );
    }

    public function test_exact_record_deny_overrides_class_allow(): void
    {
        [$user, $business, $membership, $permission] =
            $this->authorizedMembership();

        $profile = $this->createProfile(
            $business,
            'Denied Profile',
        );

        $this->allowProfileClass(
            $business,
            $membership,
            $permission,
        );

        RecordAccessRule::query()->create([
            'business_id' => $business->getKey(),
            'membership_id' => $membership->getKey(),
            'permission_profile_id' => null,
            'permission_id' => $permission->getKey(),
            'resource_type' => PermissionProfile::class,
            'resource_id' => (string) $profile->getKey(),
            'effect' => PermissionEffect::Deny->value,
        ]);

        $this->assertNull(
            $this->useCase()->execute(
                $user,
                $business,
                new Capability(self::CAPABILITY),
                (string) $profile->getKey(),
            ),
        );
    }

    public function test_current_business_object_alone_does_not_create_access(): void
    {
        $user = $this->createUser();
        $business = $this->createBusiness(
            'Context Only Business',
        );
        $profile = $this->createProfile(
            $business,
            'Context Only Profile',
        );

        $this->assertNull(
            $this->useCase()->execute(
                $user,
                $business,
                new Capability(self::CAPABILITY),
                (string) $profile->getKey(),
            ),
        );
    }

    public function test_resource_business_is_not_caller_supplied(): void
    {
        $method = new ReflectionMethod(
            FindAuthorizedPermissionProfile::class,
            'execute',
        );

        $names = array_map(
            static fn ($parameter): string => $parameter->getName(),
            $method->getParameters(),
        );

        $this->assertSame(
            [
                'user',
                'currentBusiness',
                'capability',
                'permissionProfileId',
            ],
            $names,
        );

        $this->assertNotContains(
            'resourceBusiness',
            $names,
        );
        $this->assertNotContains(
            'resourceBusinessId',
            $names,
        );
        $this->assertNotContains(
            'recordBusinessId',
            $names,
        );
    }

    public function test_query_use_case_has_no_ownership_or_governance_shortcut(): void
    {
        $source = file_get_contents(
            (new ReflectionClass(
                FindAuthorizedPermissionProfile::class,
            ))->getFileName(),
        );

        $this->assertIsString($source);

        $normalized = strtolower($source);

        $this->assertStringNotContainsString(
            'ownership',
            $normalized,
        );
        $this->assertStringNotContainsString(
            'governance',
            $normalized,
        );
        $this->assertStringNotContainsString(
            'owner_user_id',
            $normalized,
        );
        $this->assertStringNotContainsString(
            'creator',
            $normalized,
        );
    }

    /**
     * @return array{User, Business, Membership, Permission}
     */
    private function authorizedMembership(): array
    {
        $user = $this->createUser();
        $business = $this->createBusiness(
            'Authorized Business',
        );
        $membership = $this->createMembership(
            $user,
            $business,
        );
        $permission = $this->createPermission();

        PermissionGrant::query()->create([
            'business_id' => $business->getKey(),
            'membership_id' => $membership->getKey(),
            'permission_id' => $permission->getKey(),
            'effect' => PermissionEffect::Allow->value,
        ]);

        return [
            $user,
            $business,
            $membership,
            $permission,
        ];
    }

    private function allowProfileClass(
        Business $business,
        Membership $membership,
        Permission $permission,
    ): void {
        AccessPolicy::query()->create([
            'business_id' => $business->getKey(),
            'membership_id' => $membership->getKey(),
            'permission_profile_id' => null,
            'permission_id' => $permission->getKey(),
            'resource_type' => PermissionProfile::class,
            'effect' => PermissionEffect::Allow->value,
        ]);
    }

    private function createPermission(): Permission
    {
        return Permission::query()->create([
            'key' => self::CAPABILITY,
        ]);
    }

    private function createProfile(
        Business $business,
        string $name,
    ): PermissionProfile {
        return PermissionProfile::query()->create([
            'business_id' => $business->getKey(),
            'name' => $name.' '.Str::uuid7(),
        ]);
    }

    private function createMembership(
        User $user,
        Business $business,
    ): Membership {
        return Membership::query()->create([
            'user_id' => $user->getKey(),
            'business_id' => $business->getKey(),
            'access_status' => MembershipAccessStatus::Active->value,
        ]);
    }

    private function createUser(): User
    {
        return User::query()->create([
            'email' => 'f2-query-'.Str::uuid7().'@example.test',
            'password' => Hash::make('F2-round1-test-password'),
            'status' => AccountStatus::Active->value,
            'password_changed_at' => now(),
        ]);
    }

    private function createBusiness(
        string $name,
    ): Business {
        return Business::query()->create([
            'name' => $name.' '.Str::uuid7(),
            'origin_type' => BusinessOriginType::StartedThroughPbr->value,
            'business_stage' => BusinessStage::Idea->value,
            'setup_phase' => SetupPhase::Formation->value,
            'workspace_status' => WorkspaceStatus::Active->value,
            'base_currency' => 'USD',
        ]);
    }

    private function useCase(): FindAuthorizedPermissionProfile
    {
        return $this->app->make(
            FindAuthorizedPermissionProfile::class,
        );
    }
}
