<?php

declare(strict_types=1);

namespace App\Domain\Capital;

use DateTimeImmutable;
use InvalidArgumentException;

final class CapitalDecisionRecordContract
{
    public const string CONTRACT_VERSION = 'capital-decision-record-v1';

    public const string READ_MODEL_VERSION = 'capital-decision-record-read-model-v1';

    /**
     * @param  array<string,mixed>  $input
     * @return array{
     *   decisionOwnerMembershipId:string,
     *   effectiveDate:string,
     *   reviewDate:string,
     *   decisionSummary:string,
     *   evidenceReferences:list<string>
     * }
     */
    public function normalize(array $input): array
    {
        $owner = trim((string) ($input['decisionOwnerMembershipId'] ?? ''));
        $effectiveDate = trim((string) ($input['effectiveDate'] ?? ''));
        $reviewDate = trim((string) ($input['reviewDate'] ?? ''));
        $summary = trim((string) ($input['decisionSummary'] ?? ''));

        if ($owner === '') {
            throw new InvalidArgumentException('Choose a Decision Owner.');
        }

        $this->assertDate($effectiveDate, 'Effective Date');
        $this->assertDate($reviewDate, 'Review Date');

        if ($reviewDate < $effectiveDate) {
            throw new InvalidArgumentException(
                'Review Date must be on or after the Effective Date.',
            );
        }

        if ($summary === '') {
            throw new InvalidArgumentException(
                'Decision Summary is required.',
            );
        }

        if (mb_strlen($summary) > 2000) {
            throw new InvalidArgumentException(
                'Decision Summary must be 2,000 characters or fewer.',
            );
        }

        $references = $input['evidenceReferences'] ?? [];

        if (! is_array($references)) {
            throw new InvalidArgumentException(
                'Evidence / References must be a list.',
            );
        }

        if (count($references) > 20) {
            throw new InvalidArgumentException(
                'Add no more than 20 Evidence / Reference entries.',
            );
        }

        $normalizedReferences = [];

        foreach ($references as $reference) {
            if (! is_string($reference)) {
                throw new InvalidArgumentException(
                    'Each Evidence / Reference entry must be text.',
                );
            }

            $value = trim($reference);

            if ($value === '') {
                continue;
            }

            if (mb_strlen($value) > 500) {
                throw new InvalidArgumentException(
                    'Each Evidence / Reference entry must be 500 characters or fewer.',
                );
            }

            if (! in_array($value, $normalizedReferences, true)) {
                $normalizedReferences[] = $value;
            }
        }

        return [
            'decisionOwnerMembershipId' => $owner,
            'effectiveDate' => $effectiveDate,
            'reviewDate' => $reviewDate,
            'decisionSummary' => $summary,
            'evidenceReferences' => $normalizedReferences,
        ];
    }

    private function assertDate(
        string $value,
        string $label,
    ): void {
        $date = DateTimeImmutable::createFromFormat('!Y-m-d', $value);

        if (
            $date === false
            || $date->format('Y-m-d') !== $value
        ) {
            throw new InvalidArgumentException(
                $label.' must be a valid calendar date.',
            );
        }
    }
}
