<?php

declare(strict_types=1);

namespace App\Application\Activity;

use App\Application\Access\AuthorizeBusinessCapability;
use App\Domain\Access\ValueObjects\Capability;
use App\Infrastructure\Persistence\Eloquent\Businesses\Business;
use App\Infrastructure\Persistence\Eloquent\Events\BusinessEvent;
use App\Infrastructure\Persistence\Eloquent\Identity\User;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Crypt;
use InvalidArgumentException;

final class ListAuthorizedBusinessActivity
{
    private const array TIMELINE_EVENT_TYPES = [
        'records.formal_record_version.review_frozen',
        'records.formal_record_version.state_changed',
        'records.formal_record_effective_head.changed',
        'records.formal_record_version.superseded',
        'records.proposal_version.frozen',
    ];

    private const int DEFAULT_PAGE_SIZE = 25;

    public function __construct(
        private readonly AuthorizeBusinessCapability $authorizeBusinessCapability,
        private readonly ActivityTargetRegistry $activityTargetRegistry,
    ) {}

    /**
     * @return array{
     *   items: array<int, array{
     *     eventType: string,
     *     actorType: string,
     *     isCurrentUser: bool,
     *     occurredAt: string,
     *     versionNumber?: int,
     *     fromState?: string,
     *     toState?: string
     *   }>,
     *   nextCursor: string|null
     * }|null
     */
    public function execute(
        User $user,
        Business $currentBusiness,
        Capability $capability,
        ?string $cursor = null,
        int $pageSize = self::DEFAULT_PAGE_SIZE,
    ): ?array {
        $baseDecision = $this->authorizeBusinessCapability->decide(
            $user,
            $currentBusiness,
            $currentBusiness,
            $capability,
        );

        if (! $baseDecision->allowed) {
            return null;
        }

        $pageSize = max(1, min($pageSize, 50));
        $scanCursor = $this->decodeCursor($cursor);
        $items = [];

        while (count($items) < $pageSize) {
            $query = BusinessEvent::query()
                ->where('business_id', $currentBusiness->getKey())
                ->whereIn('event_type', self::TIMELINE_EVENT_TYPES)
                ->orderByDesc('occurred_at')
                ->orderByDesc('id')
                ->limit(50);

            if ($scanCursor !== null) {
                $query->where(function ($builder) use ($scanCursor): void {
                    $builder
                        ->where(
                            'occurred_at',
                            '<',
                            $scanCursor['occurred_at'],
                        )
                        ->orWhere(function ($sameTime) use ($scanCursor): void {
                            $sameTime
                                ->where(
                                    'occurred_at',
                                    '=',
                                    $scanCursor['occurred_at'],
                                )
                                ->where('id', '<', $scanCursor['id']);
                        });
                });
            }

            /** @var Collection<int, BusinessEvent> $events */
            $events = $query->get();

            if ($events->isEmpty()) {
                return [
                    'items' => $items,
                    'nextCursor' => null,
                ];
            }

            foreach ($events as $event) {
                $scanCursor = [
                    'occurred_at' => $event->occurred_at->toISOString(),
                    'id' => (string) $event->getKey(),
                ];

                $resource = $this->activityTargetRegistry->find(
                    $currentBusiness,
                    (string) $event->visibility_resource_type,
                    (string) $event->visibility_resource_id,
                );

                if ($resource === null) {
                    continue;
                }

                $resourceClass =
                    $this->activityTargetRegistry->resourceClass(
                        (string) $event->visibility_resource_type,
                    );

                if ($resourceClass === null) {
                    continue;
                }

                $decision = $this->authorizeBusinessCapability->decide(
                    $user,
                    $currentBusiness,
                    $currentBusiness,
                    $capability,
                    $resourceClass,
                    (string) $resource->getKey(),
                );

                if (! $decision->allowed) {
                    continue;
                }

                $items[] = $this->safeItem($event, $user);

                if (count($items) === $pageSize) {
                    return [
                        'items' => $items,
                        'nextCursor' => $this->encodeCursor($scanCursor),
                    ];
                }
            }

            if ($events->count() < 50) {
                return [
                    'items' => $items,
                    'nextCursor' => null,
                ];
            }
        }

        return [
            'items' => $items,
            'nextCursor' => $scanCursor === null
                ? null
                : $this->encodeCursor($scanCursor),
        ];
    }

    /**
     * @return array{
     *   eventType: string,
     *   actorType: string,
     *   isCurrentUser: bool,
     *   occurredAt: string,
     *   versionNumber?: int,
     *   fromState?: string,
     *   toState?: string
     * }
     */
    private function safeItem(BusinessEvent $event, User $user): array
    {
        $payload = is_array($event->payload) ? $event->payload : [];

        $item = [
            'eventType' => (string) $event->event_type,
            'actorType' => $event->actor_type?->value ?? 'system',
            'isCurrentUser' => (
                $event->actor_type?->value === 'user'
                && (string) $event->actor_identifier
                    === (string) $user->getKey()
            ),
            'occurredAt' => $event->occurred_at->toISOString(),
        ];

        if (is_int($payload['version_number'] ?? null)) {
            $item['versionNumber'] = $payload['version_number'];
        }

        if (is_string($payload['from_state'] ?? null)) {
            $item['fromState'] = $payload['from_state'];
        }

        if (is_string($payload['to_state'] ?? null)) {
            $item['toState'] = $payload['to_state'];
        }

        return $item;
    }

    /**
     * @return array{occurred_at: string, id: string}|null
     */
    private function decodeCursor(?string $cursor): ?array
    {
        if ($cursor === null || $cursor === '') {
            return null;
        }

        try {
            $decoded = json_decode(
                Crypt::decryptString($cursor),
                true,
                16,
                JSON_THROW_ON_ERROR,
            );
        } catch (\Throwable) {
            throw new InvalidArgumentException(
                'Activity cursor is invalid.',
            );
        }

        if (
            ! is_array($decoded)
            || ! is_string($decoded['occurred_at'] ?? null)
            || ! is_string($decoded['id'] ?? null)
        ) {
            throw new InvalidArgumentException(
                'Activity cursor is invalid.',
            );
        }

        try {
            Carbon::parse($decoded['occurred_at']);
        } catch (\Throwable) {
            throw new InvalidArgumentException(
                'Activity cursor is invalid.',
            );
        }

        return [
            'occurred_at' => $decoded['occurred_at'],
            'id' => $decoded['id'],
        ];
    }

    /**
     * @param  array{occurred_at: string, id: string}  $cursor
     */
    private function encodeCursor(array $cursor): string
    {
        return Crypt::encryptString(
            json_encode(
                $cursor,
                JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES,
            ),
        );
    }
}
