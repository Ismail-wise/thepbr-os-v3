<?php

declare(strict_types=1);

namespace Tests\Feature\Access;

use App\Domain\Access\Enums\PermissionEffect;
use App\Domain\Businesses\Enums\BusinessOriginType;
use App\Domain\Businesses\Enums\BusinessStage;
use App\Domain\Businesses\Enums\SetupPhase;
use App\Domain\Businesses\Enums\WorkspaceStatus;
use App\Domain\Identity\Enums\AccountStatus;
use App\Domain\Members\Enums\MembershipAccessStatus;
use App\Http\Middleware\EnsureCurrentBusinessContext;
use App\Infrastructure\Persistence\Eloquent\Access\AccessPolicy;
use App\Infrastructure\Persistence\Eloquent\Access\Permission;
use App\Infrastructure\Persistence\Eloquent\Access\PermissionGrant;
use App\Infrastructure\Persistence\Eloquent\Access\PermissionProfile;
use App\Infrastructure\Persistence\Eloquent\Access\RecordAccessRule;
use App\Infrastructure\Persistence\Eloquent\Businesses\Business;
use App\Infrastructure\Persistence\Eloquent\Identity\User;
use App\Infrastructure\Persistence\Eloquent\Members\Membership;
use Illuminate\Foundation\Http\Events\RequestHandled;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

final class WorkspaceAccessPageTest extends TestCase
{
    use RefreshDatabase;

    private const VIEW_CAPABILITY = 'permission_profiles.view';

    /** @var array<string, true> */
    private array $capturedSessionIds = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->capturedSessionIds = [];

        $this->app['events']->listen(
            RequestHandled::class,
            function (RequestHandled $event): void {
                if (! $event->request->hasSession()) {
                    return;
                }

                $sessionId = $event->request->session()->getId();

                if ($sessionId !== '') {
                    $this->capturedSessionIds[$sessionId] = true;
                }
            },
        );
    }

    protected function tearDown(): void
    {
        try {
            if (
                config('session.driver') === 'redis'
                && $this->capturedSessionIds !== []
            ) {
                $sessionPrefix = (string) config('session.prefix', '');
                $redis = Redis::connection('default');

                foreach (array_keys($this->capturedSessionIds) as $sessionId) {
                    $redis->del($sessionPrefix.$sessionId);
                }
            }
        } finally {
            parent::tearDown();
        }
    }

    public function test_workspace_access_page_filters_profiles_with_existing_authorization_rules(): void
    {
        $this->withoutVite();

        [$user, $business, $membership, $viewPermission] =
            $this->authorizedContext();

        $activityPermission = Permission::query()->create([
            'key' => 'records.activity.view',
        ]);

        $visibleProfile = PermissionProfile::query()->create([
            'business_id' => $business->getKey(),
            'name' => 'F2 Operator',
        ]);

        $hiddenProfile = PermissionProfile::query()->create([
            'business_id' => $business->getKey(),
            'name' => 'Restricted Profile',
        ]);

        $now = now();

        DB::table('permission_profile_permissions')->insert([
            'business_id' => $business->getKey(),
            'permission_profile_id' => $visibleProfile->getKey(),
            'permission_id' => $activityPermission->getKey(),
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        DB::table('membership_permission_profiles')->insert([
            'business_id' => $business->getKey(),
            'membership_id' => $membership->getKey(),
            'permission_profile_id' => $visibleProfile->getKey(),
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        AccessPolicy::query()->create([
            'business_id' => $business->getKey(),
            'membership_id' => $membership->getKey(),
            'permission_profile_id' => null,
            'permission_id' => $viewPermission->getKey(),
            'resource_type' => PermissionProfile::class,
            'effect' => PermissionEffect::Allow,
        ]);

        RecordAccessRule::query()->create([
            'business_id' => $business->getKey(),
            'membership_id' => $membership->getKey(),
            'permission_profile_id' => null,
            'permission_id' => $viewPermission->getKey(),
            'resource_type' => PermissionProfile::class,
            'resource_id' => $hiddenProfile->getKey(),
            'effect' => PermissionEffect::Deny,
        ]);

        $businessB = $this->createBusiness('Access Tenant B');

        PermissionProfile::query()->create([
            'business_id' => $businessB->getKey(),
            'name' => 'Business B Private Profile',
        ]);

        $response = $this
            ->actingAs($user)
            ->withSession([
                EnsureCurrentBusinessContext::SESSION_KEY => (string) $business->getKey(),
            ])
            ->get('/workspace/access');

        $response
            ->assertOk()
            ->assertInertia(
                fn (Assert $page): Assert => $page
                    ->component('Access/Index')
                    ->where(
                        'access.business.id',
                        (string) $business->getKey(),
                    )
                    ->where('access.business.name', $business->name)
                    ->where(
                        'access.membership.accessStatus',
                        MembershipAccessStatus::Active->value,
                    )
                    ->has(
                        'access.profiles',
                        1,
                        fn (Assert $profile): Assert => $profile
                            ->where(
                                'id',
                                (string) $visibleProfile->getKey(),
                            )
                            ->where('name', 'F2 Operator')
                            ->where('assigned', true)
                            ->where(
                                'capabilities.0',
                                'records.activity.view',
                            ),
                    )
                    ->has(
                        'access.directGrants',
                        1,
                        fn (Assert $grant): Assert => $grant
                            ->where(
                                'capability',
                                self::VIEW_CAPABILITY,
                            )
                            ->where(
                                'effect',
                                PermissionEffect::Allow->value,
                            ),
                    ),
            );

        $response->assertDontSee('Restricted Profile');
        $response->assertDontSee('Business B Private Profile');
    }

    public function test_workspace_access_page_fails_closed_without_view_capability(): void
    {
        $this->withoutVite();

        $user = $this->createUser();
        $business = $this->createBusiness('No Access Capability');

        Membership::query()->create([
            'user_id' => $user->getKey(),
            'business_id' => $business->getKey(),
            'access_status' => MembershipAccessStatus::Active,
        ]);

        Permission::query()->create([
            'key' => self::VIEW_CAPABILITY,
        ]);

        $this
            ->actingAs($user)
            ->withSession([
                EnsureCurrentBusinessContext::SESSION_KEY => (string) $business->getKey(),
            ])
            ->get('/workspace/access')
            ->assertNotFound();
    }

    public function test_workspace_access_route_is_current_business_scoped(): void
    {
        $route = app('router')
            ->getRoutes()
            ->getByName('workspace.access.index');

        $this->assertNotNull($route);
        $this->assertSame('workspace/access', $route->uri());

        $this->assertContains(
            EnsureCurrentBusinessContext::class,
            $route->gatherMiddleware(),
        );
    }

    /**
     * @return array{User, Business, Membership, Permission}
     */
    private function authorizedContext(): array
    {
        $user = $this->createUser();
        $business = $this->createBusiness('Access Tenant A');

        $membership = Membership::query()->create([
            'user_id' => $user->getKey(),
            'business_id' => $business->getKey(),
            'access_status' => MembershipAccessStatus::Active,
        ]);

        $permission = Permission::query()->create([
            'key' => self::VIEW_CAPABILITY,
        ]);

        PermissionGrant::query()->create([
            'business_id' => $business->getKey(),
            'membership_id' => $membership->getKey(),
            'permission_id' => $permission->getKey(),
            'effect' => PermissionEffect::Allow,
        ]);

        return [
            $user,
            $business,
            $membership,
            $permission,
        ];
    }

    private function createUser(): User
    {
        return User::query()->create([
            'email' => 'workspace-access-'.Str::uuid7().'@example.test',
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
}
