<?php

declare(strict_types=1);

namespace App\Domain\Partnership;

use DateTimeImmutable;
use InvalidArgumentException;

final class ContributionSetupContract
{
    public const string CONTRACT_VERSION = 'contribution-setup-v1';

    public const string READ_MODEL_VERSION = 'contribution-setup-read-model-v1';

    /**
     * @param  array<string,mixed>  $input
     * @return array{
     *   valuationDate:string,
     *   currency:string,
     *   periodStart:string,
     *   periodEnd:string,
     *   valuationOwnerMembershipId:string,
     *   approverMembershipIds:list<string>
     * }
     */
    public function normalize(array $input): array
    {
        $valuationDate = $this->date(
            $input['valuationDate'] ?? null,
            'Valuation Date',
        );
        $periodStart = $this->date(
            $input['periodStart'] ?? null,
            'Contribution Period start',
        );
        $periodEnd = $this->date(
            $input['periodEnd'] ?? null,
            'Contribution Period end',
        );

        if ($periodEnd < $periodStart) {
            throw new InvalidArgumentException(
                'Contribution Period end must be on or after its start date.',
            );
        }

        $currency = strtoupper(
            trim((string) ($input['currency'] ?? '')),
        );

        if (preg_match('/\A[A-Z]{3}\z/', $currency) !== 1) {
            throw new InvalidArgumentException(
                'Currency must be a three-letter currency code.',
            );
        }

        $owner = trim((string) (
            $input['valuationOwnerMembershipId'] ?? ''
        ));

        if ($owner === '') {
            throw new InvalidArgumentException(
                'Choose a Valuation Owner.',
            );
        }

        $approvers = $input['approverMembershipIds'] ?? [];

        if (
            ! is_array($approvers)
            || ! array_is_list($approvers)
            || $approvers === []
        ) {
            throw new InvalidArgumentException(
                'Choose at least one intended Approver.',
            );
        }

        $normalizedApprovers = [];

        foreach ($approvers as $approver) {
            if (! is_string($approver) || trim($approver) === '') {
                throw new InvalidArgumentException(
                    'Approver selection is invalid.',
                );
            }

            $approver = trim($approver);

            if (in_array($approver, $normalizedApprovers, true)) {
                throw new InvalidArgumentException(
                    'Approvers must not repeat.',
                );
            }

            $normalizedApprovers[] = $approver;
        }

        return [
            'valuationDate' => $valuationDate,
            'currency' => $currency,
            'periodStart' => $periodStart,
            'periodEnd' => $periodEnd,
            'valuationOwnerMembershipId' => $owner,
            'approverMembershipIds' => $normalizedApprovers,
        ];
    }

    private function date(
        mixed $value,
        string $label,
    ): string {
        $value = trim((string) $value);
        $date = DateTimeImmutable::createFromFormat(
            '!Y-m-d',
            $value,
        );

        if (
            $date === false
            || $date->format('Y-m-d') !== $value
        ) {
            throw new InvalidArgumentException(
                $label.' must be a valid calendar date.',
            );
        }

        return $value;
    }
}
