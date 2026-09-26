<?php

declare(strict_types=1);

namespace App\Application\Formation;

use App\Application\Records\CreateDraftRecordVersion;
use App\Application\Records\CreateFormalRecordFamily;
use App\Application\Records\CreateProposal;
use App\Application\Records\FreezeProposalVersion;
use App\Application\Records\SubmitRecordVersionForReview;
use App\Application\Records\TransitionFormalRecordVersion;
use App\Domain\Access\CapabilityCatalog;
use App\Domain\Access\ValueObjects\Capability;
use App\Domain\Capital\ValueObjects\CapitalRequirement;
use App\Domain\Records\Enums\FormalRecordState;
use App\Domain\Records\Exceptions\StaleRevision;
use App\Domain\Records\ValueObjects\RecordScope;
use App\Infrastructure\Persistence\Eloquent\Businesses\Business;
use App\Infrastructure\Persistence\Eloquent\Identity\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;
use RuntimeException;

final class CapitalPlanning
{
    public function __construct(
        private readonly FormationActorContext $actor,
        private readonly FormationOccurrence $occurrence,
        private readonly CreateFormalRecordFamily $createFamily,
        private readonly CreateDraftRecordVersion $createDraft,
        private readonly SubmitRecordVersionForReview $submitForReview,
        private readonly CreateProposal $createProposal,
        private readonly FreezeProposalVersion $freezeProposal,
        private readonly TransitionFormalRecordVersion $transitionRecord,
    ) {}

