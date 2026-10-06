<?php

declare(strict_types=1);

namespace App\Domain\Partnership;

use DateTimeImmutable;
use InvalidArgumentException;

final class ContributionActionPlanContract
{
    public const string CONTRACT_VERSION =
        'contribution-action-plan-v1';

    public const string READ_MODEL_VERSION =
        'contribution-action-plan-read-model-v1';

    public const string SUGGESTION_OVERDUE =
        'follow_up_overdue_delivery';

    public const string SUGGESTION_EVIDENCE =
        'collect_missing_evidence';

    public const string SUGGESTION_REVIEW =
        'review_contribution_decision';

    public const string SUGGESTION_DEFAULT =
        'resolve_defaulted_contribution';

    public const string SUGGESTION_OWNERSHIP =
        'prepare_ownership_discussion';

    /** @return list<string> */
    public static function suggestionKeys(): array
    {
        return [
            self::SUGGESTION_OVERDUE,
            self::SUGGESTION_EVIDENCE,
            self::SUGGESTION_REVIEW,
            self::SUGGESTION_DEFAULT,
            self::SUGGESTION_OWNERSHIP,
        ];
    }

    public function assertSuggestionKey(
        string $key,
    ): string {
        $key = trim($key);

        if (
            ! in_array(
                $key,
                self::suggestionKeys(),
                true,
            )
        ) {
            throw new InvalidArgumentException(
                'Choose an available Contribution Action suggestion.',
            );
        }

        return $key;
    }

    public function normalizeTitle(
        string $title,
    ): string {
        $title = trim($title);

        if ($title === '') {
            throw new InvalidArgumentException(
                'Contribution Action title is required.',
            );
        }

        if (mb_strlen($title) > 240) {
            throw new InvalidArgumentException(
                'Contribution Action title must be 240 characters or fewer.',
            );
        }

        return $title;
    }

    public function normalizeDescription(
        ?string $description,
    ): ?string {
        if ($description === null) {
            return null;
        }

        $description = trim($description);

        if ($description === '') {
            return null;
        }

        if (mb_strlen($description) > 4000) {
            throw new InvalidArgumentException(
                'Contribution Action description must be 4,000 characters or fewer.',
            );
        }

        return $description;
    }

    public function normalizeDueDate(
        ?string $dueDate,
    ): ?string {
        if (
            $dueDate === null
            || trim($dueDate) === ''
        ) {
            return null;
        }

        $dueDate = trim($dueDate);
        $date = DateTimeImmutable::createFromFormat(
            '!Y-m-d',
            $dueDate,
        );

        if (
            $date === false
            || $date->format('Y-m-d')
                !== $dueDate
        ) {
            throw new InvalidArgumentException(
                'Contribution Action Due Date must be a valid calendar date.',
            );
        }

        return $dueDate;
    }
}
