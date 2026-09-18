<?php

declare(strict_types=1);

namespace App\Application\Events;

use App\Application\Audit\AppendAuditEvent;
use App\Domain\Audit\ValueObjects\AuditActor;
use App\Domain\Audit\ValueObjects\SafeAuditMetadata;
use App\Domain\Events\ValueObjects\OccurrenceTarget;
use App\Domain\Events\ValueObjects\SafeBusinessEventPayload;
use App\Infrastructure\Persistence\Eloquent\Audit\AuditEvent;
use App\Infrastructure\Persistence\Eloquent\Businesses\Business;
use App\Infrastructure\Persistence\Eloquent\Events\BusinessEvent;
use DateTimeInterface;
use Illuminate\Support\Str;

final class RecordBusinessOccurrence
{
    public function __construct(
        private readonly AppendAuditEvent $appendAuditEvent,
        private readonly AppendBusinessEvent $appendBusinessEvent,
    ) {}

    public function newCorrelationId(): string
    {
        return (string) Str::uuid7();
    }

    public function audit(
        Business $currentBusiness,
        AuditActor $actor,
        string $action,
        OccurrenceTarget $target,
        SafeAuditMetadata $metadata,
        DateTimeInterface $occurredAt,
        ?string $correlationId = null,
    ): AuditEvent {
        return $this->appendAuditEvent->append(
            $currentBusiness,
            $actor,
            $action,
            $target,
            $metadata,
            $occurredAt,
            $correlationId,
        );
    }

    public function businessEvent(
        Business $currentBusiness,
        string $eventType,
        OccurrenceTarget $aggregate,
        OccurrenceTarget $visibilityTarget,
        SafeBusinessEventPayload $payload,
        DateTimeInterface $occurredAt,
        ?AuditActor $actor = null,
        ?string $correlationId = null,
    ): BusinessEvent {
        return $this->appendBusinessEvent->append(
            $currentBusiness,
            $eventType,
            $aggregate,
            $visibilityTarget,
            $payload,
            $occurredAt,
            $actor,
            $correlationId,
        );
    }
}
