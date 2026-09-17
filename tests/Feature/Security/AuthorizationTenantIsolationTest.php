<?php

namespace Tests\Feature\Security;

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
use App\Infrastructure\Persistence\Eloquent\Access\Permission;
use App\Infrastructure\Persistence\Eloquent\Access\PermissionGrant;
use App\Infrastructure\Persistence\Eloquent\Businesses\Business;
use App\Infrastructure\Persistence\Eloquent\Identity\User;
use App\Infrastructure\Persistence\Eloquent\Members\Membership;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

final class AuthorizationTenantIsolationTest extends TestCase
{
    use RefreshDatabase;

    public function test_business_a_permission_never_authorizes_business_b(): void
    {
        $user = $this->createUser();
        $businessA = $this->createBusiness('Authorization Tenant A');
        $businessB = $this->createBusiness('Authorization Tenant B');

        $membershipA = $this->createMembership($user, $businessA);
        $this->createMembership($user, $businessB);

        $permission = Permission::query()->create([
            'key' => 'records.view',
        ]);

        PermissionGrant::query()->create([
            'business_id' => $businessA->id,
            'membership_id' => $membershipA->id,
            'permission_id' => $permission->id,
            'effect' => PermissionEffect::Allow,
        ]);

        $authorizer = new AuthorizeBusinessCapability(
            new ResolveMembershipCapabilities,
        );

        $businessADecision = $authorizer->decide(
            $user,
            $businessA,
            $businessA,
            new Capability('records.view'),
        );

        $crossBusinessDecision = $authorizer->decide(
            $user,
            $businessA,
            $businessB,
            new Capability('records.view'),
        );

        $businessBDecision = $authorizer->decide(
            $user,
            $businessB,
            $businessB,
            new Capability('records.view'),
        );

        $this->assertTrue($businessADecision->allowed);

        $this->assertFalse($crossBusinessDecision->allowed);
        $this->assertSame(
            'resource_business_mismatch',
            $crossBusinessDecision->reason,
        );

        $this->assertFalse($businessBDecision->allowed);
        $this->assertSame(
            'capability_default_deny',
            $businessBDecision->reason,
        );
    }

    public function test_missing_membership_fails_closed(): void
    {
        $user = $this->createUser();
        $business = $this->createBusiness(
            'Authorization Missing Membership',
        );

        Permission::query()->create([
            'key' => 'records.view',
        ]);

        $decision = (new AuthorizeBusinessCapability(
            new ResolveMembershipCapabilities,
        ))->decide(
            $user,
            $business,
            $business,
            new Capability('records.view'),
        );

        $this->assertFalse($decision->allowed);
        $this->assertSame(
            'active_membership_required',
            $decision->reason,
        );
    }

    private function createUser(): User
    {
        return User::query()->create([
            'email' => sprintf('%s@example.com', Str::uuid7()),
            'password' => 'not-a-real-hash',
            'status' => AccountStatus::Active,
            'password_changed_at' => now(),
        ]);
    }

    private function createBusiness(string $name): Business
    {
        return Business::query()->create([
            'name' => $name,
            'origin_type' => BusinessOriginType::StartedThroughPbr,
            'business_stage' => BusinessStage::Idea,
            'setup_phase' => SetupPhase::Formation,
            'workspace_status' => WorkspaceStatus::Active,
            'base_currency' => 'USD',
        ]);
    }

    private function createMembership(
        User $user,
        Business $business,
    ): Membership {
        return Membership::query()->create([
            'user_id' => $user->id,
            'business_id' => $business->id,
            'access_status' => MembershipAccessStatus::Active,
        ]);
    }
}
