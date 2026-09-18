<?php

declare(strict_types=1);

namespace App\Application\Events;

use App\Domain\Audit\ValueObjects\AuditActor;
use App\Domain\Events\ValueObjects\OccurrenceTarget;
use App\Domain\Events\ValueObjects\SafeBusinessEventPayload;
use App\Infrastructure\Persistence\Eloquent\Businesses\Business;
use App\Infrastructure\Persistence\Eloquent\Events\BusinessEvent;
use DateTimeInterface;
use InvalidArgumentException;

final class AppendBusinessEvent
{
    public function append(
        Business $currentBusiness,
        string $eventType,
        OccurrenceTarget $aggregate,
        OccurrenceTarget $visibilityTarget,
        SafeBusinessEventPayload $payload,
        DateTimeInterface $occurredAt,
        ?AuditActor $actor = null,
        ?string $correlationId = null,
    ): BusinessEvent {
        $this->assertBusinessBoundary($currentBusiness, $aggregate);
        $this->assertBusinessBoundary($currentBusiness, $visibilityTarget);

        if (
            preg_match('/\A[a-z][a-z0-9_.]{0,159}\z/', $eventType) !== 1
        ) {
            throw new InvalidArgumentException(
                'Business event type uses an invalid stable identifier.',
            );
        }

        $payloadValues = $payload->toArray();

        return BusinessEvent::query()->create([
            'business_id' => $currentBusiness->getKey(),
            'event_type' => $eventType,
            'aggregate_type' => $aggregate->resourceType,
            'aggregate_id' => $aggregate->resourceId,
            'aggregate_version_id' => $aggregate->resourceVersionId,
            'actor_type' => $actor?->type->value,
            'actor_identifier' => $actor?->identifier,
            'visibility_resource_type' => $visibilityTarget->resourceType,
            'visibility_resource_id' => $visibilityTarget->resourceId,
            'occurred_at' => $occurredAt,
            'correlation_id' => $correlationId,
            'payload' => $payloadValues === [] ? (object) [] : $payloadValues,
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
                'Business-event target must belong to the current Business.',
            );
        }
    }
}
