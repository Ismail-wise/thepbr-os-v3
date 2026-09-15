<?php

namespace Tests\Feature\Members;

use App\Domain\Businesses\Enums\BusinessOriginType;
use App\Domain\Businesses\Enums\BusinessStage;
use App\Domain\Businesses\Enums\SetupPhase;
use App\Domain\Businesses\Enums\WorkspaceStatus;
use App\Domain\Identity\Enums\AccountStatus;
use App\Domain\Members\Enums\MembershipAccessStatus;
use App\Infrastructure\Persistence\Eloquent\Businesses\Business;
use App\Infrastructure\Persistence\Eloquent\Identity\User;
use App\Infrastructure\Persistence\Eloquent\Members\Membership;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

final class MembershipPersistenceTest extends TestCase
{
    use RefreshDatabase;

    public function test_membership_uses_uuid_v7_and_belongs_to_user_and_business(): void
    {
        $user = $this->createUser('member@example.com');
        $business = $this->createBusiness('Membership Business');

        $membership = Membership::query()->create([
            'user_id' => $user->id,
            'business_id' => $business->id,
            'access_status' => MembershipAccessStatus::Active,
        ])->refresh();

        $this->assertTrue(Str::isUuid((string) $membership->id, 7));
        $this->assertSame(MembershipAccessStatus::Active, $membership->access_status);
        $this->assertSame($user->id, $membership->user_id);
        $this->assertSame($business->id, $membership->business_id);
        $this->assertSame($user->id, $membership->user->id);
        $this->assertSame($business->id, $membership->business->id);
    }

    public function test_user_can_belong_to_multiple_businesses_through_memberships(): void
    {
        $user = $this->createUser('multi-business@example.com');
        $businessA = $this->createBusiness('Business A');
        $businessB = $this->createBusiness('Business B');

        Membership::query()->create([
            'user_id' => $user->id,
            'business_id' => $businessA->id,
            'access_status' => MembershipAccessStatus::Active,
        ]);

        Membership::query()->create([
            'user_id' => $user->id,
            'business_id' => $businessB->id,
            'access_status' => MembershipAccessStatus::Active,
        ]);

        $memberships = $user->fresh()->memberships;

        $this->assertCount(2, $memberships);

        $this->assertEqualsCanonicalizing(
            [$businessA->id, $businessB->id],
            $memberships->pluck('business_id')->all(),
        );
    }

    public function test_business_can_have_multiple_users_through_memberships(): void
    {
        $business = $this->createBusiness('Shared Business');
        $userA = $this->createUser('member-a@example.com');
        $userB = $this->createUser('member-b@example.com');

        Membership::query()->create([
            'user_id' => $userA->id,
            'business_id' => $business->id,
            'access_status' => MembershipAccessStatus::Active,
        ]);

        Membership::query()->create([
            'user_id' => $userB->id,
            'business_id' => $business->id,
            'access_status' => MembershipAccessStatus::Active,
        ]);

        $memberships = $business->fresh()->memberships;

        $this->assertCount(2, $memberships);

        $this->assertEqualsCanonicalizing(
            [$userA->id, $userB->id],
            $memberships->pluck('user_id')->all(),
        );
    }

    private function createUser(string $email): User
    {
        return User::query()->create([
            'email' => $email,
            'password' => 'not-a-real-hash',
            'status' => AccountStatus::Provisioned,
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
}
