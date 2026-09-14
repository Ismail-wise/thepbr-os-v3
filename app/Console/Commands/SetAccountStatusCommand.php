<?php

namespace App\Console\Commands;

use App\Application\Identity\ChangeAccountStatus;
use App\Domain\Identity\Enums\AccountStatus;
use Illuminate\Console\Command;
use InvalidArgumentException;
use Throwable;

final class SetAccountStatusCommand extends Command
{
    protected $signature = 'identity:account:set-status
        {email : Account email address}
        {status : Target status: active, suspended, disabled}
        {--actor= : Administrative actor label}
        {--reason= : Required administration reason}';

    protected $description = 'Apply an allowed account-status transition.';

    public function handle(ChangeAccountStatus $changeAccountStatus): int
    {
        $email = (string) $this->argument('email');
        $targetValue = strtolower(trim((string) $this->argument('status')));
        $actorLabel = $this->optionOrAsk('actor', 'Administrative actor label');
        $reason = $this->optionOrAsk('reason', 'Reason');

        $targetStatus = AccountStatus::tryFrom($targetValue);

        if ($targetStatus === null) {
            $this->error('Unknown account status.');

            return self::FAILURE;
        }

        try {
            $user = $changeAccountStatus->handle(
                email: $email,
                targetStatus: $targetStatus,
                actorLabel: $actorLabel,
                reason: $reason,
                source: 'cli',
            );
        } catch (InvalidArgumentException $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        } catch (Throwable) {
            $this->error('Account status change failed.');

            return self::FAILURE;
        }

        $this->info(sprintf(
            'Account %s status is now %s.',
            $user->id,
            $user->status->value,
        ));

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
