<?php

namespace Tests\Feature\Identity;

use App\Domain\Identity\Enums\AccountStatus;
use App\Domain\Identity\Enums\SecurityEventType;
use App\Infrastructure\Persistence\Eloquent\Identity\SecurityEvent;
use App\Infrastructure\Persistence\Eloquent\Identity\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

final class AccountAdministrationCommandsTest extends TestCase
{
    use RefreshDatabase;

    public function test_account_commands_do_not_expose_password_argument_or_option(): void
    {
        foreach ([
            'identity:account:provision',
            'identity:account:set-status',
            'identity:account:set-password',
        ] as $name) {
            $command = Artisan::all()[$name];

            $this->assertFalse($command->getDefinition()->hasArgument('password'));
            $this->assertFalse($command->getDefinition()->hasOption('password'));
        }
    }

    public function test_provision_command_uses_hidden_password_questions(): void
    {
        $password = 'correct horse battery staple';

        $this->artisan('identity:account:provision', [
            '--email' => 'person@example.com',
            '--display-name' => 'Person',
            '--language' => 'mixed',
            '--timezone' => 'Asia/Yangon',
            '--actor' => 'CLI Administrator',
            '--reason' => 'Controlled provisioning',
        ])
            ->expectsQuestion('Password', $password)
            ->expectsQuestion('Confirm password', $password)
            ->assertSuccessful();

        $user = User::query()->where('email', 'person@example.com')->sole();

        $this->assertSame(AccountStatus::Provisioned, $user->status);
        $this->assertTrue(Hash::check($password, $user->password));

        $event = SecurityEvent::query()->sole();

        $this->assertSame(SecurityEventType::AccountProvisioned, $event->event_type);
    }

    public function test_status_and_password_commands_use_controlled_application_use_cases(): void
    {
        $password = 'correct horse battery staple';

        $this->artisan('identity:account:provision', [
            '--email' => 'person@example.com',
            '--display-name' => 'Person',
            '--language' => 'en',
            '--timezone' => 'UTC',
            '--actor' => 'CLI Administrator',
            '--reason' => 'Controlled provisioning',
        ])
            ->expectsQuestion('Password', $password)
            ->expectsQuestion('Confirm password', $password)
            ->assertSuccessful();

        $this->artisan('identity:account:set-status', [
            'email' => 'person@example.com',
            'status' => 'active',
            '--actor' => 'CLI Administrator',
            '--reason' => 'Activation approved',
        ])->assertSuccessful();

        $newPassword = 'a different secure passphrase';

        $this->artisan('identity:account:set-password', [
            'email' => 'person@example.com',
            '--actor' => 'CLI Administrator',
            '--reason' => 'Credential rotation',
        ])
            ->expectsQuestion('Password', $newPassword)
            ->expectsQuestion('Confirm password', $newPassword)
            ->assertSuccessful();

        $user = User::query()->where('email', 'person@example.com')->sole();

        $this->assertSame(AccountStatus::Active, $user->status);
        $this->assertTrue(Hash::check($newPassword, $user->password));
        $this->assertSame(3, SecurityEvent::query()->count());
    }
}
