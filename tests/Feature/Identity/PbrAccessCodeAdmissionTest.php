<?php

declare(strict_types=1);

namespace Tests\Feature\Identity;

use App\Application\Identity\HasBusinessCreationEntitlement;
use App\Application\Identity\IssuePbrAccessCode;
use App\Domain\Identity\Enums\AccountStatus;
use App\Infrastructure\Persistence\Eloquent\Identity\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

final class PbrAccessCodeAdmissionTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutVite();
    }

    public function test_direct_client_admission_uses_explicit_access_code_routes_not_open_registration(): void
    {
        self::assertFalse(Route::has('register'));
        self::assertTrue(Route::has('access.codes.redeem.show'));
        self::assertTrue(Route::has('access.codes.redeem.store'));

        $this
            ->get(route('access.codes.redeem.show'))
            ->assertOk()
            ->assertInertia(
                fn (Assert $page): Assert => $page
                    ->component('Auth/RedeemAccessCode')
                    ->where('authenticated', false)
                    ->where('email', ''),
            );
    }

    public function test_valid_access_code_web_flow_creates_active_account_and_redirects_to_business_creation(): void
    {
        $issued = $this->app
            ->make(IssuePbrAccessCode::class)
            ->handle(
                boundEmail: 'web-client@example.test',
                expiresInHours: 24,
                clientReference: 'WEB-CLIENT',
                batchReference: null,
                notes: null,
                actorLabel: 'PBR Administrator',
                reason: 'Approved web onboarding',
            );

        $response = $this->post(
            route('access.codes.redeem.store'),
            [
                'token' => $issued['token'],
                'email' => 'web-client@example.test',
                'display_name' => 'Web Client',
                'password' => 'strong-password-123',
                'password_confirmation' => 'strong-password-123',
                'language_mode' => 'mixed',
                'timezone' => 'Asia/Yangon',
            ],
        );

        $user = User::query()
            ->where('email', 'web-client@example.test')
            ->sole();

        self::assertSame(AccountStatus::Active, $user->status);
        self::assertTrue(
            $this->app
                ->make(HasBusinessCreationEntitlement::class)
                ->handle($user),
        );

        $response
            ->assertRedirect(route('businesses.create'))
            ->assertSessionHas('success', 'PBR access activated.');

        $this->assertAuthenticatedAs($user);
    }

    public function test_account_without_direct_client_entitlement_does_not_receive_create_business_action(): void
    {
        $user = User::query()->create([
            'email' => 'member-only@example.test',
            'password' => 'not-a-real-hash',
            'status' => AccountStatus::Active,
            'password_changed_at' => now(),
        ]);

        $this
            ->actingAs($user)
            ->get('/')
            ->assertOk()
            ->assertInertia(
                fn (Assert $page): Assert => $page
                    ->component('AccountHome')
                    ->where('canCreateBusiness', false),
            );

        $this
            ->actingAs($user)
            ->get('/account/businesses')
            ->assertOk()
            ->assertInertia(
                fn (Assert $page): Assert => $page
                    ->component('Account/Businesses')
                    ->where('canCreateBusiness', false),
            );
    }
}
