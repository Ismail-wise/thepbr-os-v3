<?php

declare(strict_types=1);

namespace App\Application\PartnerChanges;

use App\Application\Events\RecordBusinessOccurrence;
use App\Domain\Audit\ValueObjects\AuditActor;
use App\Domain\Audit\ValueObjects\SafeAuditMetadata;
use App\Domain\Events\ValueObjects\OccurrenceTarget;
use App\Domain\Events\ValueObjects\SafeBusinessEventPayload;
use App\Infrastructure\Persistence\Eloquent\Businesses\Business;
use App\Infrastructure\Persistence\Eloquent\Identity\User;

final class RecordPartnerChangeOccurrence
{
    public function __construct(
        private readonly RecordBusinessOccurrence $occurrence,
    ) {}

    /** @param array<string, bool|float|int|string|null> $metadata */
    public function record(
        User $user,
        Business $business,
        string $action,
        string $targetType,
        string $targetId,
        array $metadata = [],
    ): void {
        $at = now();
        $correlationId = $this->occurrence->newCorrelationId();
        $actor = AuditActor::user((string) $user->getKey());
        $target = new OccurrenceTarget(
            (string) $business->getKey(),
            $targetType,
            $targetId,
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
