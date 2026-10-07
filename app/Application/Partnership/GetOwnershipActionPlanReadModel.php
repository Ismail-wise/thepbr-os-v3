<?php

declare(strict_types=1);

namespace App\Application\Partnership;

use App\Application\Governance\GovernanceActorContext;
use App\Domain\Access\CapabilityCatalog;
use App\Domain\Governance\Enums\ActionStatus;
use App\Domain\Partnership\OwnershipActionPlanContract;
use App\Infrastructure\Persistence\Eloquent\Businesses\Business;
use App\Infrastructure\Persistence\Eloquent\Governance\Action;
use App\Infrastructure\Persistence\Eloquent\Identity\User;
use App\Infrastructure\Persistence\Eloquent\Members\Membership;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

final class GetOwnershipActionPlanReadModel
{
    public function __construct(
        private readonly PartnershipActorContext $actor,
        private readonly GovernanceActorContext $governanceActor,
        private readonly GetOwnershipActionPlanSource $source,
        private readonly OwnershipActionPlanWorkflow $workflow,
    ) {}

    /** @return array<string,mixed>|null */
    public function execute(User $user, Business $business): ?array
    {
        if (! $this->actor->allows($user, $business, CapabilityCatalog::OWNERSHIP_VIEW)) {
            return null;
        }

        $context = $this->source->execute($business);
        if ($context === null) {
            return [
                'contractVersion' => OwnershipActionPlanContract::READ_MODEL_VERSION,
                'actionPlanContractVersion' => OwnershipActionPlanContract::CONTRACT_VERSION,
                'available' => false,
                'canManage' => false,
                'ownerOptions' => [],
                'suggestions' => [],
                'actions' => [],
                'outstandingCount' => 0,
                'completedCount' => 0,
                'zeroActionsAllowed' => true,
            ];
        }

        $canManage = $this->actor->allows($user, $business, CapabilityCatalog::OWNERSHIP_MANAGE)
            && $this->actor->allows($user, $business, CapabilityCatalog::GOVERNANCE_ACTION_MANAGE);

        $rows = DB::table('ownership_action_links as link')
            ->join('actions as action', function ($join): void {
                $join->on('action.id', '=', 'link.action_id')
                    ->on('action.business_id', '=', 'link.business_id');
            })
            ->where('link.business_id', $business->getKey())
            ->where('link.ownership_decision_record_id', $context['record']->id)
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

        $visible = $rows->filter(
            fn (object $row): bool => $this->governanceActor->canAccessResource(
                $user,
                $business,
                CapabilityCatalog::GOVERNANCE_ACTION_MANAGE,
                Action::class,
                (string) $row->id,
            ),
        );

        $actions = $visible->map(fn (object $row): array => [
            'id' => (string) $row->id,
            'title' => (string) $row->title,
            'description' => $row->description === null ? null : (string) $row->description,
            'status' => (string) $row->status,
            'blockedReason' => $row->blocked_reason === null ? null : (string) $row->blocked_reason,
            'owner' => $this->membershipLabel($business, (string) $row->assigned_membership_id),
            'dueDate' => $row->due_at === null
                ? null
                : CarbonImmutable::parse((string) $row->due_at)->format('Y-m-d'),
            'completedAt' => $row->completed_at === null ? null : (string) $row->completed_at,
            'createdAt' => (string) $row->created_at,
            'suggestionKey' => $row->suggestion_key === null ? null : (string) $row->suggestion_key,
            'canUpdate' => $canManage,
        ])->values()->all();

        $valid = $rows->filter(
            static fn (object $row): bool => (string) $row->status !== ActionStatus::Cancelled->value,
        );
        $completed = $valid->filter(
            static fn (object $row): bool => (string) $row->status === ActionStatus::Completed->value,
        )->count();

        $existingKeys = $valid->pluck('suggestion_key')->filter()->map(
            static fn (mixed $key): string => (string) $key,
        )->all();

        $suggestions = $canManage
            ? collect($this->workflow->suggestions($business, $context))
                ->reject(static fn (array $row): bool => in_array($row['key'], $existingKeys, true))
                ->values()
                ->all()
            : [];

        return [
            'contractVersion' => OwnershipActionPlanContract::READ_MODEL_VERSION,
            'actionPlanContractVersion' => OwnershipActionPlanContract::CONTRACT_VERSION,
            'available' => true,
            'canManage' => $canManage,
            'defaultOwnerMembershipId' => $canManage
                ? (string) $context['record']->decision_owner_membership_id
                : null,
            'ownerOptions' => $canManage ? $this->ownerOptions($business) : [],
            'suggestions' => $suggestions,
            'actions' => $actions,
            'outstandingCount' => $valid->count() - $completed,
            'completedCount' => $completed,
            'zeroActionsAllowed' => true,
            'semantics' => [
                'actionChangesOwnership' => false,
                'actionIssuesShares' => false,
                'actionVestsShares' => false,
                'actionChangesRights' => false,
                'actionApprovesDecision' => false,
                'actionCreatesSignature' => false,
                'actionCreatesEffectivity' => false,
            ],
        ];
    }

    /** @return list<array{id:string,name:string}> */
    private function ownerOptions(Business $business): array
    {
        return Membership::query()
            ->with(['user.profile'])
            ->where('business_id', $business->getKey())
            ->where('access_status', 'active')
            ->get()
            ->map(fn (Membership $m): array => [
                'id' => (string) $m->getKey(),
                'name' => $this->label($m),
            ])
            ->sortBy('name')
            ->values()
            ->all();
    }

    private function membershipLabel(Business $business, string $id): string
    {
        $membership = Membership::query()
            ->with(['user.profile'])
            ->where('business_id', $business->getKey())
            ->whereKey($id)
            ->first();

        return $membership === null ? 'Unavailable member' : $this->label($membership);
    }

    private function label(Membership $membership): string
    {
        $display = trim((string) ($membership->user?->profile?->display_name ?? ''));

        return $display !== '' ? $display : (string) ($membership->user?->email ?? 'Business member');
    }
}
