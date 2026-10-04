<?php

declare(strict_types=1);

namespace Tests\Feature\Identity;

use App\Application\Identity\HasBusinessCreationEntitlement;
use App\Application\Identity\IssuePbrAccessCode;
use App\Application\Identity\RedeemPbrAccessCode;
use App\Domain\Identity\Enums\AccountStatus;
use App\Domain\Identity\Enums\LanguageMode;
use App\Infrastructure\Persistence\Eloquent\Identity\User;
use App\Infrastructure\Persistence\Eloquent\Identity\UserProfile;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use Tests\TestCase;

final class PbrAccessCodeRedemptionTest extends TestCase
{
    use RefreshDatabase;

    public function test_valid_access_code_atomically_creates_active_account_and_business_creation_entitlement(): void
    {
        $issued = $this->issue('client@example.test');

        $user = $this->app
            ->make(RedeemPbrAccessCode::class)
            ->handle(
                $issued['token'],
                'CLIENT@example.test',
                null,
                'Direct Client',
                'strong-password-123',
                LanguageMode::Mixed,
                'Asia/Yangon',
            );

        self::assertSame(AccountStatus::Active, $user->status);

        $this->assertDatabaseHas('pbr_access_codes', [
            'id' => $issued['id'],
            'status' => 'redeemed',
            'redeemed_by_user_id' => $user->getKey(),
        ]);

        $this->assertDatabaseHas('account_entitlements', [
            'user_id' => $user->getKey(),
            'entitlement_key' => HasBusinessCreationEntitlement::ENTITLEMENT,
            'pbr_access_code_id' => $issued['id'],
        ]);

        self::assertTrue(
            $this->app
                ->make(HasBusinessCreationEntitlement::class)
                ->handle($user),
        );

        self::assertSame(2, DB::table('security_events')->count());
        self::assertSame(1, DB::table('users')->count());
    }

    public function test_access_code_is_one_time_and_cannot_create_duplicate_account_or_entitlement(): void
    {
        $issued = $this->issue('once@example.test');

        $user = $this->redeem(
            $issued['token'],
            'once@example.test',
        );

        $users = DB::table('users')->count();
        $entitlements = DB::table('account_entitlements')->count();

        try {
            $this->app
                ->make(RedeemPbrAccessCode::class)
                ->handle(
                    $issued['token'],
                    'once@example.test',
                    $user,
                    '',
                    '',
                    LanguageMode::English,
                    'UTC',
                );

            self::fail('Redeemed access code must not be reusable.');
        } catch (InvalidArgumentException $exception) {
            self::assertSame(
                'Access code is invalid or unavailable.',
                $exception->getMessage(),
            );
        }

        self::assertSame($users, DB::table('users')->count());
        self::assertSame(
            $entitlements,
            DB::table('account_entitlements')->count(),
        );
    }

    public function test_email_binding_mismatch_has_no_admission_side_effect(): void
    {
        $issued = $this->issue('bound@example.test');

        try {
            $this->redeem(
                $issued['token'],
                'different@example.test',
            );

            self::fail('Email-bound access code must not admit another email.');
        } catch (InvalidArgumentException $exception) {
            self::assertSame(
                'Access code is invalid or unavailable.',
                $exception->getMessage(),
            );
        }

        $this->assertDatabaseCount('users', 0);
        $this->assertDatabaseCount('account_entitlements', 0);
        $this->assertDatabaseHas('pbr_access_codes', [
            'id' => $issued['id'],
            'status' => 'pending',
        ]);
    }

    public function test_existing_active_account_must_be_authenticated_before_access_code_can_grant_entitlement(): void
    {
        $user = $this->activeUser('member@example.test');
        $issued = $this->issue('member@example.test');

        try {
            $this->app
                ->make(RedeemPbrAccessCode::class)
                ->handle(
                    $issued['token'],
                    'member@example.test',
                    null,
                    '',
                    '',
                    LanguageMode::English,
                    'UTC',
                );

            self::fail('Existing active account must authenticate first.');
        } catch (InvalidArgumentException $exception) {
            self::assertSame(
                'Sign in with this account before redeeming the access code.',
                $exception->getMessage(),
            );
        }

        $this->assertDatabaseCount('account_entitlements', 0);

        $redeemed = $this->app
            ->make(RedeemPbrAccessCode::class)
            ->handle(
                $issued['token'],
                'member@example.test',
                $user,
                '',
                '',
                LanguageMode::English,
                'UTC',
            );

        self::assertSame($user->getKey(), $redeemed->getKey());
        self::assertTrue(
            $this->app
                ->make(HasBusinessCreationEntitlement::class)
                ->handle($redeemed),
        );
    }

    public function test_account_entitlement_history_cannot_be_rewritten(): void
    {
        $issued = $this->issue('immutable@example.test');
        $user = $this->redeem(
            $issued['token'],
            'immutable@example.test',
        );

        $entitlement = DB::table('account_entitlements')
            ->where('user_id', $user->getKey())
            ->sole();

        $this->expectException(QueryException::class);

        DB::table('account_entitlements')
            ->where('id', $entitlement->id)
            ->delete();
    }

    /**
     * @return array{id:string,token:string,last4:string,bound_email:?string,expires_at:?string}
     */
    private function issue(?string $email): array
    {
        return $this->app
            ->make(IssuePbrAccessCode::class)
            ->handle(
                boundEmail: $email,
                expiresInHours: 24,
                clientReference: null,
                batchReference: null,
                notes: null,
                actorLabel: 'PBR Administrator',
                reason: 'Approved direct client',
            );
    }

    private function redeem(string $token, string $email): User
    {
        return $this->app
            ->make(RedeemPbrAccessCode::class)
            ->handle(
                $token,
                $email,
                null,
                'Direct Client',
                'strong-password-123',
                LanguageMode::English,
                'UTC',
            );
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
            'display_name' => 'Existing Member',
            'language_mode' => LanguageMode::English,
            'timezone' => 'UTC',
        ]);

        return $user;
    }
}
