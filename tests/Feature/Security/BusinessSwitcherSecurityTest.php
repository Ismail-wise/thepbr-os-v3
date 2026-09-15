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
use App\Infrastructure\Persistence\Eloquent\Identity\UserProfile;
use App\Infrastructure\Persistence\Eloquent\Members\Membership;
use Illuminate\Foundation\Http\Events\RequestHandled;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Redis;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

final class BusinessSwitcherSecurityTest extends TestCase
{
    use RefreshDatabase;

    private const string PASSWORD = 'correct horse battery staple';

    /** @var list<string> */
    private array $redisSessionIds = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutVite();

        $this->app['events']->listen(
            RequestHandled::class,
            function (RequestHandled $event): void {
                if ($event->request->hasSession()) {
                    $this->redisSessionIds[] = $event->request->session()->getId();
                }
            },
        );
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

    public function test_shared_workspace_props_do_not_leak_another_users_business(): void
    {
        $user = $this->createActiveUser('visible@example.com');
        $otherUser = $this->createActiveUser('hidden@example.com');

        $visible = $this->createBusiness('Visible Business');
        $hidden = $this->createBusiness('Restricted Business');

        $this->createActiveMembership($user, $visible);
        $this->createActiveMembership($otherUser, $hidden);

        $response = $this->actingAs($user)->get('/');

        $response->assertOk()
            ->assertInertia(
                fn (Assert $page) => $page
                    ->component('AccountHome')
                    ->where('workspace.businesses', [
                        [
                            'id' => $visible->id,
                            'name' => 'Visible Business',
                        ],
                    ])
                    ->where('workspace.currentBusiness', null),
            );

        $response->assertDontSee($hidden->id);
        $response->assertDontSee('Restricted Business');
    }

    public function test_deleted_membership_cannot_be_recovered_through_stale_current_business_session(): void
    {
        $user = $this->createActiveUser('stale@example.com');
        $business = $this->createBusiness('Former Workspace');

        $membership = $this->createActiveMembership($user, $business);
        $membership->delete();

        $response = $this
            ->actingAs($user)
            ->withSession([
                EnsureCurrentBusinessContext::SESSION_KEY => $business->id,
            ])
            ->get('/');

        $response->assertOk()
            ->assertInertia(
                fn (Assert $page) => $page
                    ->component('AccountHome')
                    ->where('workspace.businesses', [])
                    ->where('workspace.currentBusiness', null),
            );

        $response->assertDontSee($business->id);
        $response->assertDontSee('Former Workspace');
    }

    public function test_forged_cross_business_switch_remains_forbidden_and_does_not_replace_session_context(): void
    {
        $user = $this->createActiveUser('user-a@example.com');
        $otherUser = $this->createActiveUser('user-b@example.com');

        $allowed = $this->createBusiness('Allowed Business');
        $forbidden = $this->createBusiness('Forbidden Business');

        $this->createActiveMembership($user, $allowed);
        $this->createActiveMembership($otherUser, $forbidden);

        $this
            ->actingAs($user)
            ->withSession([
                EnsureCurrentBusinessContext::SESSION_KEY => $allowed->id,
            ])
            ->post(route('business-context.select'), [
                'business_id' => $forbidden->id,
            ])
            ->assertForbidden()
            ->assertSessionHas(
                EnsureCurrentBusinessContext::SESSION_KEY,
                $allowed->id,
            );
    }

    private function createActiveUser(string $email): User
    {
        $user = User::query()->create([
            'email' => $email,
            'password' => Hash::make(self::PASSWORD),
            'status' => AccountStatus::Active,
            'password_changed_at' => now(),
        ]);

        UserProfile::query()->create([
            'user_id' => $user->id,
            'display_name' => 'A10 Security User',
            'language_mode' => 'en',
            'timezone' => 'Asia/Yangon',
        ]);

        return $user;
    }

    private function createBusiness(string $name): Business
    {
        return Business::query()->create([
            'name' => $name,
            'origin_type' => BusinessOriginType::StartedThroughPbr,
            'business_stage' => BusinessStage::Planning,
            'setup_phase' => SetupPhase::Formation,
            'workspace_status' => WorkspaceStatus::Active,
            'base_currency' => 'USD',
        ]);
    }

    private function createActiveMembership(
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
