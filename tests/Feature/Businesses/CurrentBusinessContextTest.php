<?php

namespace Tests\Feature\Businesses;

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
use Tests\TestCase;

final class CurrentBusinessContextTest extends TestCase
{
    use RefreshDatabase;

    /** @var list<string> */
    private array $redisSessionIds = [];

    private const string SCOPED_URI = '/__a8-test/current-business';

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

    public function test_active_membership_can_select_current_business(): void
    {
        $user = $this->createActiveUser('context-active@example.com');
        $business = $this->createBusiness('Context Active Business');

        $this->createMembership($user, $business);

        $response = $this
            ->actingAs($user)
            ->post(route('business-context.select'), [
                'business_id' => $business->id,
            ]);

        $response
            ->assertRedirect()
            ->assertSessionHas(
                EnsureCurrentBusinessContext::SESSION_KEY,
                $business->id,
            );
    }

    public function test_business_without_membership_cannot_be_selected(): void
    {
        $user = $this->createActiveUser('context-no-membership@example.com');
        $business = $this->createBusiness('No Membership Business');

        $response = $this
            ->actingAs($user)
            ->post(route('business-context.select'), [
                'business_id' => $business->id,
            ]);

        $response
            ->assertForbidden()
            ->assertSessionMissing(EnsureCurrentBusinessContext::SESSION_KEY);
    }

    public function test_user_can_switch_between_two_active_membership_businesses(): void
    {
        $user = $this->createActiveUser('context-switch@example.com');
        $businessA = $this->createBusiness('Switch Business A');
        $businessB = $this->createBusiness('Switch Business B');

        $this->createMembership($user, $businessA);
        $this->createMembership($user, $businessB);

        $this
            ->actingAs($user)
            ->post(route('business-context.select'), [
                'business_id' => $businessA->id,
            ])
            ->assertRedirect()
            ->assertSessionHas(
                EnsureCurrentBusinessContext::SESSION_KEY,
                $businessA->id,
            );

        $this
            ->actingAs($user)
            ->post(route('business-context.select'), [
                'business_id' => $businessB->id,
            ])
            ->assertRedirect()
            ->assertSessionHas(
                EnsureCurrentBusinessContext::SESSION_KEY,
                $businessB->id,
            );
    }

    public function test_business_scoped_request_denies_missing_context(): void
    {
        $user = $this->createActiveUser('context-missing@example.com');

        $this
            ->actingAs($user)
            ->get(self::SCOPED_URI)
            ->assertForbidden();
    }

    public function test_business_scoped_request_revalidates_membership_and_denies_stale_context(): void
    {
        $user = $this->createActiveUser('context-stale@example.com');
        $business = $this->createBusiness('Stale Context Business');
        $membership = $this->createMembership($user, $business);

        $membership->delete();

        $this
            ->actingAs($user)
            ->withSession([
                EnsureCurrentBusinessContext::SESSION_KEY => $business->id,
            ])
            ->get(self::SCOPED_URI)
            ->assertForbidden()
            ->assertSessionMissing(EnsureCurrentBusinessContext::SESSION_KEY);
    }

    public function test_malformed_session_business_id_fails_closed(): void
    {
        $user = $this->createActiveUser('context-malformed@example.com');

        $this
            ->actingAs($user)
            ->withSession([
                EnsureCurrentBusinessContext::SESSION_KEY => 'not-a-uuid',
            ])
            ->get(self::SCOPED_URI)
            ->assertForbidden()
            ->assertSessionMissing(EnsureCurrentBusinessContext::SESSION_KEY);
    }

    public function test_valid_business_scoped_request_exposes_only_resolved_business(): void
    {
        $user = $this->createActiveUser('context-valid@example.com');
        $business = $this->createBusiness('Valid Context Business');

        $this->createMembership($user, $business);

        $this
            ->actingAs($user)
            ->withSession([
                EnsureCurrentBusinessContext::SESSION_KEY => $business->id,
            ])
            ->get(self::SCOPED_URI)
            ->assertOk()
            ->assertExactJson([
                'business_id' => $business->id,
            ]);
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
