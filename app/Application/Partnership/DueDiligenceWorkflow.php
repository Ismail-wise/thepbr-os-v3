<?php

declare(strict_types=1);

namespace App\Application\Partnership;

use App\Domain\Access\CapabilityCatalog;
use App\Domain\Partnership\Enums\DueDiligenceStatus;
use App\Domain\Records\Exceptions\StaleRevision;
use App\Infrastructure\Persistence\Eloquent\Businesses\Business;
use App\Infrastructure\Persistence\Eloquent\Identity\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;

final class DueDiligenceWorkflow
{
    public function __construct(
        private readonly PartnershipActorContext $actor,
        private readonly PartnershipOccurrence $occurrence,
    ) {}

    /**
     * @param  array<string, ?string>  $fields
     * @return array{id:string, revision:int, status:string}|null
     */
    public function save(
        User $user,
        Business $business,
        string $partnerId,
        ?string $caseId,
        int $expectedRevision,
        DueDiligenceStatus $status,
        ?string $riskRating,
        array $fields,
    ): ?array {
        $membership = $this->actor->membership(
            $user,
            $business,
            CapabilityCatalog::DUE_DILIGENCE_MANAGE,
        );

        if ($membership === null) {
            return null;
        }

        if (
            ! DB::table('partners')
                ->where('business_id', $business->getKey())
                ->where('id', $partnerId)
                ->exists()
        ) {
            return null;
        }

        $allowedFields = [
            'identity_legal_info',
            'background_summary',
            'business_experience',
            'financial_capacity',
            'reputation',
            'existing_business_interests',
            'conflict_of_interest',
            'time_commitment',
            'legal_regulatory_check',
            'notes',
        ];

        $fieldKeys = array_keys($fields);
        $canonicalKeys = $allowedFields;

        sort($fieldKeys);
        sort($canonicalKeys);

        if ($fieldKeys !== $canonicalKeys) {
            throw new InvalidArgumentException(
                'Due Diligence fields do not match the canonical schema.',
            );
        }

        $canonicalFields = [];

        foreach ($allowedFields as $field) {
            $canonicalFields[$field] = $fields[$field];
        }

        $fields = $canonicalFields;

        $riskRating = $this->normalizeRiskRating($riskRating);

        if ($status->isTerminal() && $riskRating === null) {
            throw new InvalidArgumentException(
                'A terminal Due Diligence case requires a risk rating.',
            );
        }

        return DB::transaction(function () use (
            $user,
            $business,
            $partnerId,
            $caseId,
            $expectedRevision,
            $status,
            $riskRating,
            $fields,
            $membership,
        ): ?array {
            if ($caseId === null) {
                if ($expectedRevision !== 0) {
                    throw new StaleRevision(
                        $expectedRevision,
                        0,
                    );
                }

                if ($status !== DueDiligenceStatus::Draft) {
                    throw new InvalidArgumentException(
                        'A new Due Diligence case must start as Draft.',
                    );
                }

                $id = (string) Str::uuid7();

                DB::table('partner_due_diligence_cases')->insert([
                    'id' => $id,
                    'business_id' => $business->getKey(),
                    'partner_id' => $partnerId,
                    'status' => $status->value,
                    'risk_rating' => $riskRating,
                    ...$this->normalizeFields($fields),
                    'reviewed_by_membership_id' => null,
                    'reviewed_at' => null,
                    'revision' => 1,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);

                $this->occurrence->record(
                    $user,
                    $business,
                    'partnership.due_diligence.created',
                    'partner_due_diligence',
                    $id,
                    [
                        'partner_id' => $partnerId,
                        'status' => $status->value,
                    ],
                );

                return [
                    'id' => $id,
                    'revision' => 1,
                    'status' => $status->value,
                ];
            }

            $current = DB::table('partner_due_diligence_cases')
                ->where('business_id', $business->getKey())
                ->where('partner_id', $partnerId)
                ->where('id', $caseId)
                ->lockForUpdate()
                ->first();

            if ($current === null) {
                return null;
            }

            $actualRevision = (int) $current->revision;

            if ($actualRevision !== $expectedRevision) {
                throw new StaleRevision(
                    $expectedRevision,
                    $actualRevision,
                );
            }

            $currentStatus = DueDiligenceStatus::from(
                (string) $current->status,
            );

            if ($currentStatus->isTerminal()) {
                throw new InvalidArgumentException(
                    'Completed Due Diligence history is immutable.',
                );
            }

            if (
                ! $this->transitionAllowed(
                    $currentStatus,
                    $status,
                )
            ) {
                throw new InvalidArgumentException(
                    sprintf(
                        'Invalid Due Diligence transition: %s -> %s.',
                        $currentStatus->value,
                        $status->value,
                    ),
                );
            }

            $terminal = $status->isTerminal();

            DB::table('partner_due_diligence_cases')
                ->where('id', $caseId)
                ->where('business_id', $business->getKey())
                ->update([
                    'status' => $status->value,
                    'risk_rating' => $riskRating,
                    ...$this->normalizeFields($fields),
                    'reviewed_by_membership_id' => $terminal ? $membership->getKey() : null,
                    'reviewed_at' => $terminal ? now() : null,
                    'revision' => $actualRevision + 1,
                    'updated_at' => now(),
                ]);

            $this->occurrence->record(
                $user,
                $business,
                'partnership.due_diligence.updated',
                'partner_due_diligence',
                $caseId,
                [
                    'partner_id' => $partnerId,
                    'status' => $status->value,
                    'revision' => $actualRevision + 1,
                ],
            );

            return [
                'id' => $caseId,
                'revision' => $actualRevision + 1,
                'status' => $status->value,
            ];
        });
    }

    private function transitionAllowed(
        DueDiligenceStatus $from,
        DueDiligenceStatus $to,
    ): bool {
        if ($from === $to) {
            return true;
        }

        return match ($from) {
            DueDiligenceStatus::Draft => $to === DueDiligenceStatus::InReview,

            DueDiligenceStatus::InReview => in_array(
                $to,
                [
                    DueDiligenceStatus::Completed,
                    DueDiligenceStatus::Blocked,
                ],
                true,
            ),

            DueDiligenceStatus::Completed,
            DueDiligenceStatus::Blocked => false,
        };
    }

    private function normalizeRiskRating(
        ?string $riskRating,
    ): ?string {
        if ($riskRating === null || trim($riskRating) === '') {
            return null;
        }

        $riskRating = trim($riskRating);

        if (
            ! in_array(
                $riskRating,
                ['low', 'moderate', 'high', 'critical'],
                true,
            )
        ) {
            throw new InvalidArgumentException(
                'Invalid Due Diligence risk rating.',
            );
        }

        return $riskRating;
    }

    /**
     * @param  array<string, ?string>  $fields
     * @return array<string, ?string>
     */
    private function normalizeFields(array $fields): array
    {
        return array_map(
            static function (?string $value): ?string {
                if ($value === null) {
                    return null;
                }

                $value = trim($value);

                return $value === '' ? null : $value;
            },
            $fields,
        );
    }
}
