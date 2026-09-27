<?php

declare(strict_types=1);

namespace App\Application\Operations;

use App\Application\Governance\GovernanceActorContext;
use App\Application\Governance\RecordGovernanceOccurrence;
use App\Domain\Access\CapabilityCatalog;
use App\Domain\Governance\Enums\ActionStatus;
use App\Infrastructure\Persistence\Eloquent\Businesses\Business;
use App\Infrastructure\Persistence\Eloquent\Governance\Action;
use App\Infrastructure\Persistence\Eloquent\Identity\User;
use App\Infrastructure\Persistence\Eloquent\Members\Membership;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;

final class CreateOperationsAction
{
    public function __construct(
        private readonly GovernanceActorContext $actorContext,
        private readonly RecordGovernanceOccurrence $occurrence,
    ) {}

    public function execute(
        User $user,
        Business $business,
        string $operationsRoleId,
        string $assignedMembershipId,
        string $title,
        ?string $description = null,
        ?CarbonInterface $dueAt = null,
    ): ?Action {
        $creator = $this->actorContext->membership(
            $user,
            $business,
            CapabilityCatalog::OPERATIONS_MANAGE,
        );

        if ($creator === null) {
            return null;
        }

        $title = trim($title);

        if ($title === '') {
            throw new InvalidArgumentException(
                'Operations Action title is required.',
            );
        }

        return DB::transaction(function () use (
            $user,
            $business,
            $creator,
            $operationsRoleId,
            $assignedMembershipId,
            $title,
            $description,
            $dueAt,
        ): ?Action {
            $head = DB::table('record_family_effective_heads as h')
                ->join(
                    'formal_record_versions as v',
                    function ($join): void {
                        $join
                            ->on(
                                'v.id',
                                '=',
                                'h.formal_record_version_id',
                            )
                            ->on(
                                'v.business_id',
                                '=',
                                'h.business_id',
                            );
                    },
                )
                ->join(
                    'formal_record_families as f',
                    function ($join): void {
                        $join
                            ->on(
                                'f.id',
                                '=',
                                'v.formal_record_family_id',
                            )
                            ->on(
                                'f.business_id',
                                '=',
                                'v.business_id',
                            );
                    },
                )
                ->where('h.business_id', $business->getKey())
                ->where('f.record_type', 'operations_register')
                ->lockForUpdate()
                ->first([
                    'h.formal_record_version_id',
                ]);

            if ($head === null) {
                return null;
            }

            $versionId = (string) $head->formal_record_version_id;

            $role = DB::table('operations_roles')
                ->where('business_id', $business->getKey())
                ->where('formal_record_version_id', $versionId)
                ->where('id', $operationsRoleId)
                ->where('status', 'active')
                ->first();

            if ($role === null) {
                return null;
            }

            $assignedToRole = DB::table(
                'operations_role_assignments',
            )
                ->where('business_id', $business->getKey())
                ->where('formal_record_version_id', $versionId)
                ->where('operations_role_id', $operationsRoleId)
                ->where('membership_id', $assignedMembershipId)
                ->exists();

            $activeMembership = Membership::query()
                ->where('business_id', $business->getKey())
                ->whereKey($assignedMembershipId)
                ->where('access_status', 'active')
                ->exists();

            if (! $assignedToRole || ! $activeMembership) {
                return null;
            }

            $action = Action::query()->create([
                'business_id' => $business->getKey(),
                'decision_id' => null,
                'formal_record_version_id' => $versionId,
                'assigned_membership_id' => $assignedMembershipId,
                'created_by_membership_id' => $creator->getKey(),
                'title' => $title,
                'description' => $description,
                'status' => ActionStatus::Open->value,
                'blocked_reason' => null,
                'due_at' => $dueAt,
                'completed_at' => null,
            ]);

            DB::table('operations_action_links')->insert([
                'id' => (string) Str::uuid7(),
                'business_id' => $business->getKey(),
                'action_id' => $action->getKey(),
                'formal_record_version_id' => $versionId,
                'operations_role_id' => $operationsRoleId,
                'created_at' => now(),
            ]);

            $this->occurrence->record(
                $user,
                $business,
                'operations.action.created',
                'operations_action',
                (string) $action->getKey(),
                [
                    'assigned_membership_id' => $assignedMembershipId,
                    'operations_role_id' => $operationsRoleId,
                ],
                $versionId,
            );

            return $action->fresh();
        });
    }
}
