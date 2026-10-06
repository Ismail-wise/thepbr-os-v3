<?php

declare(strict_types=1);

namespace App\Domain\Partnership;

use App\Domain\Partnership\Enums\ContributionType;
use InvalidArgumentException;

final class ContributionValuationCatalog
{
    /**
     * @return list<array{key:string,label:string}>
     */
    public function methods(
        ContributionType $type,
    ): array {
        return match ($type) {
            ContributionType::Cash => [
                [
                    'key' => 'face_value',
                    'label' => 'Face value / cash received',
                ],
                [
                    'key' => 'bank_or_receipt_evidence',
                    'label' => 'Bank / receipt evidence',
                ],
                [
                    'key' => 'custom',
                    'label' => 'Other documented method',
                ],
            ],
            ContributionType::TimeSkill => [
                [
                    'key' => 'fair_market_rate',
                    'label' => 'Fair market hourly rate',
                ],
                [
                    'key' => 'agreed_market_rate',
                    'label' => 'Agreed comparable market rate',
                ],
                [
                    'key' => 'custom',
                    'label' => 'Other documented method',
                ],
            ],
            ContributionType::PropertyAsset => [
                [
                    'key' => 'market_comparable',
                    'label' => 'Market comparable',
                ],
                [
                    'key' => 'independent_appraisal',
                    'label' => 'Independent appraisal',
                ],
                [
                    'key' => 'fair_rental_use_value',
                    'label' => 'Fair rental / right-to-use value',
                ],
                [
                    'key' => 'custom',
                    'label' => 'Other documented method',
                ],
            ],
            ContributionType::IpIntangible => [
                [
                    'key' => 'market_comparable',
                    'label' => 'Market comparable',
                ],
                [
                    'key' => 'income_based',
                    'label' => 'Income-based value',
                ],
                [
                    'key' => 'cost_based',
                    'label' => 'Cost-based value',
                ],
                [
                    'key' => 'negotiated_value',
                    'label' => 'Negotiated documented value',
                ],
                [
                    'key' => 'custom',
                    'label' => 'Other documented method',
                ],
            ],
        };
    }

    /**
     * @return list<array{key:string,label:string}>
     */
    public function intangibleSubtypes(): array
    {
        return [
            [
                'key' => 'ip',
                'label' => 'Intellectual Property',
            ],
            [
                'key' => 'brand',
                'label' => 'Brand',
            ],
            [
                'key' => 'software_system',
                'label' => 'Software / System',
            ],
            [
                'key' => 'customer_database',
                'label' => 'Customer Database',
            ],
            [
                'key' => 'network_introductions',
                'label' => 'Network / Introductions',
            ],
            [
                'key' => 'customer_access',
                'label' => 'Customer Access',
            ],
            [
                'key' => 'know_how',
                'label' => 'Know-how',
            ],
            [
                'key' => 'business_process',
                'label' => 'Business Process',
            ],
        ];
    }

    public function normalizeMethod(
        ContributionType $type,
        string $method,
    ): string {
        $method = trim($method);

        if ($method === '') {
            throw new InvalidArgumentException(
                'A valuation method is required.',
            );
        }

        $known = array_column(
            $this->methods($type),
            'key',
        );

        if (
            in_array(
                $method,
                $known,
                true,
            )
        ) {
            return $method;
        }

        if (
            str_starts_with(
                $method,
                'custom:',
            )
            && trim(
                substr($method, 7),
            ) !== ''
        ) {
            return $method;
        }

        if (mb_strlen($method) <= 500) {
            return $method;
        }

        throw new InvalidArgumentException(
            'Choose a supported valuation method or provide a documented custom method of 500 characters or fewer.',
        );
    }

    public function assertIntangibleSubtype(
        string $subtype,
    ): string {
        $subtype = trim($subtype);

        if (
            ! in_array(
                $subtype,
                array_column(
                    $this->intangibleSubtypes(),
                    'key',
                ),
                true,
            )
        ) {
            throw new InvalidArgumentException(
                'Choose a supported IP / Intangible subtype.',
            );
        }

        return $subtype;
    }
}
