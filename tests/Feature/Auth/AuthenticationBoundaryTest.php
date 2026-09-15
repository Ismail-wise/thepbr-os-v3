<?php

namespace Tests\Feature\Auth;

use App\Domain\Identity\Enums\AccountStatus;
use App\Infrastructure\Persistence\Eloquent\Identity\User;
use Illuminate\Foundation\Http\Events\RequestHandled;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Redis;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

final class AuthenticationBoundaryTest extends TestCase
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

    private const PASSWORD = 'correct horse battery staple';

    private const GENERIC_ERROR = 'The provided credentials are incorrect.';

    public function test_login_page_renders_without_business_or_membership_context(): void
    {
        $this->withoutVite();

        $this->get('/login')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Auth/Login')
                ->missing('business')
                ->missing('membership'));
    }

    public function test_active_account_can_authenticate_and_login_regenerates_the_session_id(): void
    {
        $user = $this->createUser('active@example.test');

        $this->get('/login');

        $beforeSessionId = session()->getId();

        $this->post('/login', [
            'email' => 'active@example.test',
            'password' => self::PASSWORD,
        ])->assertRedirect('/');

        $this->assertAuthenticatedAs($user);
        $this->assertNotSame($beforeSessionId, session()->getId());
    }

    public function test_login_reuses_canonical_email_behavior(): void
    {
        $user = $this->createUser('canonical@example.test');

        $this->post('/login', [
            'email' => '  CANONICAL@EXAMPLE.TEST  ',
            'password' => self::PASSWORD,
        ])->assertRedirect('/');

        $this->assertAuthenticatedAs($user);
    }

    public function test_wrong_password_and_unknown_account_use_the_same_generic_error(): void
    {
        $this->createUser('known@example.test');

        $this->post('/login', [
            'email' => 'known@example.test',
            'password' => 'wrong-password',
        ])
            ->assertSessionHasErrors([
                'email' => self::GENERIC_ERROR,
            ]);

        $this->assertGuest();

        $this->post('/login', [
            'email' => 'unknown@example.test',
            'password' => 'wrong-password',
        ])
            ->assertSessionHasErrors([
                'email' => self::GENERIC_ERROR,
            ]);

        $this->assertGuest();
    }

    public function test_non_active_account_statuses_are_rejected_with_the_same_generic_error(): void
    {
        foreach ([
            AccountStatus::Provisioned,
            AccountStatus::Suspended,
            AccountStatus::Disabled,
        ] as $status) {
            $email = $status->value.'@example.test';

            $this->createUser($email, $status);

            $this->post('/login', [
                'email' => $email,
                'password' => self::PASSWORD,
            ])
                ->assertSessionHasErrors([
                    'email' => self::GENERIC_ERROR,
                ]);

            $this->assertGuest();
        }
    }

    public function test_account_home_exposes_identity_with_empty_workspace_context_without_membership(): void
    {
        $user = $this->createUser('home@example.test');

        $this->actingAs($user);

        $this->withoutVite();

        $this->get('/')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('AccountHome')
                ->where('account.email', 'home@example.test')
                ->missing('business')
                ->missing('membership')
                ->where('workspace.businesses', [])
                ->where('workspace.currentBusiness', null));
    }

    public function test_stale_authenticated_session_is_terminated_when_account_is_no_longer_active(): void
    {
        $user = $this->createUser('stale@example.test');

        $this->actingAs($user);

        User::query()
            ->whereKey($user->getKey())
            ->update([
                'status' => AccountStatus::Suspended->value,
            ]);

        $this->get('/')
            ->assertRedirect('/login');

        $this->assertGuest();
    }

    public function test_logout_is_post_only_and_invalidates_session_state_and_regenerates_csrf_token(): void
    {
        $user = $this->createUser('logout@example.test');

        $this->actingAs($user);
        $this->withSession([
            'marker' => 'must-be-removed',
            '_token' => 'known-csrf-token',
        ]);

        $this->get('/logout')
            ->assertStatus(405);

        $this->post('/logout')
            ->assertRedirect('/login');

        $this->assertGuest();
        $this->assertFalse(session()->has('marker'));
        $this->assertNotSame('known-csrf-token', session()->token());
    }

    private function createUser(
        string $email,
        AccountStatus $status = AccountStatus::Active,
    ): User {
        return User::query()->create([
            'email' => $email,
            'password' => Hash::make(self::PASSWORD),
            'status' => $status,
            'password_changed_at' => now(),
        ]);
    }
}
