<?php

declare(strict_types=1);

namespace App\Application\Dashboard;

use App\Application\Activity\ListAuthorizedBusinessActivity;
use App\Application\Governance\GetGovernanceCommandCenter;
use App\Application\Health\GetBusinessHealth;
use App\Domain\Access\ValueObjects\Capability;
use App\Infrastructure\Persistence\Eloquent\Businesses\Business;
use App\Infrastructure\Persistence\Eloquent\Identity\User;

final class GetBusinessControlCenter
{
    public function __construct(
        private readonly GetBusinessHealth $health,
        private readonly GetGovernanceCommandCenter $governance,
        private readonly ListAuthorizedBusinessActivity $activity,
    ) {}

    /**
     * Presentation-only composition of existing authorized read models.
     *
     * No canonical truth is created or mutated here.
     *
     * @return array<string,mixed>
     */
    public function execute(User $user, Business $business): array
    {
        $health = $this->health->execute($user, $business);
        $governance = $this->governance->execute($user, $business);
        $activity = $this->activity->execute(
            $user,
            $business,
            new Capability('records.activity.view'),
            null,
            5,
        );

        $requirements = $this->safeHealthRequirements($health);
        $governanceSummary = $governance['summary'] ?? null;

        return [
            'business' => [
                'name' => (string) $business->name,
                'stage' => $business->business_stage->value,
                'setupPhase' => $business->setup_phase?->value,
                'workspaceStatus' => $business->workspace_status->value,
                'baseCurrency' => (string) $business->base_currency,
            ],
            'attention' => $this->attention(
                $requirements,
                $governance,
            ),
            'health' => $health === null
                ? null
                : [
                    'summary' => [
                        'ready' => (int) $health['summary']['met'],
                        'review' => (int) $health['summary']['warning'],
                        'blocked' => (int) $health['summary']['blocked'],
                        'setupNeeded' => (int) $health['summary']['unknown'],
                    ],
                    'requirements' => $requirements,
                    'currentEffectiveCount' => collect($requirements)
                        ->where('isCurrentEffective', true)
                        ->count(),
                ],
            'governance' => $governanceSummary === null
                ? null
                : [
                    'summary' => $governanceSummary,
                ],
            'nextActions' => $this->nextActions(
                $requirements,
                $governance,
            ),
            'upcoming' => $this->upcoming($governance),
            'recentActivity' => $activity === null
                ? null
                : [
                    'items' => collect($activity['items'])
                        ->map(fn (array $row): array => $this->safeActivity($row))
                        ->values()
                        ->all(),
                ],
        ];
    }

    /**
     * @param  array<string,mixed>|null  $health
     * @return list<array{
     *   area:string,
     *   status:string,
     *   isCurrentEffective:bool,
     *   route:string|null
     * }>
     */
    private function safeHealthRequirements(?array $health): array
    {
        if ($health === null) {
            return [];
        }

        return collect($health['requirements'])
            ->map(static function (array $row): array {
                $state = (string) $row['state'];
                $reasonCode = (string) $row['reason_code'];
                $isCurrentEffective =
                    $reasonCode === 'current_effective_source';

                $status = $isCurrentEffective
                    ? 'current_effective'
                    : match ($state) {
                        'met' => 'ready',
                        'warning' => 'review',
                        'blocked' => 'blocked',
                        default => 'setup_needed',
                    };

                return [
                    'area' => (string) $row['key'],
                    'status' => $status,
                    'isCurrentEffective' => $isCurrentEffective,
                    'route' => is_string($row['route'] ?? null)
                        ? $row['route']
                        : null,
                ];
            })
            ->values()
            ->all();
    }

