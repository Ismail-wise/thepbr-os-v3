<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Application\Identity\IssuePbrAccessCode;
use Illuminate\Console\Command;
use InvalidArgumentException;
use Throwable;

final class IssuePbrAccessCodeCommand extends Command
{
    protected $signature = 'access:code:issue
        {--email= : Optional email binding}
        {--expires-in-hours=168 : Expiry in hours}
        {--no-expiry : Explicitly issue without expiry}
        {--client= : Optional client reference}
        {--batch= : Optional batch reference}
        {--notes= : Optional administrative notes}
        {--actor= : Administrative actor label}
        {--reason= : Required administration reason}';

    protected $description = 'Issue a one-time PBR system access code.';

    public function handle(IssuePbrAccessCode $issue): int
    {
        $actorLabel = $this->optionOrAsk(
            'actor',
            'Administrative actor label',
        );
        $reason = $this->optionOrAsk('reason', 'Reason');

        $expiry = null;

        if (! (bool) $this->option('no-expiry')) {
            $rawExpiry = $this->option('expires-in-hours');

            if (
                ! is_string($rawExpiry)
                || preg_match('/\A[1-9][0-9]*\z/', $rawExpiry) !== 1
            ) {
                $this->error(
                    'Expiry must be a positive whole number of hours.',
                );

                return self::FAILURE;
            }

            $expiry = (int) $rawExpiry;
        }

        try {
            $result = $issue->handle(
                boundEmail: $this->stringOption('email'),
                expiresInHours: $expiry,
                clientReference: $this->stringOption('client'),
                batchReference: $this->stringOption('batch'),
                notes: $this->stringOption('notes'),
                actorLabel: $actorLabel,
                reason: $reason,
            );
        } catch (InvalidArgumentException $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        } catch (Throwable) {
            $this->error('PBR access code issuance failed.');

            return self::FAILURE;
        }

        $this->warn(
            'Copy this access code now. The raw value is not stored and cannot be recovered later.',
        );
        $this->line('Access code: '.$result['token']);
        $this->line('Code ID: '.$result['id']);
        $this->line('Last 4: '.$result['last4']);

        if ($result['expires_at'] !== null) {
            $this->line('Expires: '.$result['expires_at']);
        } else {
            $this->line('Expires: never');
        }

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

    private function stringOption(string $name): ?string
    {
        $value = $this->option($name);

        return is_string($value) ? $value : null;
    }
}
