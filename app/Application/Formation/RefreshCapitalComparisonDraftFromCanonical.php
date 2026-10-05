<?php

declare(strict_types=1);

namespace App\Application\Formation;

use App\Domain\Access\CapabilityCatalog;
use App\Domain\Capital\CapitalComparisonDraftContract;
use App\Domain\Records\Exceptions\StaleRevision;
use App\Infrastructure\Persistence\Eloquent\Businesses\Business;
use App\Infrastructure\Persistence\Eloquent\Identity\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;

final class RefreshCapitalComparisonDraftFromCanonical
{
    public function __construct(
        private readonly FormationActorContext $actor,
        private readonly FormationOccurrence $occurrence,
        private readonly GetCapitalPlanningDraft $planning,
        private readonly CapitalComparisonDraftContract $contract,
    ) {}

    /**
     * @return array<string,mixed>|null
     */
    public function execute(
        User $user,
        Business $business,
        int $expectedRevision,
    ): ?array {
        $membership = $this->actor->membership(
            $user,
            $business,
            CapabilityCatalog::CAPITAL_MANAGE,
        );

        if ($membership === null) {
            return null;
        }

        $canonical = $this->planning->execute($user, $business);

        if (
            $canonical === null
            || (int) $canonical['revision'] < 1
            || ! is_array($canonical['input'])
        ) {
            throw new InvalidArgumentException(
                'Save the current Capital planning draft before preparing comparison scenarios.',
            );
        }

        $capitalPlanningRevision = (int) $canonical['revision'];
        $normalized = $this->contract->initializeFromCanonical(
            $canonical['input'],
        );
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
            $capitalPlanningRevision,
            $normalized,
            $encoded,
        ): array {
            $businessId = (string) $business->getKey();
            $existing = DB::table('capital_comparison_drafts')
                ->where('business_id', $businessId)
                ->lockForUpdate()
                ->first();

            if ($existing === null) {
                if ($expectedRevision !== 0) {
                    throw new StaleRevision($expectedRevision, 0);
                }

                $id = (string) Str::uuid7();
                $now = now();

                DB::table('capital_comparison_drafts')->insert([
                    'id' => $id,
                    'business_id' => $businessId,
                    'contract_version' => CapitalComparisonDraftContract::CONTRACT_VERSION,
                    'input_payload' => $encoded,
                    'capital_planning_revision' => $capitalPlanningRevision,
                    'revision' => 1,
                    'created_by_membership_id' => $membership->getKey(),
                    'updated_by_membership_id' => $membership->getKey(),
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);

                return [
                    'id' => $id,
                    'created' => true,
                    'revision' => 1,
                    'capitalPlanningRevision' => $capitalPlanningRevision,
                    'input' => $normalized,
                    'updatedAt' => $now->toIso8601String(),
                ];
            }

            $actual = (int) $existing->revision;

            if ($actual !== $expectedRevision) {
                throw new StaleRevision($expectedRevision, $actual);
            }

            $now = now();

            DB::table('capital_comparison_drafts')
                ->where('id', $existing->id)
                ->where('business_id', $businessId)
                ->update([
                    'contract_version' => CapitalComparisonDraftContract::CONTRACT_VERSION,
                    'input_payload' => $encoded,
                    'capital_planning_revision' => $capitalPlanningRevision,
                    'revision' => $actual + 1,
                    'updated_by_membership_id' => $membership->getKey(),
                    'updated_at' => $now,
                ]);

            return [
                'id' => (string) $existing->id,
                'created' => false,
                'revision' => $actual + 1,
                'capitalPlanningRevision' => $capitalPlanningRevision,
                'input' => $normalized,
                'updatedAt' => $now->toIso8601String(),
            ];
        });

        $this->occurrence->record(
            $user,
            $business,
            $result['created']
                ? 'capital.comparison.created'
                : 'capital.comparison.updated',
            'capital_comparison_draft',
            $result['id'],
            [
                'contract_version' => CapitalComparisonDraftContract::CONTRACT_VERSION,
                'revision' => $result['revision'],
                'capital_planning_revision' => $result['capitalPlanningRevision'],
                'prepared_scenarios' => 3,
            ],
        );

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
