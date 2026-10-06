<?php

declare(strict_types=1);

namespace App\Application\Formation;

use App\Application\Governance\GovernanceActorContext;
use App\Domain\Access\CapabilityCatalog;
use App\Domain\Capital\CapitalActionPlanContract;
use App\Domain\Governance\Enums\ActionStatus;
use App\Infrastructure\Persistence\Eloquent\Businesses\Business;
use App\Infrastructure\Persistence\Eloquent\Governance\Action;
use App\Infrastructure\Persistence\Eloquent\Identity\User;
use App\Infrastructure\Persistence\Eloquent\Members\Membership;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

final class GetCapitalActionPlanReadModel
{
    public function __construct(
        private readonly FormationActorContext $actor,
        private readonly GovernanceActorContext $governanceActor,
        private readonly GetCapitalActionPlanSource $source,
        private readonly GetCapitalDecisionRecordReadModel $decisionRecord,
        private readonly CapitalActionPlanWorkflow $workflow,
    ) {}

    /**
     * @return array<string,mixed>|null
     */
    public function execute(
        User $user,
        Business $business,
    ): ?array {
        if (! $this->actor->allows(
            $user,
            $business,
            CapabilityCatalog::CAPITAL_VIEW,
        )) {
            return null;
        }

        $decisionRecord = $this->decisionRecord->execute(
            $user,
            $business,
        );

        if ($decisionRecord === null) {
            return null;
        }

        $context = $this->source->execute($business);

        if ($context === null) {
            return [
                'contractVersion' => CapitalActionPlanContract::READ_MODEL_VERSION,
                'available' => false,
                'established' => false,
                'canManage' => false,
                'actions' => [],
                'suggestions' => [],
                'outstandingCount' => 0,
                'completedCount' => 0,
                'semantics' => $this->semantics(false),
            ];
        }

        $canManage = $this->actor->allows(
            $user,
            $business,
            CapabilityCatalog::CAPITAL_MANAGE,
        ) && $this->actor->allows(
            $user,
            $business,
            CapabilityCatalog::GOVERNANCE_ACTION_MANAGE,
        );

        $canReadActions = $this->actor->allows(
            $user,
            $business,
            CapabilityCatalog::GOVERNANCE_ACTION_MANAGE,
        );

        $rows = DB::table('capital_action_links as link')
            ->join('actions as action', function ($join): void {
                $join
                    ->on('action.id', '=', 'link.action_id')
                    ->on(
                        'action.business_id',
                        '=',
                        'link.business_id',
                    );
            })
            ->where('link.business_id', $business->getKey())
            ->where(
                'link.capital_decision_record_id',
                $context['record']->id,
            )
            ->orderBy('action.created_at')
            ->get([
                'action.id',
                'action.title',
                'action.description',
                'action.status',
                'action.blocked_reason',
                'action.assigned_membership_id',
                'action.due_at',
                'action.completed_at',
                'action.created_at',
                'link.suggestion_key',
            ]);

        $visibleActions = $canReadActions
            ? $rows->filter(
                fn (object $row): bool => $this->governanceActor
                    ->canAccessResource(
                        $user,
                        $business,
                        CapabilityCatalog::GOVERNANCE_ACTION_MANAGE,
                        Action::class,
                        (string) $row->id,
                    ),
            )
            : collect();

        $actions = $visibleActions
            ->map(fn (object $row): array => [
                'id' => (string) $row->id,
                'title' => (string) $row->title,
                'description' => $row->description === null
                    ? null
                    : (string) $row->description,
                'status' => (string) $row->status,
                'blockedReason' => $row->blocked_reason === null
                    ? null
                    : (string) $row->blocked_reason,
                'owner' => $this->membershipLabel(
                    $business,
                    (string) $row->assigned_membership_id,
                ),
                'dueDate' => $row->due_at === null
                    ? null
                    : CarbonImmutable::parse(
                        (string) $row->due_at,
                    )->format('Y-m-d'),
                'completedAt' => $row->completed_at === null
                    ? null
                    : (string) $row->completed_at,
                'createdAt' => (string) $row->created_at,
                'suggestionKey' => $row->suggestion_key === null
                    ? null
                    : (string) $row->suggestion_key,
                'canUpdate' => $canManage,
            ])
            ->values()
            ->all();

        $validRows = $rows->filter(
            static fn (object $row): bool => (string) $row->status
                    !== ActionStatus::Cancelled->value,
        );

        $completedCount = $validRows->filter(
            static fn (object $row): bool => (string) $row->status
                    === ActionStatus::Completed->value,
        )->count();

        $outstandingCount = $validRows->count() - $completedCount;
        $established = $validRows->isNotEmpty();

        $existingSuggestionKeys = $validRows
            ->pluck('suggestion_key')
            ->filter()
            ->map(static fn (mixed $key): string => (string) $key)
            ->all();

        $suggestions = $canManage
            ? collect($this->workflow->suggestions($context))
                ->reject(
                    static fn (array $suggestion): bool => in_array(
                        $suggestion['key'],
                        $existingSuggestionKeys,
                        true,
                    ),
                )
                ->values()
                ->all()
            : [];

        $approvedPlan = $decisionRecord['approvedPlan'] ?? [];
        $record = $decisionRecord['record'] ?? [];

        return [
            'contractVersion' => CapitalActionPlanContract::READ_MODEL_VERSION,
            'actionPlanContractVersion' => CapitalActionPlanContract::CONTRACT_VERSION,
            'available' => true,
            'established' => $established,
            'canManage' => $canManage,
            'planningChangedSinceApproval' => (bool) (
                $decisionRecord['planningChangedSinceApproval'] ?? false
            ),
            'signatureRequired' => (bool) (
                $decisionRecord['governedApproval']['signatureRequired']
                ?? false
            ),
            'sourceDecisionRecord' => [
                'preferredPlan' => $approvedPlan['preferredPlan'] ?? null,
                'baseCurrency' => $approvedPlan['baseCurrency'] ?? null,
                'totalCapitalRequirement' => $approvedPlan[
                    'totalCapitalRequirement'
                ] ?? null,
                'fundingGap' => $approvedPlan['fundingGap'] ?? null,
                'fundingSurplus' => $approvedPlan['fundingSurplus'] ?? null,
                'shortfallResponses' => $approvedPlan[
                    'capitalRule'
                ]['shortfallResponses'] ?? [],
                'decisionOwner' => $record['decisionOwner'] ?? null,
                'reviewDate' => $record['reviewDate'] ?? null,
                'decisionSummary' => $record['decisionSummary'] ?? null,
            ],
            'defaultOwnerMembershipId' => $canManage
                ? (string) $context['record']->decision_owner_membership_id
                : null,
            'ownerOptions' => $canManage
                ? $this->ownerOptions($business)
                : [],
            'suggestions' => $suggestions,
            'actions' => $actions,
            'outstandingCount' => $outstandingCount,
            'completedCount' => $completedCount,
            'semantics' => $this->semantics($established),
        ];
    }