    /**
     * @param  list<array<string,mixed>>  $requirements
     * @param  array<string,mixed>|null  $governance
     * @return list<array{key:string,count:int,route:string,tone:string}>
     */
    private function attention(
        array $requirements,
        ?array $governance,
    ): array {
        $items = collect();

        foreach ($requirements as $requirement) {
            if (! in_array(
                $requirement['status'],
                ['blocked', 'review'],
                true,
            )) {
                continue;
            }

            $items->push([
                'key' => 'health.'.$requirement['area'],
                'count' => 1,
                'route' => $requirement['route'] ?? '/health',
                'tone' => $requirement['status'] === 'blocked'
                    ? 'danger'
                    : 'warning',
            ]);
        }

        if ($governance !== null) {
            $governanceItems = [
                [
                    'key' => 'governance.proposals',
                    'count' => collect($governance['proposalVersions'] ?? [])
                        ->filter(
                            static fn (array $row): bool => (bool) (
                                ($row['canCompleteReview'] ?? false)
                                || ($row['canOpenDecision'] ?? false)
                            ),
                        )
                        ->count(),
                ],
                [
                    'key' => 'governance.decisions',
                    'count' => collect($governance['decisions'] ?? [])
                        ->filter(
                            static fn (array $row): bool => (bool) (
                                ($row['actions']['canApprove'] ?? false)
                                || ($row['actions']['canVote'] ?? false)
                                || ($row['actions']['canResolve'] ?? false)
                            ),
                        )
                        ->count(),
                ],
                [
                    'key' => 'governance.signatures',
                    'count' => collect($governance['signatureRequests'] ?? [])
                        ->filter(
                            static fn (array $row): bool => (bool) (
                                ($row['canSign'] ?? false)
                                || ($row['canComplete'] ?? false)
                                || ($row['canSend'] ?? false)
                            ),
                        )
                        ->count(),
                ],
                [
                    'key' => 'governance.actions',
                    'count' => collect($governance['actions'] ?? [])
                        ->filter(
                            static fn (array $row): bool => (bool) (
                                ($row['canManage'] ?? false)
                                && ! in_array(
                                    $row['status'] ?? null,
                                    ['completed', 'cancelled'],
                                    true,
                                )
                            ),
                        )
                        ->count(),
                ],
                [
                    'key' => 'governance.reviews',
                    'count' => collect($governance['reviews'] ?? [])
                        ->where('canComplete', true)
                        ->count(),
                ],
            ];

            foreach ($governanceItems as $item) {
                if ($item['count'] < 1) {
                    continue;
                }

                $items->push([
                    ...$item,
                    'route' => '/governance',
                    'tone' => 'action',
                ]);
            }
        }

        return $items
            ->take(6)
            ->values()
            ->all();
    }

    /**
     * @param  list<array<string,mixed>>  $requirements
     * @param  array<string,mixed>|null  $governance
     * @return list<array{
     *   key:string,
     *   count:int,
     *   route:string,
     *   state:string
     * }>
     */
    private function nextActions(
        array $requirements,
        ?array $governance,
    ): array {
        $items = collect();

        foreach ($this->attention([], $governance) as $item) {
            $items->push([
                'key' => $item['key'],
                'count' => $item['count'],
                'route' => $item['route'],
                'state' => 'action',
            ]);
        }

        collect($requirements)
            ->whereIn('status', ['blocked', 'review', 'setup_needed'])
            ->sortBy(
                static fn (array $row): int => match ($row['status']) {
                    'blocked' => 0,
                    'review' => 1,
                    default => 2,
                },
            )
            ->each(function (array $row) use ($items): void {
                $items->push([
                    'key' => 'health.'.$row['area'],
                    'count' => 1,
                    'route' => $row['route'] ?? '/health',
                    'state' => (string) $row['status'],
                ]);
            });

        return $items
            ->unique(
                static fn (array $item): string => $item['key'].'|'.$item['route'],
            )
            ->take(4)
            ->values()
            ->all();
    }

    /**
     * @param  array<string,mixed>  $row
     * @return array{
     *   kind:string,
     *   actor:string,
     *   occurredAt:string
     * }
     */
    private function safeActivity(array $row): array
    {
        $kind = match ((string) ($row['eventType'] ?? '')) {
            'records.formal_record_version.review_frozen' => 'review_frozen',
            'records.formal_record_version.state_changed' => 'record_state_changed',
            'records.formal_record_effective_head.changed' => 'current_effective_changed',
            'records.formal_record_version.superseded' => 'record_superseded',
            'records.proposal_version.frozen' => 'proposal_frozen',
            default => 'business_record_activity',
        };

        $actor = ($row['isCurrentUser'] ?? false) === true
            ? 'you'
            : ((string) ($row['actorType'] ?? 'system') === 'system'
                ? 'system'
                : 'member');

        return [
            'kind' => $kind,
            'actor' => $actor,
            'occurredAt' => (string) $row['occurredAt'],
        ];
    }

    /**
     * @param  array<string,mixed>|null  $governance
     * @return list<array{
     *   kind:string,
     *   title:string|null,
     *   dueAt:string,
     *   route:string
     * }>
     */
    private function upcoming(?array $governance): array
    {
        if ($governance === null) {
            return [];
        }

        $items = collect();

        collect($governance['actions'] ?? [])
            ->filter(
                static fn (array $row): bool => is_string(
                    $row['dueAt'] ?? null,
                ) && ! in_array(
                    $row['status'] ?? null,
                    ['completed', 'cancelled'],
                    true,
                ),
            )
            ->each(function (array $row) use ($items): void {
                $items->push([
                    'kind' => 'action',
                    'title' => is_string($row['title'] ?? null)
                        ? $row['title']
                        : null,
                    'dueAt' => $row['dueAt'],
                    'route' => '/governance',
                ]);
            });

        collect($governance['reviews'] ?? [])
            ->filter(
                static fn (array $row): bool => ($row['status'] ?? null)
                    === 'open'
                    && is_string($row['dueAt'] ?? null),
            )
            ->each(function (array $row) use ($items): void {
                $items->push([
                    'kind' => 'review',
                    'title' => null,
                    'dueAt' => $row['dueAt'],
                    'route' => '/governance',
                ]);
            });

        return $items
            ->sortBy('dueAt')
            ->take(5)
            ->values()
            ->all();
    }
}
