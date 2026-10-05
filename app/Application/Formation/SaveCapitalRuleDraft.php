<?php

declare(strict_types=1);

namespace App\Application\Formation;

use App\Domain\Access\CapabilityCatalog;
use App\Domain\Capital\CapitalRuleDraftContract;
use App\Domain\Records\Exceptions\StaleRevision;
use App\Infrastructure\Persistence\Eloquent\Businesses\Business;
use App\Infrastructure\Persistence\Eloquent\Identity\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;

final class SaveCapitalRuleDraft
{
    public function __construct(
        private readonly FormationActorContext $actor,
        private readonly FormationOccurrence $occurrence,
        private readonly CapitalRuleDraftContract $contract,
        private readonly GetCapitalPlanningDraftCalculation $calculations,
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

        $calculationEnvelope = $this->calculations->execute(
            $user,
            $business,
        );

        if ($calculationEnvelope === null) {
            return null;
        }

        $planningRevision = (int) (
            $calculationEnvelope['revision'] ?? 0
        );
        $calculation = $calculationEnvelope['calculation'] ?? null;

        if (
            $planningRevision < 1
            || ! is_array($calculation)
            || ! $this->calculationComplete($calculation)
        ) {
            throw new InvalidArgumentException(
                'Complete the current Capital calculation before saving the Capital Rule draft.',
            );
        }

        $normalized = $this->contract->normalize($input);
        $gapMinor = $this->minor(
            $calculation['fundingPosition']['fundingGap'] ?? null,
        );

        if ($gapMinor === null) {
            throw new InvalidArgumentException(
                'Funding Gap is not currently calculable.',
            );
        }

        if ($gapMinor === 0) {
            $normalized['shortfallResponses'] = [];
            $normalized['shortfallRuleNotes'] = null;
            $normalized['capitalCallRuleNote'] = null;
        }

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
            $planningRevision,
            $normalized,
            $encoded,
        ): array {
            $businessId = (string) $business->getKey();
            $existing = DB::table('capital_rule_drafts')
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

                DB::table('capital_rule_drafts')->insert([
                    'id' => $id,
                    'business_id' => $businessId,
                    'contract_version' => CapitalRuleDraftContract::CONTRACT_VERSION,
                    'input_payload' => $encoded,
                    'capital_planning_revision' => $planningRevision,
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
                    'capitalPlanningRevision' => $planningRevision,
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

            DB::table('capital_rule_drafts')
                ->where('id', $existing->id)
                ->where('business_id', $businessId)
                ->update([
                    'contract_version' => CapitalRuleDraftContract::CONTRACT_VERSION,
                    'input_payload' => $encoded,
                    'capital_planning_revision' => $planningRevision,
                    'revision' => $actual + 1,
                    'updated_by_membership_id' => $membership->getKey(),
                    'updated_at' => $now,
                ]);

            return [
                'id' => (string) $existing->id,
                'created' => false,
                'revision' => $actual + 1,
                'capitalPlanningRevision' => $planningRevision,
                'input' => $normalized,
                'updatedAt' => $now->toIso8601String(),
            ];
        });

        $this->occurrence->record(
            $user,
            $business,
            $result['created']
                ? 'capital.rule_draft.created'
                : 'capital.rule_draft.updated',
            'capital_rule_draft',
            $result['id'],
            [
                'contract_version' => CapitalRuleDraftContract::CONTRACT_VERSION,
                'revision' => $result['revision'],
                'capital_planning_revision' => $result[
                    'capitalPlanningRevision'
                ],
            ],
        );

        return [
            'contractVersion' => CapitalRuleDraftContract::CONTRACT_VERSION,
            'revision' => $result['revision'],
            'capitalPlanningRevision' => $result['capitalPlanningRevision'],
            'input' => $result['input'],
            'updatedAt' => $result['updatedAt'],
            'semantics' => [
                'editablePlanningDraft' => true,
                'planningTruthOnly' => true,
                'approvedTruth' => false,
                'signedTruth' => false,
                'effectiveTruth' => false,
                'decisionComplete' => false,
                'capitalCallExecuted' => false,
                'contributionTruth' => false,
                'equityTruth' => false,
                'ownershipTruth' => false,
            ],
        ];
    }

    /**
     * @param  array<string,mixed>  $calculation
     */
    private function calculationComplete(array $calculation): bool
    {
        return ($calculation['preOpening']['status'] ?? null) === 'calculable'
            && ($calculation['initialAssetsInventory']['status'] ?? null) === 'calculable'
            && ($calculation['workingCapital']['status'] ?? null) === 'calculable'
            && ($calculation['contingency']['status'] ?? null) === 'calculable'
            && ($calculation['fundingPosition']['status'] ?? null) === 'calculable';
    }

    private function minor(mixed $value): ?int
    {
        if (
            ! is_string($value)
            || preg_match(
                '/\A(\d+)(?:\.(\d{1,2}))?\z/',
                $value,
                $matches,
            ) !== 1
        ) {
            return null;
        }

        return ((int) $matches[1] * 100)
            + (int) str_pad($matches[2] ?? '', 2, '0');
    }
}
