<?php

namespace Tests\Feature\Identity;

use App\Application\Identity\ChangeAccountPassword;
use App\Application\Identity\ChangeAccountStatus;
use App\Application\Identity\ProvisionAccount;
use App\Domain\Identity\Enums\AccountStatus;
use App\Domain\Identity\Enums\LanguageMode;
use App\Domain\Identity\Enums\SecurityEventType;
use App\Infrastructure\Persistence\Eloquent\Identity\SecurityEvent;
use App\Infrastructure\Persistence\Eloquent\Identity\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use InvalidArgumentException;
use Tests\TestCase;

final class AccountAdministrationTest extends TestCase
{
    use RefreshDatabase;

    public function test_provisioning_uses_argon2id_and_records_security_event_without_password_data(): void
    {
        $password = 'correct horse battery staple';

        $user = $this->app->make(ProvisionAccount::class)->handle(
            email: ' Person@EXAMPLE.COM ',
            displayName: 'Person',
            password: $password,
            languageMode: LanguageMode::Mixed,
            timezone: 'Asia/Yangon',
            actorLabel: 'Controlled Administrator',
            reason: 'Initial account provisioning',
        );

        $this->assertSame('person@example.com', $user->email);
        $this->assertSame(AccountStatus::Provisioned, $user->status);
        $this->assertSame('Person', $user->profile->display_name);
        $this->assertSame(LanguageMode::Mixed, $user->profile->language_mode);
        $this->assertSame('Asia/Yangon', $user->profile->timezone);

        $this->assertStringStartsWith('$argon2id$', $user->password);
        $this->assertTrue(Hash::check($password, $user->password));

        $event = SecurityEvent::query()->sole();

        $this->assertSame(SecurityEventType::AccountProvisioned, $event->event_type);
        $this->assertSame($user->id, $event->subject_user_id);
        $this->assertSame('Controlled Administrator', $event->actor_label);
        $this->assertSame('cli', $event->source);
        $this->assertSame('Initial account provisioning', $event->reason);
        $this->assertSame([
            'initial_status' => AccountStatus::Provisioned->value,
        ], $event->metadata);

        $metadata = json_encode($event->metadata, JSON_THROW_ON_ERROR);

        $this->assertStringNotContainsString($password, $metadata);
        $this->assertStringNotContainsString($user->password, $metadata);
    }

    public function test_allowed_status_change_uses_existing_transition_rule_and_records_event(): void
    {
        $user = $this->provisionUser();

        $changed = $this->app->make(ChangeAccountStatus::class)->handle(
            email: $user->email,
            targetStatus: AccountStatus::Active,
            actorLabel: 'Controlled Administrator',
            reason: 'Activation approved',
        );

        $this->assertSame(AccountStatus::Active, $changed->status);

        $event = SecurityEvent::query()
            ->where('event_type', SecurityEventType::AccountStatusChanged)
            ->sole();

        $this->assertCount(2, $event->metadata);
        $this->assertSame('provisioned', $event->metadata['from_status']);
        $this->assertSame('active', $event->metadata['to_status']);
    }

    public function test_disallowed_status_change_is_rejected_without_security_event(): void
    {
        $user = $this->provisionUser();

        try {
            $this->app->make(ChangeAccountStatus::class)->handle(
                email: $user->email,
                targetStatus: AccountStatus::Suspended,
                actorLabel: 'Controlled Administrator',
                reason: 'Invalid direct suspension',
            );

            $this->fail('Expected disallowed status transition to be rejected.');
        } catch (InvalidArgumentException) {
            $this->assertSame(
                AccountStatus::Provisioned,
                User::query()->findOrFail($user->id)->status,
            );

            $this->assertSame(1, SecurityEvent::query()->count());
        }
    }

    public function test_password_change_uses_argon2id_and_records_no_password_metadata(): void
    {
        $user = $this->provisionUser();
        $oldHash = $user->password;
        $newPassword = 'a different secure passphrase';

        $changed = $this->app->make(ChangeAccountPassword::class)->handle(
            email: $user->email,
            password: $newPassword,
            actorLabel: 'Controlled Administrator',
            reason: 'Credential rotation',
        );

        $this->assertNotSame($oldHash, $changed->password);
        $this->assertStringStartsWith('$argon2id$', $changed->password);
        $this->assertTrue(Hash::check($newPassword, $changed->password));

        $event = SecurityEvent::query()
            ->where('event_type', SecurityEventType::AccountPasswordChanged)
            ->sole();

        $this->assertSame([], $event->metadata);

        $metadata = json_encode($event->metadata, JSON_THROW_ON_ERROR);

        $this->assertStringNotContainsString($newPassword, $metadata);
        $this->assertStringNotContainsString($changed->password, $metadata);
    }

    private function provisionUser(): User
    {
        return $this->app->make(ProvisionAccount::class)->handle(
            email: 'person@example.com',
            displayName: 'Person',
            password: 'correct horse battery staple',
            languageMode: LanguageMode::English,
            timezone: 'UTC',
            actorLabel: 'Controlled Administrator',
            reason: 'Test provisioning',
        );
    }
}
