<?php

namespace Tests\Feature\Businesses;

use App\Application\Businesses\CreateBusiness;
use App\Domain\Businesses\Enums\BusinessOriginType;
use App\Domain\Businesses\Enums\BusinessStage;
use App\Domain\Businesses\Enums\WorkspaceStatus;
use App\Domain\Identity\Enums\AccountStatus;
use App\Domain\Members\Enums\MembershipAccessStatus;
use App\Infrastructure\Persistence\Eloquent\Businesses\Business;
use App\Infrastructure\Persistence\Eloquent\Identity\User;
use App\Infrastructure\Persistence\Eloquent\Members\Membership;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use InvalidArgumentException;
use Tests\TestCase;

final class BusinessOnboardingTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_atomically_creates_business_and_initial_active_membership(): void
    {
        $user = $this->createActiveUser('onboarding@example.com');

        $business = $this->app->make(CreateBusiness::class)->handle(
            $user,
            '  First Workspace  ',
            BusinessOriginType::StartedThroughPbr,
            BusinessStage::Planning,
            'THB',
        );

        $membership = Membership::query()
            ->where('user_id', $user->id)
            ->where('business_id', $business->id)
            ->firstOrFail();

        $business->refresh();

        $this->assertSame('First Workspace', $business->name);
        $this->assertSame(
            BusinessOriginType::StartedThroughPbr,
            $business->origin_type,
        );
        $this->assertSame(BusinessStage::Planning, $business->business_stage);
        $this->assertNull($business->setup_phase);
        $this->assertSame(WorkspaceStatus::Active, $business->workspace_status);
        $this->assertSame('THB', $business->base_currency);

        $this->assertSame($user->id, $membership->user_id);
        $this->assertSame($business->id, $membership->business_id);
        $this->assertSame(
            MembershipAccessStatus::Active,
            $membership->access_status,
        );

        $this->assertSame(1, Membership::query()->count());
    }

    public function test_origin_and_business_stage_remain_independent(): void
    {
        $user = $this->createActiveUser('independent-dimensions@example.com');

        $business = $this->app->make(CreateBusiness::class)->handle(
            $user,
            'Existing But Early Stage',
            BusinessOriginType::ExistingBusinessImportedIntoPbr,
            BusinessStage::Validation,
            'MMK',
        );

        $business->refresh();

        $this->assertSame(
            BusinessOriginType::ExistingBusinessImportedIntoPbr,
            $business->origin_type,
        );
        $this->assertSame(BusinessStage::Validation, $business->business_stage);
        $this->assertNull($business->setup_phase);
        $this->assertSame(WorkspaceStatus::Active, $business->workspace_status);
        $this->assertSame('MMK', $business->base_currency);
    }

    public function test_blank_business_name_is_rejected_before_persistence(): void
    {
        $user = $this->createActiveUser('blank-name@example.com');

        try {
            $this->app->make(CreateBusiness::class)->handle(
                $user,
                '   ',
                BusinessOriginType::StartedThroughPbr,
                BusinessStage::Idea,
                'USD',
            );

            $this->fail('Expected blank Business name rejection.');
        } catch (InvalidArgumentException) {
            $this->assertDatabaseCount('businesses', 0);
            $this->assertDatabaseCount('memberships', 0);
        }
    }

    public function test_business_name_over_160_characters_is_rejected_before_persistence(): void
    {
        $user = $this->createActiveUser('long-name@example.com');

        try {
            $this->app->make(CreateBusiness::class)->handle(
                $user,
                str_repeat('A', 161),
                BusinessOriginType::StartedThroughPbr,
                BusinessStage::Idea,
                'USD',
            );

            $this->fail('Expected overlong Business name rejection.');
        } catch (InvalidArgumentException) {
            $this->assertDatabaseCount('businesses', 0);
            $this->assertDatabaseCount('memberships', 0);
        }
    }

    public function test_noncanonical_base_currency_is_rejected_before_persistence(): void
    {
        $user = $this->createActiveUser('currency@example.com');

        try {
            $this->app->make(CreateBusiness::class)->handle(
                $user,
                'Currency Guard Business',
                BusinessOriginType::StartedThroughPbr,
                BusinessStage::Idea,
                'usd',
            );

            $this->fail('Expected noncanonical base currency rejection.');
        } catch (InvalidArgumentException) {
            $this->assertDatabaseCount('businesses', 0);
            $this->assertDatabaseCount('memberships', 0);
        }
    }

    public function test_membership_failure_rolls_back_business_creation(): void
    {
        $user = $this->createActiveUser('rollback@example.com');
        $userId = $user->id;

        $user->delete();

        $this->assertDatabaseMissing('users', [
            'id' => $userId,
        ]);

        try {
            $this->app->make(CreateBusiness::class)->handle(
                $user,
                'Must Roll Back',
                BusinessOriginType::StartedThroughPbr,
                BusinessStage::Idea,
                'USD',
            );

            $this->fail('Expected Membership foreign-key failure.');
        } catch (QueryException) {
            $this->assertDatabaseMissing('businesses', [
                'name' => 'Must Roll Back',
            ]);

            $this->assertDatabaseCount('memberships', 0);
        }
    }

    private function createActiveUser(string $email): User
    {
        return User::query()->create([
            'email' => $email,
            'password' => 'not-a-real-hash',
            'status' => AccountStatus::Active,
            'password_changed_at' => now(),
        ]);
    }
    public function test_padded_base_currency_is_rejected_without_persistence(): void
    {
        $user = \App\Infrastructure\Persistence\Eloquent\Identity\User::query()->create([
            'email' => 'direct-padded-currency@example.com',
            'password' => 'not-a-real-hash',
            'status' => \App\Domain\Identity\Enums\AccountStatus::Active,
            'password_changed_at' => now(),
        ]);

        $this->assertDatabaseCount('businesses', 0);
        $this->assertDatabaseCount('memberships', 0);

        try {
            app(\App\Application\Businesses\CreateBusiness::class)->handle(
                $user,
                'Padded Currency Rejection',
                \App\Domain\Businesses\Enums\BusinessOriginType::StartedThroughPbr,
                \App\Domain\Businesses\Enums\BusinessStage::Idea,
                ' USD ',
            );

            $this->fail('Padded base currency must not be silently normalized.');
        } catch (\InvalidArgumentException $exception) {
            $this->assertSame(
                'Base currency must be a three-letter uppercase currency code.',
                $exception->getMessage(),
            );
        }

        $this->assertDatabaseCount('businesses', 0);
        $this->assertDatabaseCount('memberships', 0);
    }

}
