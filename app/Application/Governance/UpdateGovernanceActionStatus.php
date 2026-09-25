<?php

declare(strict_types=1);

namespace App\Application\Governance;

use App\Domain\Access\CapabilityCatalog;
use App\Domain\Governance\Enums\ActionStatus;
use App\Infrastructure\Persistence\Eloquent\Businesses\Business;
use App\Infrastructure\Persistence\Eloquent\Governance\Action;
use App\Infrastructure\Persistence\Eloquent\Identity\User;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

final class UpdateGovernanceActionStatus
{
    public function __construct(
        private readonly GovernanceActorContext $actorContext,
        private readonly RecordGovernanceOccurrence $occurrence,
    ) {}

    public function execute(
        User $user,
        Business $business,
        string $actionId,
        ActionStatus $status,
        ?string $blockedReason = null,
    ): ?Action {
        $membership = $this->actorContext->membership(
            $user,
            $business,
            CapabilityCatalog::GOVERNANCE_ACTION_MANAGE,
        );

        if ($membership === null) {
            return null;
        }

        if ($status === ActionStatus::Blocked) {
            $blockedReason = trim((string) $blockedReason);

            if ($blockedReason === '') {
                throw new InvalidArgumentException(
                    'Blocked Action requires a reason.',
                );
            }
        } else {
            $blockedReason = null;
        }

        return DB::transaction(function () use (
            $user,
            $business,
            $actionId,
            $status,
            $blockedReason,
            $membership,
        ): ?Action {
            $action = Action::query()
                ->where('business_id', $business->getKey())
                ->whereKey($actionId)
                ->lockForUpdate()
                ->first();

            if ($action === null) {
                return null;
            }

            if (! $this->actorContext->canAccessResource(
                $user,
                $business,
                CapabilityCatalog::GOVERNANCE_ACTION_MANAGE,
                Action::class,
                (string) $action->getKey(),
            )) {
                return null;
            }

            if (in_array($action->status, [ActionStatus::Completed, ActionStatus::Cancelled], true)) {
                return null;
            }

            $action->fill([
                'status' => $status->value,
                'blocked_reason' => $blockedReason,
                'completed_at' => $status === ActionStatus::Completed ? now() : null,
            ])->save();

            $this->occurrence->record(
                $user,
                $business,
                'governance.action.status_changed',
                'governance_action',
                (string) $action->getKey(),
                ['status' => $status->value],
            );

            return $action->fresh();
        });
    }
}
