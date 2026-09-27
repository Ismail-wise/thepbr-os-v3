<?php

declare(strict_types=1);

namespace App\Application\Conflict;

use App\Application\Operations\CreateOperationsAction;
use App\Infrastructure\Persistence\Eloquent\Businesses\Business;
use App\Infrastructure\Persistence\Eloquent\Governance\Action;
use App\Infrastructure\Persistence\Eloquent\Identity\User;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;

final class CreateConflictAction
{
    public function __construct(
        private readonly ConflictRecordVisibility $visibility,
        private readonly CreateOperationsAction $createOperationsAction,
        private readonly RecordConflictOccurrence $occurrence,
    ) {}

    public function execute(
        User $user,
        Business $business,
        string $caseId,
        string $operationsRoleId,
        string $assignedMembershipId,
        string $title,
        string $sourceType,
        string $sourceId,
        ?string $description = null,
        ?CarbonInterface $dueAt = null,
    ): ?Action {
        if (! $this->visibility->canManage($user, $business, $caseId)) {
            return null;
        }

        if (
            ! Str::isUuid($sourceId)
            || ! $this->sourceBelongsToCase(
                $business,
                $caseId,
                $sourceType,
                $sourceId,
            )
        ) {
            throw new InvalidArgumentException(
                'Conflict Action source must be an exact same-Business case resource.',
            );
        }

        return DB::transaction(function () use (
            $user,
            $business,
            $caseId,
            $operationsRoleId,
            $assignedMembershipId,
            $title,
            $sourceType,
            $sourceId,
            $description,
            $dueAt,
        ): ?Action {
            $action = $this->createOperationsAction->execute(
                $user,
                $business,
                $operationsRoleId,
                $assignedMembershipId,
                $title,
                $description,
                $dueAt,
            );

            if ($action === null) {
                return null;
            }

            $opsLink = DB::table('operations_action_links')
                ->where('business_id', $business->getKey())
                ->where('action_id', $action->getKey())
                ->first();

            if (
                $opsLink === null
                || (string) $opsLink->operations_role_id
                    !== $operationsRoleId
            ) {
                return null;
            }

            DB::table('conflict_action_links')->insert([
                'id' => (string) Str::uuid7(),
                'business_id' => $business->getKey(),
                'conflict_case_id' => $caseId,
                'action_id' => $action->getKey(),
                'operations_formal_record_version_id' => $opsLink->formal_record_version_id,
                'operations_role_id' => $opsLink->operations_role_id,
                'source_type' => $sourceType,
                'source_id' => $sourceId,
                'created_at' => now(),
            ]);

            $this->occurrence->record(
                $user,
                $business,
                'conflict.action.created',
                $caseId,
                [
                    'operations_role_id' => $operationsRoleId,
                    'source_type' => $sourceType,
                ],
                (string) $opsLink->formal_record_version_id,
            );

            return $action->fresh();
        });
    }

    private function sourceBelongsToCase(
        Business $business,
        string $caseId,
        string $sourceType,
        string $sourceId,
    ): bool {
        if ($sourceType === 'conflict_case') {
            return $sourceId === $caseId
                && DB::table('conflict_cases')
                    ->where('business_id', $business->getKey())
                    ->where('id', $caseId)
                    ->exists();
        }

        $table = match ($sourceType) {
            'direct_discussion' => 'conflict_direct_discussions',
            'mediation' => 'conflict_mediations',
            'escalation' => 'conflict_escalations',
            'deadlock' => 'conflict_deadlock_records',
            'investigation' => 'conflict_investigations',
            'urgent_risk' => 'conflict_urgent_risk_records',
            'settlement' => 'conflict_settlement_versions',
            'review' => 'conflict_case_reviews',
            'referral' => 'conflict_exit_legal_referrals',
            default => null,
        };

        if ($table === null) {
            return false;
        }

        return DB::table($table)
            ->where('business_id', $business->getKey())
            ->where('conflict_case_id', $caseId)
            ->where('id', $sourceId)
            ->exists();
    }
}
