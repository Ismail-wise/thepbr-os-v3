<?php

declare(strict_types=1);

namespace Tests\Feature\Formation;

use App\Application\Businesses\CreateBusiness;
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
use Illuminate\Foundation\Http\Events\RequestHandled;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Redis;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

final class F4FormationPageTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @var list<string>
     */
    private array $capturedSessionIds = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->app['events']->listen(
            RequestHandled::class,
            function (RequestHandled $event): void {
                if (! $event->request->hasSession()) {
                    return;
                }

                $sessionId = $event->request->session()->getId();

                if ($sessionId !== '') {
                    $this->capturedSessionIds[] = $sessionId;
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

                foreach (array_unique($this->capturedSessionIds) as $sessionId) {
                    $redis->del($prefix.$sessionId);
                }
            }
        } finally {
            parent::tearDown();
        }
    }

    public function test_workspace_owner_can_open_new_business_formation_workspace(): void
    {
        $this->withoutVite();

        $user = $this->activeUser('f4-page@example.test');

        $business = $this->app->make(CreateBusiness::class)->handle(
            $user,
            'F4 Formation Page',
            BusinessOriginType::StartedThroughPbr,
            BusinessStage::Planning,
            'USD',
        );

        $this->actingAs($user)
            ->withSession([
                EnsureCurrentBusinessContext::SESSION_KEY => (string) $business->getKey(),
            ])
            ->get('/formation')
            ->assertOk()
            ->assertInertia(
                fn (Assert $page): Assert => $page
                    ->component('Formation/Index')
                    ->where('formation.journey', 'new')
                    ->where(
                        'formation.business.id',
                        (string) $business->getKey(),
                    )
                    ->where(
                        'formation.permissions.can_manage_capital',
                        true,
                    )
                    ->has('formation.capital.scenarios', 0),
            );
    }

    public function test_formation_workspace_fails_closed_without_system_capability(): void
    {
        $this->withoutVite();

        $user = $this->activeUser('f4-no-access@example.test');

        $business = Business::query()->create([
            'name' => 'No Formation Access',
            'origin_type' => BusinessOriginType::StartedThroughPbr,
            'business_stage' => BusinessStage::Planning,
            'setup_phase' => SetupPhase::Formation,
            'workspace_status' => WorkspaceStatus::Active,
            'base_currency' => 'USD',
        ]);

        Membership::query()->create([
            'user_id' => $user->getKey(),
            'business_id' => $business->getKey(),
            'access_status' => MembershipAccessStatus::Active,
        ]);

        $this->actingAs($user)
            ->withSession([
                EnsureCurrentBusinessContext::SESSION_KEY => (string) $business->getKey(),
            ])
            ->get('/formation')
            ->assertNotFound();
    }

    public function test_formation_route_is_current_business_scoped(): void
    {
        $route = app('router')
            ->getRoutes()
            ->getByName('formation.index');

        self::assertNotNull($route);
        self::assertSame('formation', $route->uri());

        self::assertContains(
            EnsureCurrentBusinessContext::class,
            $route->gatherMiddleware(),
        );
    }

    private function activeUser(string $email): User
    {
        return User::query()->create([
            'email' => $email,
            'password' => 'not-a-real-hash',
            'status' => AccountStatus::Active,
            'password_changed_at' => now(),
        ]);
    }
}
