<?php

declare(strict_types=1);

namespace App\Application\Governance;

use App\Domain\Access\CapabilityCatalog;
use App\Domain\Governance\Enums\ActionStatus;
use App\Infrastructure\Persistence\Eloquent\Businesses\Business;
use App\Infrastructure\Persistence\Eloquent\Governance\Action;
use App\Infrastructure\Persistence\Eloquent\Governance\Decision;
use App\Infrastructure\Persistence\Eloquent\Identity\User;
use App\Infrastructure\Persistence\Eloquent\Members\Membership;
use App\Infrastructure\Persistence\Eloquent\Records\FormalRecordVersion;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

final class CreateGovernanceAction
{
    public function __construct(
        private readonly GovernanceActorContext $actorContext,
        private readonly CreateGovernanceNotification $notifications,
        private readonly RecordGovernanceOccurrence $occurrence,
    ) {}

    public function execute(
        User $user,
        Business $business,
        string $assignedMembershipId,
        string $title,
        ?string $decisionId = null,
        ?string $formalRecordVersionId = null,
        ?string $description = null,
        ?CarbonInterface $dueAt = null,
    ): ?Action {
        $creator = $this->actorContext->membership(
            $user,
            $business,
            CapabilityCatalog::GOVERNANCE_ACTION_MANAGE,
        );

        if ($creator === null) {
            return null;
        }

        $title = trim($title);

        if ($title === '') {
            throw new InvalidArgumentException('Action title is required.');
        }

        if ($decisionId === null && $formalRecordVersionId === null) {
            throw new InvalidArgumentException(
                'Action requires a Decision or Effective Formal Record source.',
            );
        }

        return DB::transaction(function () use (
            $user,
            $business,
            $assignedMembershipId,
            $title,
            $decisionId,
            $formalRecordVersionId,
            $description,
            $dueAt,
            $creator,
        ): ?Action {
            $assignee = Membership::query()
                ->where('business_id', $business->getKey())
                ->whereKey($assignedMembershipId)
                ->where('access_status', 'active')
                ->first();

            if ($assignee === null) {
                return null;
            }

            if ($decisionId !== null) {
                $decision = Decision::query()
                    ->where('business_id', $business->getKey())
                    ->whereKey($decisionId)
                    ->first();

                if (
                    $decision === null
                    || ! $this->actorContext->canAccessResource(
                        $user,
                        $business,
                        CapabilityCatalog::GOVERNANCE_ACTION_MANAGE,
                        Decision::class,
                        (string) $decision->getKey(),
                    )
                ) {
                    return null;
                }
            }

            if ($formalRecordVersionId !== null) {
                $version = FormalRecordVersion::query()
                    ->where('business_id', $business->getKey())
                    ->whereKey($formalRecordVersionId)
                    ->first();

                if (
                    $version === null
                    || ! $this->actorContext->canAccessResource(
                        $user,
                        $business,
                        CapabilityCatalog::GOVERNANCE_ACTION_MANAGE,
                        FormalRecordVersion::class,
                        (string) $version->getKey(),
                    )
                ) {
                    return null;
                }
            }

            $action = Action::query()->create([
                'business_id' => $business->getKey(),
                'decision_id' => $decisionId,
                'formal_record_version_id' => $formalRecordVersionId,
                'assigned_membership_id' => $assignee->getKey(),
                'created_by_membership_id' => $creator->getKey(),
                'title' => $title,
                'description' => $description,
                'status' => ActionStatus::Open->value,
                'blocked_reason' => null,
                'due_at' => $dueAt,
                'completed_at' => null,
            ]);

            $this->notifications->execute(
                $business,
                (string) $assignee->getKey(),
                'governance.action.assigned',
                'governance_action',
                (string) $action->getKey(),
            );

            $this->occurrence->record(
                $user,
                $business,
                'governance.action.created',
                'governance_action',
                (string) $action->getKey(),
                [
                    'status' => ActionStatus::Open->value,
                    'assigned_membership_id' => (string) $assignee->getKey(),
                ],
            );

            return $action;
        });
    }
}
