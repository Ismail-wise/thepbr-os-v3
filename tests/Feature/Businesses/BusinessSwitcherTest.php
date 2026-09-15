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
use App\Infrastructure\Persistence\Eloquent\Identity\UserProfile;
use App\Infrastructure\Persistence\Eloquent\Members\Membership;
use Illuminate\Foundation\Http\Events\RequestHandled;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Redis;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

final class BusinessSwitcherTest extends TestCase
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

    public function test_home_lists_only_currently_accessible_businesses_without_auto_selecting_one(): void
    {
        $user = $this->createActiveUser('switcher@example.com');

        $zulu = $this->createBusiness('Zulu Studio');
        $alpha = $this->createBusiness('Alpha Works');
        $hidden = $this->createBusiness('Hidden Holdings');

        $this->createActiveMembership($user, $zulu);
        $this->createActiveMembership($user, $alpha);

        $response = $this->actingAs($user)->get('/');

        $response->assertOk()
            ->assertInertia(
                fn (Assert $page) => $page
                    ->component('AccountHome')
                    ->where('workspace.businesses', [
                        [
                            'id' => $alpha->id,
                            'name' => 'Alpha Works',
                        ],
                        [
                            'id' => $zulu->id,
                            'name' => 'Zulu Studio',
                        ],
                    ])
                    ->where('workspace.currentBusiness', null),
            );

        $response->assertDontSee($hidden->id);
        $response->assertDontSee('Hidden Holdings');
    }

    public function test_removed_membership_removes_business_and_stale_session_does_not_restore_access(): void
    {
        $user = $this->createActiveUser('removed-membership@example.com');

        $accessible = $this->createBusiness('Accessible Business');
        $removed = $this->createBusiness('Removed Business');

        $this->createActiveMembership($user, $accessible);

        $removedMembership = $this->createActiveMembership($user, $removed);
        $removedMembership->delete();

        $response = $this
            ->actingAs($user)
            ->withSession([
                EnsureCurrentBusinessContext::SESSION_KEY => $removed->id,
            ])
            ->get('/');

        $response->assertOk()
            ->assertInertia(
                fn (Assert $page) => $page
                    ->component('AccountHome')
                    ->where('workspace.businesses', [
                        [
                            'id' => $accessible->id,
                            'name' => 'Accessible Business',
                        ],
                    ])
                    ->where('workspace.currentBusiness', null),
            );

        $response->assertDontSee($removed->id);
        $response->assertDontSee('Removed Business');
    }

    public function test_workspace_props_are_deterministic_across_home_settings_and_create_business_pages(): void
    {
        $user = $this->createActiveUser('shared-workspace@example.com');
        $business = $this->createBusiness('Current Workspace');

        $this->createActiveMembership($user, $business);

        $workspace = [
            'businesses' => [
                [
                    'id' => $business->id,
                    'name' => 'Current Workspace',
                ],
            ],
            'currentBusiness' => [
                'id' => $business->id,
                'name' => 'Current Workspace',
            ],
        ];

        foreach ([
            ['/', 'AccountHome'],
            ['/account/settings', 'Account/Settings'],
            ['/businesses/create', 'Businesses/Create'],
        ] as [$uri, $component]) {
            $this
                ->actingAs($user)
                ->withSession([
                    EnsureCurrentBusinessContext::SESSION_KEY => $business->id,
                ])
                ->get($uri)
                ->assertOk()
                ->assertInertia(
                    fn (Assert $page) => $page
                        ->component($component)
                        ->where('workspace', $workspace),
                );
        }
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
            'display_name' => 'A10 User',
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
