<?php

declare(strict_types=1);

namespace App\Application\Governance;

use App\Application\Events\RecordBusinessOccurrence;
use App\Domain\Audit\ValueObjects\AuditActor;
use App\Domain\Audit\ValueObjects\SafeAuditMetadata;
use App\Domain\Events\ValueObjects\OccurrenceTarget;
use App\Domain\Events\ValueObjects\SafeBusinessEventPayload;
use App\Infrastructure\Persistence\Eloquent\Businesses\Business;
use App\Infrastructure\Persistence\Eloquent\Identity\User;

final class RecordGovernanceOccurrence
{
    public function __construct(
        private readonly RecordBusinessOccurrence $occurrences,
    ) {}

    /**
     * @param  array<string, bool|float|int|string|null>  $metadata
     */
    public function record(
        User $user,
        Business $business,
        string $type,
        string $resourceType,
        string $resourceId,
        array $metadata = [],
        ?string $resourceVersionId = null,
    ): void {
        $at = now();
        $correlation = $this->occurrences->newCorrelationId();
        $actor = AuditActor::user((string) $user->getKey());
        $target = new OccurrenceTarget(
            (string) $business->getKey(),
            $resourceType,
            $resourceId,
            $resourceVersionId,
        );

        $this->occurrences->audit(
            $business,
            $actor,
            $type,
            $target,
            SafeAuditMetadata::from($metadata),
            $at,
            $correlation,
        );

        $this->occurrences->businessEvent(
            $business,
            $type,
            $target,
            new OccurrenceTarget(
                (string) $business->getKey(),
                $resourceType,
                $resourceId,
            ),
            SafeBusinessEventPayload::from($metadata),
            $at,
            $actor,
            $correlation,
        );
    }
}
