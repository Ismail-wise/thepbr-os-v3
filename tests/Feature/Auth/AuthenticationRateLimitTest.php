<?php

namespace Tests\Feature\Auth;

use Illuminate\Foundation\Http\Events\RequestHandled;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Redis;
use Tests\TestCase;

final class AuthenticationRateLimitTest extends TestCase
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

    public function test_login_rate_limit_uses_the_normalized_email_boundary(): void
    {
        $this->withServerVariables([
            'REMOTE_ADDR' => '203.0.113.10',
        ]);

        foreach ([
            '  RATE-LIMIT@EXAMPLE.TEST  ',
            'rate-limit@example.test',
            'Rate-Limit@Example.Test',
            ' rate-limit@example.test',
            'rate-limit@example.test ',
        ] as $email) {
            $this->post('/login', [
                'email' => $email,
                'password' => 'wrong-password',
            ])
                ->assertRedirect()
                ->assertSessionHasErrors('email');
        }

        $this->post('/login', [
            'email' => 'rate-limit@example.test',
            'password' => 'wrong-password',
        ])->assertStatus(429);
    }
}
