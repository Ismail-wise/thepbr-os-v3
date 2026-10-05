<?php

declare(strict_types=1);

namespace App\Application\Formation;

use App\Domain\Access\CapabilityCatalog;
use App\Domain\Capital\CapitalComparisonDraftContract;
use App\Domain\Records\Exceptions\StaleRevision;
use App\Infrastructure\Persistence\Eloquent\Businesses\Business;
use App\Infrastructure\Persistence\Eloquent\Identity\User;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

final class SaveCapitalComparisonDraft
{
    public function __construct(
        private readonly FormationActorContext $actor,
        private readonly FormationOccurrence $occurrence,
        private readonly CapitalComparisonDraftContract $contract,
    ) {}

    /**
     * @param  array<string,mixed>  $input
     * @return array<string,mixed>|null
     */
    public function execute(
        User $user,
        Business $business,
        int $expectedRevision,
        array $input,
    ): ?array {
        $membership = $this->actor->membership(
            $user,
            $business,
            CapabilityCatalog::CAPITAL_MANAGE,
        );

        if ($membership === null) {
            return null;
        }

        $normalized = $this->contract->normalize($input);
        $encoded = json_encode(
            $normalized,
            JSON_THROW_ON_ERROR
            | JSON_UNESCAPED_SLASHES
            | JSON_UNESCAPED_UNICODE,
        );

        $result = DB::transaction(function () use (
            $business,
            $membership,
            $expectedRevision,
            $normalized,
            $encoded,
        ): array {
            $businessId = (string) $business->getKey();
            $existing = DB::table('capital_comparison_drafts')
                ->where('business_id', $businessId)
                ->lockForUpdate()
                ->first();

            if ($existing === null) {
                throw new InvalidArgumentException(
                    'Prepare comparison scenarios from the current Capital plan before editing them.',
                );
            }

            $actual = (int) $existing->revision;

            if ($actual !== $expectedRevision) {
                throw new StaleRevision($expectedRevision, $actual);
            }

            $previousPayload = json_decode(
                (string) $existing->input_payload,
                true,
                512,
                JSON_THROW_ON_ERROR,
            );
            $previous = is_array($previousPayload)
                ? $this->contract->normalize($previousPayload)
                : ['preferredPlan' => null];

            $now = now();

            DB::table('capital_comparison_drafts')
                ->where('id', $existing->id)
                ->where('business_id', $businessId)
                ->update([
                    'contract_version' => CapitalComparisonDraftContract::CONTRACT_VERSION,
                    'input_payload' => $encoded,
                    'revision' => $actual + 1,
                    'updated_by_membership_id' => $membership->getKey(),
                    'updated_at' => $now,
                ]);

            return [
                'id' => (string) $existing->id,
                'revision' => $actual + 1,
                'capitalPlanningRevision' => (int) $existing->capital_planning_revision,
                'input' => $normalized,
                'previousPreferredPlan' => $previous['preferredPlan'] ?? null,
                'updatedAt' => $now->toIso8601String(),
            ];
        });

        $prepared = count(array_filter(
            $result['input']['scenarios'],
            static fn (mixed $scenario): bool => is_array($scenario),
        ));

        $this->occurrence->record(
            $user,
            $business,
            'capital.comparison.updated',
            'capital_comparison_draft',
            $result['id'],
            [
                'contract_version' => CapitalComparisonDraftContract::CONTRACT_VERSION,
                'revision' => $result['revision'],
                'capital_planning_revision' => $result['capitalPlanningRevision'],
                'prepared_scenarios' => $prepared,
            ],
        );

        $preferred = $result['input']['preferredPlan'];

        if (
            is_string($preferred)
            && $preferred !== $result['previousPreferredPlan']
        ) {
            $this->occurrence->record(
                $user,
                $business,
                'capital.comparison.preferred_selected',
                'capital_comparison_draft',
                $result['id'],
                [
                    'revision' => $result['revision'],
                    'preferred_plan' => $preferred,
                ],
            );
        }

        return [
            'contractVersion' => CapitalComparisonDraftContract::CONTRACT_VERSION,
            'revision' => $result['revision'],
            'capitalPlanningRevision' => $result['capitalPlanningRevision'],
            'input' => $result['input'],
            'updatedAt' => $result['updatedAt'],
            'semantics' => $this->semantics(),
        ];
    }

    /**
     * @return array<string,bool>
     */
    private function semantics(): array
    {
        return [
            'editablePlanningDraft' => true,
            'planningAlternativesOnly' => true,
            'preferredPlanIsPlanningOnly' => true,
            'approvedTruth' => false,
            'signedTruth' => false,
            'effectiveTruth' => false,
            'decisionComplete' => false,
            'fundingCommitmentTruth' => false,
            'contributionTruth' => false,
            'equityTruth' => false,
            'ownershipTruth' => false,
        ];
    }
}
