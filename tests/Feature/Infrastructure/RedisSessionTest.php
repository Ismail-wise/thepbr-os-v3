<?php

namespace Tests\Feature\Infrastructure;

use Illuminate\Session\SessionManager;
use Illuminate\Support\Facades\Redis;
use Tests\TestCase;

final class RedisSessionTest extends TestCase
{
    public function test_sessions_use_redis_db0_with_prefix_ttl_encryption_and_db1_isolation(): void
    {
        $this->assertSame('redis', config('session.driver'));
        $this->assertSame('redis', config('session.store'));
        $this->assertSame('default', config('session.connection'));
        $this->assertSame('sessions:', config('session.prefix'));
        $this->assertTrue(config('session.encrypt'));
        $this->assertTrue(config('session.http_only'));
        $this->assertSame('lax', config('session.same_site'));
        $this->assertFalse(config('session.secure'));

        $sessionId = bin2hex(random_bytes(20));
        $sentinel = 'f1-a3-session-'.bin2hex(random_bytes(16));
        $sessionKey = config('session.prefix').$sessionId;

        $defaultRedis = Redis::connection('default');
        $cacheRedis = Redis::connection('cache');

        $defaultRedis->del($sessionKey);
        $cacheRedis->del($sessionKey);

        try {
            $writer = (new SessionManager($this->app))->driver();
            $writer->setId($sessionId);

            $this->assertTrue($writer->start());

            $writer->put('f1_a3_sentinel', $sentinel);
            $writer->save();

            $this->assertSame(
                1,
                (int) $defaultRedis->exists($sessionKey),
                'Redis session key must exist in DB0/default.',
            );

            $this->assertSame(
                0,
                (int) $cacheRedis->exists($sessionKey),
                'Redis session key must not exist in DB1/cache.',
            );

            $ttl = (int) $defaultRedis->ttl($sessionKey);
            $expectedMaximumTtl = ((int) config('session.lifetime')) * 60;

            $this->assertGreaterThan(
                0,
                $ttl,
                'Redis session key must have a positive TTL.',
            );

            $this->assertLessThanOrEqual(
                $expectedMaximumTtl,
                $ttl,
                'Redis session TTL must not exceed the configured session lifetime.',
            );

            $storedPayload = (string) $defaultRedis->get($sessionKey);

            $this->assertNotSame('', $storedPayload);

            $this->assertStringNotContainsString(
                $sentinel,
                $storedPayload,
                'Encrypted Redis session payload must not expose sentinel plaintext.',
            );

            $reader = (new SessionManager($this->app))->driver();
            $reader->setId($sessionId);

            $this->assertTrue($reader->start());

            $this->assertSame(
                $sentinel,
                $reader->get('f1_a3_sentinel'),
                'A second session store must read the value persisted in Redis.',
            );

            $this->assertTrue($reader->invalidate());

            $this->assertSame(
                0,
                (int) $defaultRedis->exists($sessionKey),
                'Invalidating the session must remove the exact old Redis session key.',
            );
        } finally {
            $defaultRedis->del($sessionKey);
            $cacheRedis->del($sessionKey);
        }
    }
}
