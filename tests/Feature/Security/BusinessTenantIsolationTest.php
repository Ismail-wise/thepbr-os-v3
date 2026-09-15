<?php

namespace Tests\Feature\Security;

use App\Domain\Businesses\Enums\BusinessOriginType;
use App\Domain\Businesses\Enums\BusinessStage;
use App\Domain\Businesses\Enums\SetupPhase;
use App\Domain\Businesses\Enums\WorkspaceStatus;
use App\Domain\Identity\Enums\AccountStatus;
use App\Domain\Members\Enums\MembershipAccessStatus;
use App\Http\Middleware\EnsureCurrentBusinessContext;
use App\Infrastructure\Persistence\Eloquent\Businesses\Business;
use App\Infrastructure\Persistence\Eloquent\Identity\User;
use App\Infrastructure\Persistence\Eloquent\Members\Membership;
use App\Presentation\Http\Middleware\EnsureActiveAccount;
use Illuminate\Foundation\Http\Events\RequestHandled;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use Tests\TestCase;

final class BusinessTenantIsolationTest extends TestCase
{
    use RefreshDatabase;

    /** @var list<string> */
    private array $redisSessionIds = [];

    private const string SCOPED_URI = '/__a8-test/tenant-boundary';

    protected function setUp(): void
    {
        parent::setUp();

        $this->app['events']->listen(RequestHandled::class, function (RequestHandled $event): void {
            if ($event->request->hasSession()) {
                $this->redisSessionIds[] = $event->request->session()->getId();
            }
        });

        Route::get(self::SCOPED_URI, function (Request $request): JsonResponse {
            $business = $request->attributes->get(
                EnsureCurrentBusinessContext::ATTRIBUTE_KEY,
            );

            abort_unless($business instanceof Business, 500);

            return response()->json([
                'business_id' => $business->id,
            ]);
        })->middleware([
            'web',
            'auth',
            EnsureActiveAccount::class,
            'current.business',
        ]);
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

    public function test_same_user_business_a_membership_does_not_authorize_business_b(): void
    {
        $user = $this->createActiveUser('tenant-same-user@example.com');
        $businessA = $this->createBusiness('Tenant Same User A');
        $businessB = $this->createBusiness('Tenant Same User B');

        $this->createMembership($user, $businessA);

        $this
            ->actingAs($user)
            ->post(route('business-context.select'), [
                'business_id' => $businessB->id,
            ])
            ->assertForbidden()
            ->assertSessionMissing(EnsureCurrentBusinessContext::SESSION_KEY);
    }

    public function test_user_cannot_use_another_users_business_as_current_context(): void
    {
        $userA = $this->createActiveUser('tenant-user-a@example.com');
        $userB = $this->createActiveUser('tenant-user-b@example.com');
        $businessB = $this->createBusiness('Tenant User B Business');

        $this->createMembership($userB, $businessB);

        $this
            ->actingAs($userA)
            ->withSession([
                EnsureCurrentBusinessContext::SESSION_KEY => $businessB->id,
            ])
            ->get(self::SCOPED_URI)
            ->assertForbidden()
            ->assertSessionMissing(EnsureCurrentBusinessContext::SESSION_KEY);
    }

    public function test_business_a_context_never_resolves_business_b_without_membership(): void
    {
        $user = $this->createActiveUser('tenant-a-b@example.com');
        $businessA = $this->createBusiness('Tenant Boundary A');
        $businessB = $this->createBusiness('Tenant Boundary B');

        $this->createMembership($user, $businessA);

        $this
            ->actingAs($user)
            ->withSession([
                EnsureCurrentBusinessContext::SESSION_KEY => $businessA->id,
            ])
            ->get(self::SCOPED_URI)
            ->assertOk()
            ->assertExactJson([
                'business_id' => $businessA->id,
            ]);

        $this
            ->actingAs($user)
            ->withSession([
                EnsureCurrentBusinessContext::SESSION_KEY => $businessB->id,
            ])
            ->get(self::SCOPED_URI)
            ->assertForbidden()
            ->assertSessionMissing(EnsureCurrentBusinessContext::SESSION_KEY);
    }

    public function test_existing_inaccessible_and_nonexistent_business_ids_both_fail_closed(): void
    {
        $user = $this->createActiveUser('tenant-object-id@example.com');
        $businessA = $this->createBusiness('Tenant Object A');
        $businessB = $this->createBusiness('Tenant Object B');

        $this->createMembership($user, $businessA);

        $this
            ->actingAs($user)
            ->post(route('business-context.select'), [
                'business_id' => $businessB->id,
            ])
            ->assertForbidden();

        $this
            ->actingAs($user)
            ->post(route('business-context.select'), [
                'business_id' => (string) Str::uuid(),
            ])
            ->assertForbidden();
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
