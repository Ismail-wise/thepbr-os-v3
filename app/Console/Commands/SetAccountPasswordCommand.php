<?php

namespace App\Console\Commands;

use App\Application\Identity\ChangeAccountPassword;
use Illuminate\Console\Command;
use InvalidArgumentException;
use Throwable;

final class SetAccountPasswordCommand extends Command
{
    protected $signature = 'identity:account:set-password
        {email : Account email address}
        {--actor= : Administrative actor label}
        {--reason= : Required administration reason}';

    protected $description = 'Change an account password using a hidden interactive prompt.';

    public function handle(ChangeAccountPassword $changeAccountPassword): int
    {
        $email = (string) $this->argument('email');
        $actorLabel = $this->optionOrAsk('actor', 'Administrative actor label');
        $reason = $this->optionOrAsk('reason', 'Reason');

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
            $user = $changeAccountPassword->handle(
                email: $email,
                password: $password,
                actorLabel: $actorLabel,
                reason: $reason,
                source: 'cli',
            );
        } catch (InvalidArgumentException $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        } catch (Throwable) {
            $this->error('Account password change failed.');

            return self::FAILURE;
        }

        $this->info('Password changed for account ID: '.$user->id);

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
