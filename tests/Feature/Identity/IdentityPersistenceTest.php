<?php

namespace Tests\Feature\Identity;

use App\Domain\Identity\Enums\AccountStatus;
use App\Domain\Identity\Enums\LanguageMode;
use App\Infrastructure\Persistence\Eloquent\Identity\User;
use App\Infrastructure\Persistence\Eloquent\Identity\UserProfile;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Tests\TestCase;

final class IdentityPersistenceTest extends TestCase
{
    use RefreshDatabase;

    public function test_user_uses_uuid_v7_and_canonical_email(): void
    {
        $user = User::query()->create([
            'email' => '  Person@EXAMPLE.COM  ',
            'password' => 'not-a-real-hash',
            'status' => AccountStatus::Provisioned,
            'password_changed_at' => now(),
        ])->refresh();

        $this->assertTrue(Str::isUuid((string) $user->id, 7));
        $this->assertSame('person@example.com', $user->email);
        $this->assertSame(AccountStatus::Provisioned, $user->status);
        $this->assertNotNull($user->password_changed_at);
    }

    public function test_profile_is_bound_one_to_one_to_user_identity(): void
    {
        $user = User::query()->create([
            'email' => 'person@example.com',
            'password' => 'not-a-real-hash',
            'status' => AccountStatus::Provisioned,
            'password_changed_at' => now(),
        ]);

        $profile = UserProfile::query()->create([
            'user_id' => $user->id,
            'display_name' => 'Person',
            'language_mode' => LanguageMode::Mixed,
            'timezone' => 'Asia/Yangon',
        ])->refresh();

        $this->assertSame($user->id, $profile->user_id);
        $this->assertSame(LanguageMode::Mixed, $profile->language_mode);
        $this->assertSame('Asia/Yangon', $profile->timezone);
        $this->assertSame($profile->user_id, $user->fresh()->profile->user_id);
        $this->assertSame($user->id, $profile->user->id);
    }

    public function test_profile_rejects_invalid_timezone_identifier(): void
    {
        $user = User::query()->create([
            'email' => 'person@example.com',
            'password' => 'not-a-real-hash',
            'status' => AccountStatus::Provisioned,
            'password_changed_at' => now(),
        ]);

        $this->expectException(InvalidArgumentException::class);

        UserProfile::query()->create([
            'user_id' => $user->id,
            'display_name' => 'Person',
            'language_mode' => LanguageMode::English,
            'timezone' => 'Not/A_Timezone',
        ]);
    }
}