    public function saveScenario(
        User $user,
        Business $business,
        string $kind,
        int $expectedRevision,
        string $name,
        CapitalRequirement $requirement,
        ?string $notes,
    ): ?array {
        $membership = $this->actor->membership(
            $user,
            $business,
            CapabilityCatalog::CAPITAL_MANAGE,
        );

        if ($membership === null) {
            return null;
        }

        $this->assertKind($kind);

        $row = DB::transaction(function () use (
            $business,
            $kind,
            $expectedRevision,
            $name,
            $requirement,
            $notes,
        ): array {
            $existing = DB::table('capital_scenarios')
                ->where('business_id', $business->getKey())
                ->where('scenario_kind', $kind)
                ->lockForUpdate()
                ->first();

            $fields = [
                'name' => trim($name),
                'pre_opening_costs' => $requirement->preOpening,
                'initial_assets_inventory' =>
                    $requirement->initialAssetsInventory,
                'working_capital' => $requirement->workingCapital,
                'contingency_reserve' =>
                    $requirement->contingencyReserve,
                'available_funding' => $requirement->availableFunding,
                'total_requirement' => $requirement->totalRequirement,
                'funding_gap' => $requirement->fundingGap,
                'notes' => $notes,
            ];

            if ($existing === null) {
                if ($expectedRevision !== 0) {
                    throw new StaleRevision(
                        $expectedRevision,
                        0,
                    );
                }

                $id = (string) Str::uuid7();

                DB::table('capital_scenarios')->insert([
                    'id' => $id,
                    'business_id' => $business->getKey(),
                    'scenario_kind' => $kind,
                    ...$fields,
                    'revision' => 1,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);

                return [
                    'id' => $id,
                    'revision' => 1,
                    ...$fields,
                ];
            }

            $actual = (int) $existing->revision;

            if ($actual !== $expectedRevision) {
                throw new StaleRevision(
                    $expectedRevision,
                    $actual,
                );
            }

            DB::table('capital_scenarios')
                ->where('id', $existing->id)
                ->where('business_id', $business->getKey())
                ->update([
                    ...$fields,
                    'revision' => $actual + 1,
                    'updated_at' => now(),
                ]);

            return [
                'id' => (string) $existing->id,
                'revision' => $actual + 1,
                ...$fields,
            ];
        });

        $this->occurrence->record(
            $user,
            $business,
            'capital.scenario.saved',
            'capital_scenario',
            $row['id'],
            [
                'scenario_kind' => $kind,
                'revision' => $row['revision'],
                'total_requirement' => $row['total_requirement'],
                'funding_gap' => $row['funding_gap'],
            ],
        );

        return $row;
    }

    public function promoteScenario(
        User $user,
        Business $business,
        string $kind,
    ): ?array {
        $membership = $this->actor->membership(
            $user,
            $business,
            CapabilityCatalog::CAPITAL_MANAGE,
        );

        if (
            $membership === null
            || ! $this->actor->allows(
                $user,
                $business,
                CapabilityCatalog::RECORDS_MANAGE,
            )
        ) {
            return null;
        }

        $this->assertKind($kind);

        return DB::transaction(function () use (
            $user,
            $business,
            $kind,
            $membership,
        ): ?array {
            $scenario = DB::table('capital_scenarios')
                ->where('business_id', $business->getKey())
                ->where('scenario_kind', $kind)
                ->lockForUpdate()
                ->first();

            if ($scenario === null) {
                return null;
            }

            $existing = DB::table('capital_plan_promotions')
                ->where('business_id', $business->getKey())
                ->where('capital_scenario_id', $scenario->id)
                ->where('scenario_revision', $scenario->revision)
                ->first();

            if ($existing !== null) {
                return [
                    'id' => (string) $existing->id,
                    'formal_record_version_id' =>
                        (string) $existing->formal_record_version_id,
                    'proposal_version_id' =>
                        (string) $existing->proposal_version_id,
                ];
            }

            $payload = [
                'business_id' => (string) $business->getKey(),
                'currency' => (string) $business->base_currency,
                'scenario_kind' => (string) $scenario->scenario_kind,
                'scenario_name' => (string) $scenario->name,
                'scenario_revision' => (int) $scenario->revision,
                'pre_opening_costs' =>
                    (string) $scenario->pre_opening_costs,
                'initial_assets_inventory' =>
                    (string) $scenario->initial_assets_inventory,
                'working_capital' =>
                    (string) $scenario->working_capital,
                'contingency_reserve' =>
                    (string) $scenario->contingency_reserve,
                'available_funding' =>
                    (string) $scenario->available_funding,
                'total_requirement' =>
                    (string) $scenario->total_requirement,
                'funding_gap' =>
                    (string) $scenario->funding_gap,
                'notes' => $scenario->notes,
            ];

            $contentHash = hash(
                'sha256',
                json_encode(
                    $payload,
                    JSON_THROW_ON_ERROR
                    | JSON_UNESCAPED_SLASHES
                    | JSON_UNESCAPED_UNICODE,
                ),
            );

            $latestPromotion = DB::table('capital_plan_promotions')
                ->where('business_id', $business->getKey())
                ->orderByDesc('created_at')
                ->first();

            $familyId = $latestPromotion?->formal_record_family_id;
            $predecessorId =
                $latestPromotion?->formal_record_version_id;

            if ($familyId === null) {
                $family = $this->createFamily->execute(
                    $user,
                    $business,
                    new Capability(CapabilityCatalog::RECORDS_MANAGE),
                    new RecordScope(
                        'capital_plan',
                        'business',
                        (string) $business->getKey(),
                    ),
                );

                if ($family === null) {
                    return null;
                }

                $familyId = (string) $family->getKey();
            }

            $recordVersion = $this->createDraft->execute(
                $user,
                $business,
                new Capability(CapabilityCatalog::RECORDS_MANAGE),
                (string) $familyId,
                $contentHash,
                sprintf(
                    'Capital Plan proposal from %s scenario revision %d.',
                    $kind,
                    (int) $scenario->revision,
                ),
                now(),
                null,
                null,
                $predecessorId === null
                    ? null
                    : (string) $predecessorId,
            );

            if ($recordVersion === null) {
                return null;
            }

            $submitted = $this->submitForReview->execute(
                $user,
                $business,
                new Capability(CapabilityCatalog::RECORDS_MANAGE),
                (string) $recordVersion->getKey(),
                1,
            );

            if ($submitted === null) {
                return null;
            }

            $proposal = $this->createProposal->execute(
                $user,
                $business,
                new Capability(CapabilityCatalog::RECORDS_MANAGE),
                $contentHash,
            );

            if ($proposal === null) {
                return null;
            }

            $proposalVersion = $this->freezeProposal->execute(
                $user,
                $business,
                new Capability(CapabilityCatalog::RECORDS_MANAGE),
                (string) $proposal->getKey(),
                1,
                [(string) $recordVersion->getKey()],
            );

            if ($proposalVersion === null) {
                return null;
            }

            $promotionId = (string) Str::uuid7();

            DB::table('capital_plan_promotions')->insert([
                'id' => $promotionId,
                'business_id' => $business->getKey(),
                'capital_scenario_id' => $scenario->id,
                'scenario_revision' => $scenario->revision,
                'formal_record_family_id' => $familyId,
                'formal_record_version_id' =>
                    $recordVersion->getKey(),
                'proposal_id' => $proposal->getKey(),
                'proposal_version_id' =>
                    $proposalVersion->getKey(),
                'content_hash' => $contentHash,
                'created_by_membership_id' =>
                    $membership->getKey(),
                'created_at' => now(),
            ]);

            $this->occurrence->record(
                $user,
                $business,
                'capital.scenario.promoted',
                'capital_plan_promotion',
                $promotionId,
                [
                    'scenario_kind' => $kind,
                    'scenario_revision' =>
                        (int) $scenario->revision,
                ],
            );

            return [
                'id' => $promotionId,
                'formal_record_version_id' =>
                    (string) $recordVersion->getKey(),
                'proposal_version_id' =>
                    (string) $proposalVersion->getKey(),
            ];
        });
    }

    public function advanceContentReview(
        User $user,
        Business $business,
        string $promotionId,
        FormalRecordState $target,
    ): bool {
        if (
            ! in_array(
                $target,
                [
                    FormalRecordState::UnderReview,
                    FormalRecordState::Approved,
                ],
                true,
            )
        ) {
            throw new InvalidArgumentException(
                'Capital content review may only enter Under Review or Approved.',
            );
        }

        if (
            ! $this->actor->allows(
                $user,
                $business,
                CapabilityCatalog::CAPITAL_MANAGE,
            )
            || ! $this->actor->allows(
                $user,
                $business,
                CapabilityCatalog::RECORDS_MANAGE,
            )
        ) {
            return false;
        }

        $promotion = DB::table('capital_plan_promotions')
            ->where('business_id', $business->getKey())
            ->where('id', $promotionId)
            ->first();

        if ($promotion === null) {
            return false;
        }

        $result = $this->transitionRecord->execute(
            $user,
            $business,
            new Capability(CapabilityCatalog::RECORDS_MANAGE),
            (string) $promotion->formal_record_version_id,
            $target,
        );

        if ($result === null) {
            return false;
        }

        $this->occurrence->record(
            $user,
            $business,
            'capital.content_review.advanced',
            'capital_plan_promotion',
            $promotionId,
            ['state' => $target->value],
        );

        return true;
    }

    public function scenarioForExport(
        User $user,
        Business $business,
        string $kind,
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

        $this->assertKind($kind);

        $scenario = DB::table('capital_scenarios')
            ->where('business_id', $business->getKey())
            ->where('scenario_kind', $kind)
            ->first();

        if ($scenario === null) {
            return null;
        }

        return [
            'business' => (string) $business->name,
            'currency' => (string) $business->base_currency,
            'scenario_kind' => (string) $scenario->scenario_kind,
            'scenario_name' => (string) $scenario->name,
            'revision' => (int) $scenario->revision,
            'pre_opening_costs' =>
                (string) $scenario->pre_opening_costs,
            'initial_assets_inventory' =>
                (string) $scenario->initial_assets_inventory,
            'working_capital' =>
                (string) $scenario->working_capital,
            'contingency_reserve' =>
                (string) $scenario->contingency_reserve,
            'total_requirement' =>
                (string) $scenario->total_requirement,
            'available_funding' =>
                (string) $scenario->available_funding,
            'funding_gap' => (string) $scenario->funding_gap,
            'notes' => $scenario->notes,
        ];
    }

    private function assertKind(string $kind): void
    {
        if (! in_array($kind, ['lean', 'base', 'growth'], true)) {
            throw new InvalidArgumentException(
                'Capital scenario must be Lean, Base or Growth.',
            );
        }
    }
}
