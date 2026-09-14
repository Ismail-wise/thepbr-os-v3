<?php

namespace Tests\Feature;

use App\Presentation\Http\Middleware\EnsureActiveAccount;
use Illuminate\Foundation\Http\Events\RequestHandled;
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

final class FoundationPageTest extends TestCase
{
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

    public function test_root_requires_authentication(): void
    {
        $this->get('/')
            ->assertRedirect('/login');
    }

    public function test_health_endpoint_is_available(): void
    {
        $this->get('/up')->assertOk();
    }

    public function test_f1_a4_exposes_only_the_approved_authentication_route_boundary(): void
    {
        $this->assertTrue(Route::has('login'));
        $this->assertTrue(Route::has('login.store'));
        $this->assertTrue(Route::has('home'));
        $this->assertTrue(Route::has('logout'));

        $this->assertFalse(Route::has('account.home'));
        $this->assertFalse(Route::has('register'));
        $this->assertFalse(Route::has('password.request'));
        $this->assertFalse(Route::has('password.email'));
        $this->assertFalse(Route::has('password.reset'));
        $this->assertFalse(Route::has('password.update'));
        $this->assertFalse(Route::has('verification.notice'));
        $this->assertFalse(Route::has('verification.verify'));
        $this->assertFalse(Route::has('verification.send'));

        $logoutRoute = Route::getRoutes()->getByName('logout');

        $this->assertNotNull($logoutRoute);

        $logoutMiddleware = $logoutRoute->gatherMiddleware();

        $this->assertContains('auth', $logoutMiddleware);
        $this->assertNotContains(EnsureActiveAccount::class, $logoutMiddleware);

        foreach (Route::getRoutes() as $route) {
            $uri = $route->uri();

            $this->assertFalse(
                $uri === 'register'
                || $uri === 'forgot-password'
                || $uri === 'email/verify'
                || str_starts_with($uri, 'password/')
                || str_starts_with($uri, 'business')
                || str_starts_with($uri, 'membership'),
                "Unexpected F1-A4 route URI: {$uri}",
            );
        }
    }
}