    /**
     * @return array<string,bool>
     */
    private function semantics(bool $established): array
    {
        return [
            'chapterComplete' => $established,
            'actionPlanTruth' => $established,
            'allActionsCompleteRequired' => false,
            'signedTruth' => false,
            'effectiveTruth' => false,
            'capitalCallExecuted' => false,
            'fundingReceivedTruth' => false,
            'contributionTruth' => false,
            'acceptedContributionTruth' => false,
            'equityTruth' => false,
            'ownershipTruth' => false,
        ];
    }

    /**
     * @return list<array{id:string,name:string}>
     */
    private function ownerOptions(Business $business): array
    {
        return Membership::query()
            ->with(['user.profile'])
            ->where('business_id', $business->getKey())
            ->where('access_status', 'active')
            ->get()
            ->map(fn (Membership $membership): array => [
                'id' => (string) $membership->getKey(),
                'name' => $this->label($membership),
            ])
            ->sortBy('name')
            ->values()
            ->all();
    }

    private function membershipLabel(
        Business $business,
        string $membershipId,
    ): string {
        $membership = Membership::query()
            ->with(['user.profile'])
            ->where('business_id', $business->getKey())
            ->whereKey($membershipId)
            ->first();

        return $membership === null
            ? 'Unavailable member'
            : $this->label($membership);
    }

    private function label(Membership $membership): string
    {
        $displayName = trim((string) (
            $membership->user?->profile?->display_name ?? ''
        ));

        return $displayName !== ''
            ? $displayName
            : (string) (
                $membership->user?->email ?? 'Business member'
            );
    }
}
