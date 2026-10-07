<?php

declare(strict_types=1);

namespace App\Domain\Partnership;

use DateTimeImmutable;
use InvalidArgumentException;

final class OwnershipActionPlanContract
{
    public const string CONTRACT_VERSION = 'ownership-action-plan-v1';

    public const string READ_MODEL_VERSION = 'ownership-action-plan-read-model-v1';

    public const string SUGGESTION_REVIEW = 'review_ownership_decision';

    /** @return list<string> */
    public static function suggestionKeys(): array
    {
        return [self::SUGGESTION_REVIEW];
    }

    public function assertSuggestionKey(string $key): string
    {
        $key = trim($key);
        if (! in_array($key, self::suggestionKeys(), true)) {
            throw new InvalidArgumentException('Choose an available Ownership Action suggestion.');
        }

        return $key;
    }

    public function normalizeTitle(string $title): string
    {
        $title = trim($title);
        if ($title === '') {
            throw new InvalidArgumentException('Ownership Action title is required.');
        }
        if (mb_strlen($title) > 240) {
            throw new InvalidArgumentException('Ownership Action title must be 240 characters or fewer.');
        }

        return $title;
    }

    public function normalizeDescription(?string $description): ?string
    {
        if ($description === null || trim($description) === '') {
            return null;
        }
        $description = trim($description);
        if (mb_strlen($description) > 4000) {
            throw new InvalidArgumentException('Ownership Action description must be 4,000 characters or fewer.');
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
            throw new InvalidArgumentException('Ownership Action Due Date must be a valid calendar date.');
        }

        return $dueDate;
    }
}
