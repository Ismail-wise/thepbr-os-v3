<?php

namespace Tests\Feature\Security;

use App\Domain\Businesses\Enums\BusinessOriginType;
use App\Domain\Businesses\Enums\BusinessStage;
use App\Domain\Businesses\Enums\WorkspaceStatus;
use App\Domain\Identity\Enums\AccountStatus;
use App\Domain\Members\Enums\MembershipAccessStatus;
use App\Http\Middleware\EnsureCurrentBusinessContext;
use App\Infrastructure\Persistence\Eloquent\Businesses\Business;
use App\Infrastructure\Persistence\Eloquent\Identity\User;
use App\Infrastructure\Persistence\Eloquent\Members\Membership;
use Illuminate\Foundation\Http\Events\RequestHandled;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Redis;
use Tests\TestCase;

final class BusinessOnboardingSecurityTest extends TestCase
{
    use RefreshDatabase;

    /** @var list<string> */
    private array $redisSessionIds = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->app['events']->listen(RequestHandled::class, function (RequestHandled $event): void {
            if ($event->request->hasSession()) {
                $this->redisSessionIds[] = $event->request->session()->getId();
            }
        });
    }

    protected function tearDown(): void
    {
        try {
            if (config('session.driver') === 'redis') {
                $redis = Redis::connection('default');
                $prefix = (string) config('session.prefix', '');

                foreach (array_unique($this->redisSessionIds) as $sessionId) {
                    $redis->del($prefix.$sessionId);
                }
            }
        } finally {
            parent::tearDown();
        }
    }

    public function test_active_user_can_create_started_through_pbr_business_and_select_it(): void
    {
        $user = $this->createUser(
            'started-origin@example.com',
            AccountStatus::Active,
        );

        $response = $this
            ->actingAs($user)
            ->post(route('businesses.store'), [
                'name' => 'Started Through PBR',
                'origin_type' => BusinessOriginType::StartedThroughPbr->value,
                'business_stage' => BusinessStage::Planning->value,
                'base_currency' => 'THB',
            ]);

        $business = Business::query()
            ->where('name', 'Started Through PBR')
            ->firstOrFail();

        $membership = Membership::query()
            ->where('user_id', $user->id)
            ->where('business_id', $business->id)
            ->firstOrFail();

        $response
            ->assertRedirect(route('businesses.create'))
            ->assertSessionHas(
                EnsureCurrentBusinessContext::SESSION_KEY,
                $business->id,
            )
            ->assertSessionHas('success', 'Business created successfully.');

        $this->assertSame(
            BusinessOriginType::StartedThroughPbr,
            $business->origin_type,
        );
        $this->assertSame(BusinessStage::Planning, $business->business_stage);
        $this->assertNull($business->setup_phase);
        $this->assertSame(WorkspaceStatus::Active, $business->workspace_status);
        $this->assertSame('THB', $business->base_currency);
        $this->assertSame(MembershipAccessStatus::Active, $membership->access_status);
    }

    public function test_existing_origin_can_use_independent_stage_and_become_current_business(): void
    {
        $user = $this->createUser(
            'existing-origin@example.com',
            AccountStatus::Active,
        );

        $this
            ->actingAs($user)
            ->post(route('businesses.store'), [
                'name' => 'First Workspace',
                'origin_type' => BusinessOriginType::StartedThroughPbr->value,
                'business_stage' => BusinessStage::Idea->value,
                'base_currency' => 'USD',
            ])
            ->assertRedirect(route('businesses.create'));

        $response = $this
            ->actingAs($user)
            ->post(route('businesses.store'), [
                'name' => 'Existing Imported Business',
                'origin_type' => BusinessOriginType::ExistingBusinessImportedIntoPbr->value,
                'business_stage' => BusinessStage::Validation->value,
                'base_currency' => 'MMK',
            ]);

        $business = Business::query()
            ->where('name', 'Existing Imported Business')
            ->firstOrFail();

        $response
            ->assertRedirect(route('businesses.create'))
            ->assertSessionHas(
                EnsureCurrentBusinessContext::SESSION_KEY,
                $business->id,
            );

        $this->assertSame(
            BusinessOriginType::ExistingBusinessImportedIntoPbr,
            $business->origin_type,
        );
        $this->assertSame(BusinessStage::Validation, $business->business_stage);
        $this->assertNull($business->setup_phase);
        $this->assertSame(WorkspaceStatus::Active, $business->workspace_status);
        $this->assertSame(2, Business::query()->count());
        $this->assertSame(2, Membership::query()->count());
    }

    public function test_submitted_user_and_owner_like_fields_cannot_choose_creator_authority(): void
    {
        $authenticatedUser = $this->createUser(
            'principal@example.com',
            AccountStatus::Active,
        );

        $otherUser = $this->createUser(
            'foreign-principal@example.com',
            AccountStatus::Active,
        );

        $response = $this
            ->actingAs($authenticatedUser)
            ->post(route('businesses.store'), [
                'name' => 'Principal Bound Business',
                'origin_type' => BusinessOriginType::StartedThroughPbr->value,
                'business_stage' => BusinessStage::Operating->value,
                'base_currency' => 'USD',
                'user_id' => $otherUser->id,
                'authenticated_user_id' => $otherUser->id,
                'owner_user_id' => $otherUser->id,
                'workspace_status' => WorkspaceStatus::Closed->value,
                'setup_phase' => 'formation',
            ]);

        $business = Business::query()
            ->where('name', 'Principal Bound Business')
            ->firstOrFail();

        $response
            ->assertRedirect(route('businesses.create'))
            ->assertSessionHas(
                EnsureCurrentBusinessContext::SESSION_KEY,
                $business->id,
            );

        $this->assertDatabaseHas('memberships', [
            'user_id' => $authenticatedUser->id,
            'business_id' => $business->id,
            'access_status' => MembershipAccessStatus::Active->value,
        ]);

        $this->assertDatabaseMissing('memberships', [
            'user_id' => $otherUser->id,
            'business_id' => $business->id,
        ]);

        $business->refresh();

        $this->assertNull($business->setup_phase);
        $this->assertSame(WorkspaceStatus::Active, $business->workspace_status);
    }

    public function test_invalid_origin_is_rejected_without_partial_rows(): void
    {
        $user = $this->createUser(
            'invalid-origin@example.com',
            AccountStatus::Active,
        );

        $this
            ->actingAs($user)
            ->post(route('businesses.store'), [
                ...$this->validPayload(),
                'origin_type' => 'not_a_real_origin',
            ])
            ->assertSessionHasErrors(['origin_type']);

        $this->assertDatabaseCount('businesses', 0);
        $this->assertDatabaseCount('memberships', 0);
    }

    public function test_invalid_stage_is_rejected_without_partial_rows(): void
    {
        $user = $this->createUser(
            'invalid-stage@example.com',
            AccountStatus::Active,
        );

        $this
            ->actingAs($user)
            ->post(route('businesses.store'), [
                ...$this->validPayload(),
                'business_stage' => 'formation',
            ])
            ->assertSessionHasErrors(['business_stage']);

        $this->assertDatabaseCount('businesses', 0);
        $this->assertDatabaseCount('memberships', 0);
    }

    public function test_lowercase_currency_is_rejected_without_normalization_or_partial_rows(): void
    {
        $user = $this->createUser(
            'invalid-currency@example.com',
            AccountStatus::Active,
        );

        $this
            ->actingAs($user)
            ->post(route('businesses.store'), [
                ...$this->validPayload(),
                'base_currency' => 'usd',
            ])
            ->assertSessionHasErrors(['base_currency']);

        $this->assertDatabaseCount('businesses', 0);
        $this->assertDatabaseCount('memberships', 0);
    }

    public function test_blank_name_is_rejected_without_partial_rows(): void
    {
        $user = $this->createUser(
            'invalid-name@example.com',
            AccountStatus::Active,
        );

        $this
            ->actingAs($user)
            ->post(route('businesses.store'), [
                ...$this->validPayload(),
                'name' => '   ',
            ])
            ->assertSessionHasErrors(['name']);

        $this->assertDatabaseCount('businesses', 0);
        $this->assertDatabaseCount('memberships', 0);
    }

    public function test_failed_request_preserves_previous_current_business_context(): void
    {
        $user = $this->createUser(
            'preserve-context@example.com',
            AccountStatus::Active,
        );

        $this
            ->actingAs($user)
            ->post(route('businesses.store'), [
                'name' => 'Preserved Current Business',
                'origin_type' => BusinessOriginType::StartedThroughPbr->value,
                'business_stage' => BusinessStage::Idea->value,
                'base_currency' => 'USD',
            ])
            ->assertRedirect(route('businesses.create'));

        $business = Business::query()
            ->where('name', 'Preserved Current Business')
            ->firstOrFail();

        $this
            ->actingAs($user)
            ->post(route('businesses.store'), [
                ...$this->validPayload(),
                'origin_type' => 'invalid_origin',
            ])
            ->assertSessionHasErrors(['origin_type'])
            ->assertSessionHas(
                EnsureCurrentBusinessContext::SESSION_KEY,
                $business->id,
            );

        $this->assertDatabaseCount('businesses', 1);
        $this->assertDatabaseCount('memberships', 1);
    }

    public function test_unauthenticated_user_cannot_create_business(): void
    {
        $this
            ->post(route('businesses.store'), $this->validPayload())
            ->assertRedirect(route('login'));

        $this->assertDatabaseCount('businesses', 0);
        $this->assertDatabaseCount('memberships', 0);
    }

    public function test_non_active_authenticated_user_is_terminated_before_business_creation(): void
    {
        $user = $this->createUser(
            'suspended@example.com',
            AccountStatus::Suspended,
        );

        $this
            ->actingAs($user)
            ->post(route('businesses.store'), $this->validPayload());

        $this->assertGuest();
        $this->assertDatabaseCount('businesses', 0);
        $this->assertDatabaseCount('memberships', 0);
    }

    /**
     * @return array{
     *     name: string,
     *     origin_type: string,
     *     business_stage: string,
     *     base_currency: string
     * }
     */
    private function validPayload(): array
    {
        return [
            'name' => 'Valid Business',
            'origin_type' => BusinessOriginType::StartedThroughPbr->value,
            'business_stage' => BusinessStage::Idea->value,
            'base_currency' => 'USD',
        ];
    }

    private function createUser(string $email, AccountStatus $status): User
    {
        return User::query()->create([
            'email' => $email,
            'password' => 'not-a-real-hash',
            'status' => $status,
            'password_changed_at' => now(),
        ]);
    }

    public function test_padded_base_currency_is_rejected_without_partial_rows(): void
    {
        $user = User::query()->create([
            'email' => 'http-padded-currency@example.com',
            'password' => 'not-a-real-hash',
            'status' => AccountStatus::Active,
            'password_changed_at' => now(),
        ]);

        $response = $this
            ->actingAs($user)
            ->post('/businesses', [
                'name' => 'HTTP Padded Currency Rejection',
                'origin_type' => BusinessOriginType::StartedThroughPbr->value,
                'business_stage' => BusinessStage::Idea->value,
                'base_currency' => ' USD ',
            ]);

        $response->assertSessionHasErrors('base_currency');

        $this->assertDatabaseCount('businesses', 0);
        $this->assertDatabaseCount('memberships', 0);
    }
}
