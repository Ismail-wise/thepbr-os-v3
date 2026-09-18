<?php

declare(strict_types=1);

namespace App\Application\Audit;

use App\Domain\Audit\ValueObjects\AuditActor;
use App\Domain\Audit\ValueObjects\SafeAuditMetadata;
use App\Domain\Events\ValueObjects\OccurrenceTarget;
use App\Infrastructure\Persistence\Eloquent\Audit\AuditEvent;
use App\Infrastructure\Persistence\Eloquent\Businesses\Business;
use DateTimeInterface;
use InvalidArgumentException;

final class AppendAuditEvent
{
    public function append(
        Business $currentBusiness,
        AuditActor $actor,
        string $action,
        OccurrenceTarget $target,
        SafeAuditMetadata $metadata,
        DateTimeInterface $occurredAt,
        ?string $correlationId = null,
    ): AuditEvent {
        $this->assertBusinessBoundary($currentBusiness, $target);

        if (preg_match('/\A[a-z][a-z0-9_.]{0,127}\z/', $action) !== 1) {
            throw new InvalidArgumentException(
                'Audit action uses an invalid stable identifier.',
            );
        }

        $metadataValues = $metadata->toArray();

        return AuditEvent::query()->create([
            'business_id' => $currentBusiness->getKey(),
            'actor_type' => $actor->type->value,
            'actor_identifier' => $actor->identifier,
            'action' => $action,
            'target_type' => $target->resourceType,
            'target_id' => $target->resourceId,
            'target_version_id' => $target->resourceVersionId,
            'occurred_at' => $occurredAt,
            'correlation_id' => $correlationId,
            'metadata' => $metadataValues === [] ? (object) [] : $metadataValues,
        ]);
    }

    private function assertBusinessBoundary(
        Business $currentBusiness,
        OccurrenceTarget $target,
    ): void {
        if (
            $target->businessId
            !== (string) $currentBusiness->getKey()
        ) {
            throw new InvalidArgumentException(
                'Audit target must belong to the current Business.',
            );
        }
    }
}
