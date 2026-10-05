<?php

declare(strict_types=1);

namespace App\Application\Formation;

use App\Domain\Access\CapabilityCatalog;
use App\Domain\Capital\CapitalPlanningDraftContract;
use App\Infrastructure\Persistence\Eloquent\Businesses\Business;
use App\Infrastructure\Persistence\Eloquent\Identity\User;
use Illuminate\Support\Facades\DB;
use RuntimeException;

final class GetCapitalPlanningDraft
{
    public function __construct(
        private readonly FormationActorContext $actor,
        private readonly CapitalPlanningDraftContract $contract,
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

        $row = DB::table('capital_planning_drafts')
            ->where('business_id', $business->getKey())
            ->first();

        if ($row === null) {
            return [
                'contractVersion' => CapitalPlanningDraftContract::CONTRACT_VERSION,
                'revision' => 0,
                'input' => null,
                'baseCurrency' => (string) $business->base_currency,
                'updatedAt' => null,
                'semantics' => $this->semantics(),
            ];
        }

        if (
            (string) $row->contract_version
            !== CapitalPlanningDraftContract::CONTRACT_VERSION
        ) {
            throw new RuntimeException(
                'Unsupported Capital planning draft contract version.',
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
                'Capital planning draft payload is invalid.',
            );
        }

        return [
            'contractVersion' => (string) $row->contract_version,
            'revision' => (int) $row->revision,
            'input' => $this->contract->normalize($payload),
            'baseCurrency' => (string) $business->base_currency,
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
            'approvedTruth' => false,
            'signedTruth' => false,
            'effectiveTruth' => false,
            'contributionTruth' => false,
            'equityTruth' => false,
            'ownershipTruth' => false,
        ];
    }
}
