<?php

namespace App\Console\Commands;

use App\Application\Identity\ProvisionAccount;
use App\Domain\Identity\Enums\LanguageMode;
use Illuminate\Console\Command;
use InvalidArgumentException;
use Throwable;

final class ProvisionAccountCommand extends Command
{
    protected $signature = 'identity:account:provision
        {--email= : Account email address}
        {--display-name= : Display name}
        {--language=en : Language mode: en, my, mixed}
        {--timezone=UTC : IANA timezone identifier}
        {--actor= : Administrative actor label}
        {--reason= : Required administration reason}';

    protected $description = 'Provision a controlled thePBR OS account.';

    public function handle(ProvisionAccount $provisionAccount): int
    {
        $email = $this->optionOrAsk('email', 'Email');
        $displayName = $this->optionOrAsk('display-name', 'Display name');
        $languageValue = (string) $this->option('language');
        $timezone = (string) $this->option('timezone');
        $actorLabel = $this->optionOrAsk('actor', 'Administrative actor label');
        $reason = $this->optionOrAsk('reason', 'Reason');

        $languageMode = LanguageMode::tryFrom($languageValue);

        if ($languageMode === null) {
            $this->error('Language must be one of: en, my, mixed.');

            return self::FAILURE;
        }

        $password = $this->secret('Password');
        $confirmation = $this->secret('Confirm password');

        if (! is_string($password) || ! is_string($confirmation)) {
            $this->error('Password input is required.');

            return self::FAILURE;
        }

        if (! hash_equals($password, $confirmation)) {
            $this->error('Password confirmation does not match.');

            return self::FAILURE;
        }

        try {
            $user = $provisionAccount->handle(
                email: $email,
                displayName: $displayName,
                password: $password,
                languageMode: $languageMode,
                timezone: $timezone,
                actorLabel: $actorLabel,
                reason: $reason,
                source: 'cli',
            );
        } catch (InvalidArgumentException $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        } catch (Throwable) {
            $this->error('Account provisioning failed.');

            return self::FAILURE;
        }

        $this->info('Account provisioned with ID: '.$user->id);

        return self::SUCCESS;
    }

    private function optionOrAsk(string $option, string $question): string
    {
        $value = $this->option($option);

        if (is_string($value) && trim($value) !== '') {
            return $value;
        }

        $answer = $this->ask($question);

        return is_string($answer) ? $answer : '';
    }
}
