<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Application\Identity\RevokePbrAccessCode;
use Illuminate\Console\Command;
use InvalidArgumentException;
use Throwable;

final class RevokePbrAccessCodeCommand extends Command
{
    protected $signature = 'access:code:revoke
        {id : PBR access code ID}
        {--actor= : Administrative actor label}
        {--reason= : Required administration reason}';

    protected $description = 'Revoke a pending PBR system access code.';

    public function handle(RevokePbrAccessCode $revoke): int
    {
        $actorLabel = $this->optionOrAsk(
            'actor',
            'Administrative actor label',
        );
        $reason = $this->optionOrAsk('reason', 'Reason');

        try {
            $revoked = $revoke->handle(
                codeId: (string) $this->argument('id'),
                actorLabel: $actorLabel,
                reason: $reason,
            );
        } catch (InvalidArgumentException $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        } catch (Throwable) {
            $this->error('PBR access code revocation failed.');

            return self::FAILURE;
        }

        if (! $revoked) {
            $this->error('Pending PBR access code not found.');

            return self::FAILURE;
        }

        $this->info('PBR access code revoked.');

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
