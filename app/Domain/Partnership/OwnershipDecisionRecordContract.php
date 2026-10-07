<?php

declare(strict_types=1);

namespace App\Domain\Partnership;

use DateTimeImmutable;
use InvalidArgumentException;

final class OwnershipDecisionRecordContract
{
    public const string CONTRACT_VERSION = 'ownership-decision-record-v1';

    public const string READ_MODEL_VERSION = 'ownership-decision-record-read-model-v1';

    /**
     * @param  array<string,mixed>  $input
     * @return array{
     *   decisionOwnerMembershipId:string,
     *   reviewDate:string,
     *   decisionSummary:string,
     *   evidenceReferences:list<string>
     * }
     */
    public function normalize(array $input): array
    {
        $owner = trim((string) ($input['decisionOwnerMembershipId'] ?? ''));
        if ($owner === '') {
            throw new InvalidArgumentException('Choose a Decision Owner.');
        }

        $reviewDate = trim((string) ($input['reviewDate'] ?? ''));
        $date = DateTimeImmutable::createFromFormat('!Y-m-d', $reviewDate);
        if ($date === false || $date->format('Y-m-d') !== $reviewDate) {
            throw new InvalidArgumentException('Review Date must be a valid calendar date.');
        }

        $summary = trim((string) ($input['decisionSummary'] ?? ''));
        if ($summary === '') {
            throw new InvalidArgumentException('Decision Summary is required.');
        }
        if (mb_strlen($summary) > 4000) {
            throw new InvalidArgumentException('Decision Summary must be 4,000 characters or fewer.');
        }

        $references = $input['evidenceReferences'] ?? [];
        if (! is_array($references) || ! array_is_list($references)) {
            throw new InvalidArgumentException('Evidence References must be a list.');
        }

        $normalized = [];
        foreach ($references as $reference) {
            if (! is_string($reference)) {
                throw new InvalidArgumentException('Evidence Reference entries must be text.');
            }
            $reference = trim($reference);
            if ($reference === '') {
                continue;
            }
            if (mb_strlen($reference) > 500) {
                throw new InvalidArgumentException('Each Evidence Reference must be 500 characters or fewer.');
            }
            if (! in_array($reference, $normalized, true)) {
                $normalized[] = $reference;
            }
        }

        if (count($normalized) > 20) {
            throw new InvalidArgumentException('Add no more than 20 Evidence References.');
        }

        return [
            'decisionOwnerMembershipId' => $owner,
            'reviewDate' => $reviewDate,
            'decisionSummary' => $summary,
            'evidenceReferences' => $normalized,
        ];
    }
}
