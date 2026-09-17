<?php

declare(strict_types=1);

namespace App\Domain\Records\ValueObjects;

use InvalidArgumentException;

final readonly class RecordScope
{
    public string $recordType;

    public string $subjectType;

    public string $subjectId;

    public function __construct(
        string $recordType,
        string $subjectType,
        string $subjectId,
    ) {
        $recordType = trim($recordType);
        $subjectType = trim($subjectType);
        $subjectId = trim($subjectId);

        if ($recordType === '' || mb_strlen($recordType) > 160) {
            throw new InvalidArgumentException('Record type must be 1-160 characters.');
        }

        if ($subjectType === '' || mb_strlen($subjectType) > 160) {
            throw new InvalidArgumentException('Subject type must be 1-160 characters.');
        }

        if ($subjectId === '' || mb_strlen($subjectId) > 191) {
            throw new InvalidArgumentException('Subject ID must be 1-191 characters.');
        }

        $this->recordType = $recordType;
        $this->subjectType = $subjectType;
        $this->subjectId = $subjectId;
    }
}
