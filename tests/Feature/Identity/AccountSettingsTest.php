<?php

namespace Tests\Feature\Identity;

use App\Domain\Identity\Enums\AccountStatus;
use App\Domain\Identity\Enums\LanguageMode;
use App\Infrastructure\Persistence\Eloquent\Identity\SecurityEvent;
use App\Infrastructure\Persistence\Eloquent\Identity\User;
use App\Infrastructure\Persistence\Eloquent\Identity\UserProfile;
use Illuminate\Foundation\Http\Events\RequestHandled;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Redis;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

final class AccountSettingsTest extends TestCase
{
    use RefreshDatabase;

    private const PASSWORD = 'correct horse battery staple';

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

    public function test_guest_cannot_view_account_settings(): void
    {
        $this->get('/account/settings')
            ->assertRedirect('/login');
    }

    public function test_guest_cannot_update_account_settings(): void
    {
        $this->patch('/account/settings', [
            'display_name' => 'Guest',
            'language_mode' => 'en',
            'timezone' => 'UTC',
        ])->assertRedirect('/login');
    }

    public function test_active_authenticated_user_sees_own_account_profile_values(): void
    {
        $user = $this->createUser(
            email: 'person@example.test',
            displayName: 'Person',
            languageMode: LanguageMode::Mixed,
            timezone: 'Asia/Yangon',
        );

        $this->actingAs($user);
        $this->withoutVite();

        $this->get('/account/settings')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Account/Settings')
                ->where('account.email', 'person@example.test')
                ->where('account.profile.display_name', 'Person')
                ->where('account.profile.language_mode', 'mixed')
                ->where('account.profile.timezone', 'Asia/Yangon'));
    }

    public function test_account_settings_page_exposes_empty_workspace_context_without_membership(): void
    {
        $user = $this->createUser('person@example.test');

        $this->actingAs($user);
        $this->withoutVite();

        $this->get('/account/settings')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Account/Settings')
                ->missing('business')
                ->missing('membership')
                ->where('workspace.businesses', [])
                ->where('workspace.currentBusiness', null));
    }

    public function test_active_user_can_update_own_profile_preferences(): void
    {
        $user = $this->createUser('person@example.test');

        $this->actingAs($user);

        $this->patch('/account/settings', [
            'display_name' => '  Updated Person  ',
            'language_mode' => 'mixed',
            'timezone' => 'Asia/Yangon',
        ])->assertRedirect('/account/settings');

        $profile = UserProfile::query()->whereKey($user->id)->sole();

        $this->assertSame('Updated Person', $profile->display_name);
        $this->assertSame(LanguageMode::Mixed, $profile->language_mode);
        $this->assertSame('Asia/Yangon', $profile->timezone);
    }

    public function test_submitted_target_identifiers_cannot_modify_another_users_profile(): void
    {
        $user = $this->createUser(
            email: 'first@example.test',
            displayName: 'First User',
        );

        $other = $this->createUser(
            email: 'second@example.test',
            displayName: 'Second User',
            languageMode: LanguageMode::Mixed,
            timezone: 'Asia/Yangon',
        );

        $this->actingAs($user);

        $this->patch('/account/settings', [
            'user_id' => $other->id,
            'target_user_id' => $other->id,
            'display_name' => 'Updated First',
            'language_mode' => 'my',
            'timezone' => 'Asia/Bangkok',
        ])->assertRedirect('/account/settings');

        $ownProfile = UserProfile::query()->whereKey($user->id)->sole();
        $otherProfile = UserProfile::query()->whereKey($other->id)->sole();

        $this->assertSame('Updated First', $ownProfile->display_name);
        $this->assertSame(LanguageMode::Myanmar, $ownProfile->language_mode);
        $this->assertSame('Asia/Bangkok', $ownProfile->timezone);

        $this->assertSame('Second User', $otherProfile->display_name);
        $this->assertSame(LanguageMode::Mixed, $otherProfile->language_mode);
        $this->assertSame('Asia/Yangon', $otherProfile->timezone);
    }

    public function test_invalid_language_mode_is_rejected(): void
    {
        $user = $this->createUser('person@example.test');

        $this->actingAs($user);

        $this->patch('/account/settings', [
            'display_name' => 'Person',
            'language_mode' => 'invalid',
            'timezone' => 'UTC',
        ])->assertSessionHasErrors('language_mode');

        $profile = UserProfile::query()->whereKey($user->id)->sole();

        $this->assertSame(LanguageMode::English, $profile->language_mode);
    }

    public function test_invalid_timezone_is_rejected(): void
    {
        $user = $this->createUser('person@example.test');

        $this->actingAs($user);

        $this->patch('/account/settings', [
            'display_name' => 'Person',
            'language_mode' => 'en',
            'timezone' => 'Not/A_Timezone',
        ])->assertSessionHasErrors('timezone');

        $profile = UserProfile::query()->whereKey($user->id)->sole();

        $this->assertSame('UTC', $profile->timezone);
    }

    public function test_blank_and_oversized_display_names_are_rejected(): void
    {
        $user = $this->createUser('person@example.test');

        $this->actingAs($user);

        $this->patch('/account/settings', [
            'display_name' => '   ',
            'language_mode' => 'en',
            'timezone' => 'UTC',
        ])->assertSessionHasErrors('display_name');

        $this->patch('/account/settings', [
            'display_name' => str_repeat('x', 121),
            'language_mode' => 'en',
            'timezone' => 'UTC',
        ])->assertSessionHasErrors('display_name');

        $profile = UserProfile::query()->whereKey($user->id)->sole();

        $this->assertSame('Person', $profile->display_name);
    }

    public function test_stale_non_active_authenticated_account_cannot_use_settings(): void
    {
        $user = $this->createUser('person@example.test');

        $this->actingAs($user);

        User::query()
            ->whereKey($user->id)
            ->update([
                'status' => AccountStatus::Suspended->value,
            ]);

        $this->get('/account/settings')
            ->assertRedirect('/login');

        $this->assertGuest();
    }

    public function test_profile_update_does_not_change_email_status_or_password(): void
    {
        $user = $this->createUser('person@example.test');

        $originalEmail = $user->email;
        $originalStatus = $user->status;
        $originalPassword = $user->password;

        $this->actingAs($user);

        $this->patch('/account/settings', [
            'display_name' => 'Updated Person',
            'language_mode' => 'mixed',
            'timezone' => 'Asia/Yangon',
            'email' => 'attacker@example.test',
            'status' => 'disabled',
            'password' => 'replacement-password',
        ])->assertRedirect('/account/settings');

        $updated = User::query()->findOrFail($user->id);

        $this->assertSame($originalEmail, $updated->email);
        $this->assertSame($originalStatus, $updated->status);
        $this->assertSame($originalPassword, $updated->password);
    }

    public function test_profile_update_does_not_create_security_event(): void
    {
        $user = $this->createUser('person@example.test');

        $this->actingAs($user);

        $this->patch('/account/settings', [
            'display_name' => 'Updated Person',
            'language_mode' => 'mixed',
            'timezone' => 'Asia/Yangon',
        ])->assertRedirect('/account/settings');

        $this->assertSame(0, SecurityEvent::query()->count());
    }

    private function createUser(
        string $email,
        string $displayName = 'Person',
        ?LanguageMode $languageMode = null,
        string $timezone = 'UTC',
        AccountStatus $status = AccountStatus::Active,
    ): User {
        $languageMode ??= LanguageMode::English;

        $user = User::query()->create([
            'email' => $email,
            'password' => Hash::make(self::PASSWORD),
            'status' => $status,
            'password_changed_at' => now(),
        ]);

        UserProfile::query()->create([
            'user_id' => $user->id,
            'display_name' => $displayName,
            'language_mode' => $languageMode,
            'timezone' => $timezone,
        ]);

        return $user->refresh()->load('profile');
    }
}
