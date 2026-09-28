<?php

declare(strict_types=1);

namespace App\Application\Portability;

use App\Application\Access\AuthorizeBusinessCapability;
use App\Application\Access\ResolveMembershipCapabilities;
use App\Application\Events\RecordBusinessOccurrence;
use App\Domain\Access\CapabilityCatalog;
use App\Domain\Access\ValueObjects\Capability;
use App\Domain\Audit\ValueObjects\AuditActor;
use App\Domain\Audit\ValueObjects\SafeAuditMetadata;
use App\Domain\Businesses\Enums\WorkspaceStatus;
use App\Domain\Events\ValueObjects\OccurrenceTarget;
use App\Domain\Events\ValueObjects\SafeBusinessEventPayload;
use App\Infrastructure\Persistence\Eloquent\Businesses\Business;
use App\Infrastructure\Persistence\Eloquent\Identity\User;
use App\Infrastructure\Persistence\Eloquent\Portability\BusinessArchiveTransition;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use RuntimeException;

final class ChangeWorkspaceArchiveState
{
    public function __construct(
        private readonly ResolveMembershipCapabilities $memberships,
        private readonly AuthorizeBusinessCapability $authorize,
        private readonly RecordBusinessOccurrence $occurrence,
    ) {}

    public function archive(
        User $user,
        Business $business,
        string $reason,
    ): ?Business {
        return $this->transition(
            $user,
            $business,
            true,
            $reason,
        );
    }

    public function unarchive(
        User $user,
        Business $business,
        string $reason,
    ): ?Business {
        return $this->transition(
            $user,
            $business,
            false,
            $reason,
        );
    }

    private function transition(
        User $user,
        Business $business,
        bool $archive,
        string $reason,
    ): ?Business {
        $membership = $this->memberships->activeMembership(
            $user,
            $business,
        );

        if (
            $membership === null
            || ! $this->authorize->decide(
                $user,
                $business,
                $business,
                new Capability(CapabilityCatalog::PORTABILITY_MANAGE),
            )->allowed
        ) {
            return null;
        }

        $reason = trim($reason);

        if ($reason === '' || mb_strlen($reason) > 1000) {
            throw new InvalidArgumentException(
                'Archive state change reason is required and must not exceed 1000 characters.',
            );
        }

        return DB::transaction(function () use (
            $user,
            $business,
            $membership,
            $archive,
            $reason,
        ): Business {
            $locked = Business::query()
                ->whereKey($business->getKey())
                ->lockForUpdate()
                ->firstOrFail();

            $from = $locked->workspace_status;

            if ($from === WorkspaceStatus::Closed) {
                throw new RuntimeException(
                    'A Closed Business cannot be archived or unarchived.',
                );
            }

            if ($archive) {
                if (! in_array(
                    $from,
                    [
                        WorkspaceStatus::Active,
                        WorkspaceStatus::Restricted,
                    ],
                    true,
                )) {
                    throw new RuntimeException(
                        'Only an Active or Restricted Business can be archived.',
                    );
                }

                $to = WorkspaceStatus::Archived;
            } else {
                if ($from !== WorkspaceStatus::Archived) {
                    throw new RuntimeException(
                        'Only an Archived Business can be unarchived.',
                    );
                }

                $lastArchive = BusinessArchiveTransition::query()
                    ->where('business_id', $locked->getKey())
                    ->where('to_status', WorkspaceStatus::Archived->value)
                    ->orderByDesc('occurred_at')
                    ->orderByDesc('created_at')
                    ->first();

                if (
                    $lastArchive === null
                    || ! in_array(
                        $lastArchive->from_status,
                        [
                            WorkspaceStatus::Active,
                            WorkspaceStatus::Restricted,
                        ],
                        true,
                    )
                ) {
                    throw new RuntimeException(
                        'Archived Business lacks a valid archive transition to restore.',
                    );
                }

                $to = $lastArchive->from_status;
            }

            $locked->workspace_status = $to;
            $locked->save();

            BusinessArchiveTransition::query()->create([
                'business_id' => $locked->getKey(),
                'actor_membership_id' => $membership->getKey(),
                'from_status' => $from,
                'to_status' => $to,
                'reason' => $reason,
                'occurred_at' => now(),
            ]);

            $this->recordOccurrence(
                $user,
                $locked,
                $archive
                    ? 'portability.workspace.archived'
                    : 'portability.workspace.unarchived',
                [
                    'from_status' => $from->value,
                    'to_status' => $to->value,
                ],
            );

            return $locked->fresh();
        });
    }

    /**
     * @param  array<string,bool|float|int|string|null>  $metadata
     */
    private function recordOccurrence(
        User $user,
        Business $business,
        string $action,
        array $metadata,
    ): void {
        $at = now();
        $correlationId = $this->occurrence->newCorrelationId();
        $actor = AuditActor::user((string) $user->getKey());
        $target = new OccurrenceTarget(
            (string) $business->getKey(),
            'business',
            (string) $business->getKey(),
        );

        $this->occurrence->audit(
            $business,
            $actor,
            $action,
            $target,
            SafeAuditMetadata::from($metadata),
            $at,
            $correlationId,
        );

        $this->occurrence->businessEvent(
            $business,
            $action,
            $target,
            $target,
            SafeBusinessEventPayload::from($metadata),
            $at,
            $actor,
            $correlationId,
        );
    }
}
