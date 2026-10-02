<?php

declare(strict_types=1);

namespace Tests\Feature\Account;

use App\Application\Account\GetAccountGovernanceInbox;
use App\Application\Account\GetAccountWork;
use App\Application\Businesses\CreateBusiness;
use App\Domain\Businesses\Enums\BusinessOriginType;
use App\Domain\Businesses\Enums\BusinessStage;
use App\Domain\Identity\Enums\AccountStatus;
use App\Domain\Identity\Enums\LanguageMode;
use App\Domain\Members\Enums\MembershipAccessStatus;
use App\Http\Middleware\EnsureCurrentBusinessContext;
use App\Infrastructure\Persistence\Eloquent\Identity\User;
use App\Infrastructure\Persistence\Eloquent\Identity\UserProfile;
use App\Infrastructure\Persistence\Eloquent\Members\Membership;
use App\Presentation\Http\Middleware\EnsureActiveAccount;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

final class AccountExperienceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutVite();
    }

    public function test_account_experience_routes_are_account_level_and_do_not_require_current_business(): void
    {
        $user = $this->activeUser('account-level@example.test');

        $routes = [
            ['/', 'home', 'AccountHome'],
            ['/account/businesses', 'account.businesses', 'Account/Businesses'],
            ['/account/work', 'account.work', 'Account/Work'],
            ['/account/notifications', 'account.notifications', 'Account/Notifications'],
            ['/account/approvals', 'account.approvals', 'Account/Approvals'],
            ['/account/signatures', 'account.signatures', 'Account/Signatures'],
        ];

        foreach ($routes as [$uri, $name, $component]) {
            $route = app('router')->getRoutes()->getByName($name);

            $this->assertNotNull($route);
            $this->assertContains(EnsureActiveAccount::class, $route->gatherMiddleware());
            $this->assertNotContains(
                EnsureCurrentBusinessContext::class,
                $route->gatherMiddleware(),
            );

            $this
                ->actingAs($user)
                ->get($uri)
                ->assertOk()
                ->assertInertia(
                    fn (Assert $page): Assert => $page->component($component),
                );
        }
    }

    public function test_home_and_my_businesses_include_only_active_membership_businesses(): void
    {
        $user = $this->activeUser('account-visible@example.test');
        $other = $this->activeUser('account-hidden@example.test');

        $visible = $this->createBusiness($user, 'Visible Account Business');
        $revoked = $this->createBusiness($user, 'Revoked Account Business');
        $hidden = $this->createBusiness($other, 'Foreign Secret Business');

        Membership::query()
            ->where('user_id', $user->getKey())
            ->where('business_id', $revoked->getKey())
            ->update([
                'access_status' => MembershipAccessStatus::Revoked->value,
            ]);

        $this
            ->actingAs($user)
            ->get('/')
            ->assertOk()
            ->assertInertia(
                fn (Assert $page): Assert => $page
                    ->component('AccountHome')
                    ->has('businesses', 1)
                    ->where('businesses.0.name', 'Visible Account Business'),
            )
            ->assertDontSee('Revoked Account Business')
            ->assertDontSee('Foreign Secret Business');

        $this
            ->actingAs($user)
            ->get('/account/businesses')
            ->assertOk()
            ->assertInertia(
                fn (Assert $page): Assert => $page
                    ->component('Account/Businesses')
                    ->has('businesses', 1)
                    ->where('businesses.0.name', 'Visible Account Business'),
            )
            ->assertDontSee('Revoked Account Business')
            ->assertDontSee('Foreign Secret Business');

        $this->assertNotSame($visible->getKey(), $hidden->getKey());
    }

    public function test_cross_business_account_composers_do_not_iterate_unauthorized_businesses(): void
    {
        $user = $this->activeUser('account-composer@example.test');
        $other = $this->activeUser('account-composer-hidden@example.test');

        $this->createBusiness($user, 'Authorized Business');
        $this->createBusiness($other, 'Unauthorized Business');

        $inbox = $this->app->make(GetAccountGovernanceInbox::class)->execute($user);
        $work = $this->app->make(GetAccountWork::class)->execute($user);

        $encoded = json_encode([
            'inbox' => $inbox,
            'work' => $work,
        ], JSON_THROW_ON_ERROR);

        $this->assertStringNotContainsString('Unauthorized Business', $encoded);
        $this->assertSame([], $inbox['approvals']);
        $this->assertSame([], $inbox['signatures']);
        $this->assertSame([], $inbox['notifications']);
        $this->assertSame([], $work);
    }

    public function test_account_home_payload_does_not_expose_raw_governance_identifiers_in_zero_state(): void
    {
        $user = $this->activeUser('account-safe-payload@example.test');

        $this->createBusiness($user, 'Safe Account Business');

        $response = $this
            ->actingAs($user)
            ->get('/')
            ->assertOk();

        $body = $response->getContent();

        foreach ([
            'authority_snapshot',
            'formal_record_version',
            'proposalVersionId',
            'documentHash',
            'membershipId',
        ] as $forbidden) {
            $this->assertStringNotContainsString($forbidden, $body);
        }
    }

    private function activeUser(string $email): User
    {
        $user = User::query()->create([
            'email' => $email,
            'password' => 'not-a-real-hash',
            'status' => AccountStatus::Active,
            'password_changed_at' => now(),
        ]);

        UserProfile::query()->create([
            'user_id' => $user->getKey(),
            'display_name' => 'Account Experience User',
            'language_mode' => LanguageMode::English->value,
            'timezone' => 'UTC',
        ]);

        return $user;
    }

    private function createBusiness(
        User $user,
        string $name,
    ) {
        return $this->app->make(CreateBusiness::class)->handle(
            $user,
            $name,
            BusinessOriginType::StartedThroughPbr,
            BusinessStage::Operating,
            'USD',
        );
    }
}
