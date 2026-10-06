<?php

declare(strict_types=1);

namespace App\Domain\Capital;

use DateTimeImmutable;
use InvalidArgumentException;

final class CapitalActionPlanContract
{
    public const string CONTRACT_VERSION = 'capital-action-plan-v1';

    public const string READ_MODEL_VERSION = 'capital-action-plan-read-model-v1';

    public const string SUGGESTION_REVIEW = 'review_approved_decision';

    public const string SUGGESTION_REDUCE_SCOPE = 'prepare_scope_reduction';

    public const string SUGGESTION_DELAY = 'plan_delayed_items';

    public const string SUGGESTION_BORROW = 'prepare_borrowing_review';

    public const string SUGGESTION_CAPITAL_CALL = 'prepare_capital_call_process';

    public const string SUGGESTION_SIGNATURE = 'complete_required_signature';

    public static function suggestionKeys(): array
    {
        return [
            self::SUGGESTION_REVIEW,
            self::SUGGESTION_REDUCE_SCOPE,
            self::SUGGESTION_DELAY,
            self::SUGGESTION_BORROW,
            self::SUGGESTION_CAPITAL_CALL,
            self::SUGGESTION_SIGNATURE,
        ];
    }

    public function assertSuggestionKey(string $key): string
    {
        $key = trim($key);

        if (! in_array($key, self::suggestionKeys(), true)) {
            throw new InvalidArgumentException(
                'Choose an available Capital Action suggestion.',
            );
        }

        return $key;
    }

    public function normalizeTitle(string $title): string
    {
        $title = trim($title);

        if ($title === '') {
            throw new InvalidArgumentException(
                'Capital Action title is required.',
            );
        }

        if (mb_strlen($title) > 240) {
            throw new InvalidArgumentException(
                'Capital Action title must be 240 characters or fewer.',
            );
        }

        return $title;
    }

    public function normalizeDescription(?string $description): ?string
    {
        if ($description === null) {
            return null;
        }

        $description = trim($description);

        if ($description === '') {
            return null;
        }

        if (mb_strlen($description) > 4000) {
            throw new InvalidArgumentException(
                'Capital Action description must be 4,000 characters or fewer.',
            );
        }

        return $description;
    }

    public function normalizeDueDate(?string $dueDate): ?string
    {
        if ($dueDate === null || trim($dueDate) === '') {
            return null;
        }

        $dueDate = trim($dueDate);
        $date = DateTimeImmutable::createFromFormat('!Y-m-d', $dueDate);

        if ($date === false || $date->format('Y-m-d') !== $dueDate) {
            throw new InvalidArgumentException(
                'Capital Action Due Date must be a valid calendar date.',
            );
        }

        return $dueDate;
    }
}
