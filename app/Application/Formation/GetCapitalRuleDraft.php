<?php

declare(strict_types=1);

namespace App\Application\Formation;

use App\Domain\Access\CapabilityCatalog;
use App\Domain\Capital\CapitalRuleDraftContract;
use App\Infrastructure\Persistence\Eloquent\Businesses\Business;
use App\Infrastructure\Persistence\Eloquent\Identity\User;
use Illuminate\Support\Facades\DB;
use RuntimeException;

final class GetCapitalRuleDraft
{
    public function __construct(
        private readonly FormationActorContext $actor,
        private readonly CapitalRuleDraftContract $contract,
    ) {}

    /**
     * @return array<string,mixed>|null
     */
    public function execute(
        User $user,
        Business $business,
    ): ?array {
        if (
            ! $this->actor->allows(
                $user,
                $business,
                CapabilityCatalog::CAPITAL_VIEW,
            )
        ) {
            return null;
        }

        $row = DB::table('capital_rule_drafts')
            ->where('business_id', $business->getKey())
            ->first();

        if ($row === null) {
            return [
                'contractVersion' => CapitalRuleDraftContract::CONTRACT_VERSION,
                'revision' => 0,
                'capitalPlanningRevision' => null,
                'input' => null,
                'updatedAt' => null,
                'semantics' => $this->semantics(),
            ];
        }

        if (
            (string) $row->contract_version
            !== CapitalRuleDraftContract::CONTRACT_VERSION
        ) {
            throw new RuntimeException(
                'Unsupported Capital Rule draft contract version.',
            );
        }

        $payload = json_decode(
            (string) $row->input_payload,
            true,
            512,
            JSON_THROW_ON_ERROR,
        );

        if (! is_array($payload)) {
            throw new RuntimeException(
                'Capital Rule draft payload is invalid.',
            );
        }

        return [
            'contractVersion' => (string) $row->contract_version,
            'revision' => (int) $row->revision,
            'capitalPlanningRevision' => (int) $row->capital_planning_revision,
            'input' => $this->contract->normalize($payload),
            'updatedAt' => (string) $row->updated_at,
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
            'planningTruthOnly' => true,
            'approvedTruth' => false,
            'signedTruth' => false,
            'effectiveTruth' => false,
            'decisionComplete' => false,
            'capitalCallExecuted' => false,
            'contributionTruth' => false,
            'equityTruth' => false,
            'ownershipTruth' => false,
        ];
    }
}
