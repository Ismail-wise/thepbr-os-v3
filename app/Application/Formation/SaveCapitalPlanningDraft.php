<?php

declare(strict_types=1);

namespace App\Application\Formation;

use App\Domain\Access\CapabilityCatalog;
use App\Domain\Capital\CapitalPlanningDraftContract;
use App\Domain\Records\Exceptions\StaleRevision;
use App\Infrastructure\Persistence\Eloquent\Businesses\Business;
use App\Infrastructure\Persistence\Eloquent\Identity\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final class SaveCapitalPlanningDraft
{
    public function __construct(
        private readonly FormationActorContext $actor,
        private readonly FormationOccurrence $occurrence,
        private readonly CapitalPlanningDraftContract $contract,
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
            $existing = DB::table('capital_planning_drafts')
                ->where('business_id', $businessId)
                ->lockForUpdate()
                ->first();

            if ($existing === null) {
                if ($expectedRevision !== 0) {
                    throw new StaleRevision(
                        $expectedRevision,
                        0,
                    );
                }

                $id = (string) Str::uuid7();
                $now = now();

                DB::table('capital_planning_drafts')->insert([
                    'id' => $id,
                    'business_id' => $businessId,
                    'contract_version' => CapitalPlanningDraftContract::CONTRACT_VERSION,
                    'input_payload' => $encoded,
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
                    'input' => $normalized,
                    'updatedAt' => $now->toIso8601String(),
                ];
            }

            $actual = (int) $existing->revision;

            if ($actual !== $expectedRevision) {
                throw new StaleRevision(
                    $expectedRevision,
                    $actual,
                );
            }

            $now = now();

            DB::table('capital_planning_drafts')
                ->where('id', $existing->id)
                ->where('business_id', $businessId)
                ->update([
                    'contract_version' => CapitalPlanningDraftContract::CONTRACT_VERSION,
                    'input_payload' => $encoded,
                    'revision' => $actual + 1,
                    'updated_by_membership_id' => $membership->getKey(),
                    'updated_at' => $now,
                ]);

            return [
                'id' => (string) $existing->id,
                'created' => false,
                'revision' => $actual + 1,
                'input' => $normalized,
                'updatedAt' => $now->toIso8601String(),
            ];
        });

        $this->occurrence->record(
            $user,
            $business,
            $result['created']
                ? 'capital.planning_draft.created'
                : 'capital.planning_draft.updated',
            'capital_planning_draft',
            $result['id'],
            [
                'contract_version' => CapitalPlanningDraftContract::CONTRACT_VERSION,
                'revision' => $result['revision'],
            ],
        );

        return [
            'contractVersion' => CapitalPlanningDraftContract::CONTRACT_VERSION,
            'revision' => $result['revision'],
            'input' => $result['input'],
            'baseCurrency' => (string) $business->base_currency,
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
            'approvedTruth' => false,
            'signedTruth' => false,
            'effectiveTruth' => false,
            'contributionTruth' => false,
            'equityTruth' => false,
            'ownershipTruth' => false,
        ];
    }
}
