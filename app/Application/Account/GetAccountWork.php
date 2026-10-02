<?php

declare(strict_types=1);

namespace App\Application\Account;

use App\Application\Access\ResolveMembershipCapabilities;
use App\Application\Businesses\ListAccessibleBusinesses;
use App\Application\Governance\GetGovernanceCommandCenter;
use App\Application\Operations\GetOperationsWorkspace;
use App\Infrastructure\Persistence\Eloquent\Identity\User;
use Illuminate\Support\Collection;

final class GetAccountWork
{
    public function __construct(
        private readonly ListAccessibleBusinesses $businesses,
        private readonly ResolveMembershipCapabilities $memberships,
        private readonly GetGovernanceCommandCenter $governance,
        private readonly GetOperationsWorkspace $operations,
    ) {}

    /**
     * Account-level read-only work composition.
     *
     * Every Business is first constrained by active Membership and then by the
     * existing source reader's capability/visibility rules.
     *
     * @return list<array<string,mixed>>
     */
    public function execute(User $user): array
    {
        $items = collect();

        foreach ($this->businesses->handle($user) as $business) {
            $membership = $this->memberships->activeMembership(
                $user,
                $business,
            );

            if ($membership === null) {
                continue;
            }

            $businessId = (string) $business->getKey();
            $businessName = (string) $business->name;
            $membershipId = (string) $membership->getKey();
            $seenActionIds = [];

            $operations = $this->operations->execute($user, $business);
            $operationActions = $operations['current']['actions'] ?? [];

            foreach ($operationActions as $row) {
                $assignedMembershipId = (string) (
                    $row->assigned_membership_id ?? ''
                );
                $status = (string) ($row->status ?? '');

                if (
                    $assignedMembershipId !== $membershipId
                    || in_array($status, ['completed', 'cancelled'], true)
                ) {
                    continue;
                }

                $actionId = (string) ($row->id ?? '');

                if ($actionId !== '') {
                    $seenActionIds[$actionId] = true;
                }

                $items->push([
                    'businessId' => $businessId,
                    'businessName' => $businessName,
                    'kind' => 'operations_action',
                    'title' => (string) ($row->title ?? 'Business action'),
                    'description' => self::nullableString(
                        $row->description ?? null,
                    ),
                    'status' => $status,
                    'dueAt' => self::nullableString($row->due_at ?? null),
                    'route' => '/operations',
                ]);
            }

            $governance = $this->governance->execute($user, $business);

            if ($governance === null) {
                continue;
            }

            collect($governance['actions'] ?? [])
                ->filter(
                    static fn (array $row): bool => (
                        (string) ($row['assignedMembershipId'] ?? '')
                        === $membershipId
                    ) && ! in_array(
                        (string) ($row['status'] ?? ''),
                        ['completed', 'cancelled'],
                        true,
                    ),
                )
                ->each(function (array $row) use (
                    $items,
                    &$seenActionIds,
                    $businessId,
                    $businessName,
                ): void {
                    $actionId = (string) ($row['id'] ?? '');

                    if (
                        $actionId !== ''
                        && isset($seenActionIds[$actionId])
                    ) {
                        return;
                    }

                    if ($actionId !== '') {
                        $seenActionIds[$actionId] = true;
                    }

                    $items->push([
                        'businessId' => $businessId,
                        'businessName' => $businessName,
                        'kind' => 'governance_action',
                        'title' => (string) (
                            $row['title'] ?? 'Business action'
                        ),
                        'description' => self::nullableString(
                            $row['description'] ?? null,
                        ),
                        'status' => (string) ($row['status'] ?? ''),
                        'dueAt' => self::nullableString(
                            $row['dueAt'] ?? null,
                        ),
                        'route' => '/governance',
                    ]);
                });

            collect($governance['proposalVersions'] ?? [])
                ->filter(
                    static fn (array $row): bool => (bool) (
                        $row['canCompleteReview'] ?? false
                    ),
                )
                ->each(function (array $row) use (
                    $items,
                    $businessId,
                    $businessName,
                ): void {
                    $review = is_array($row['review'] ?? null)
                        ? $row['review']
                        : [];

                    $items->push([
                        'businessId' => $businessId,
                        'businessName' => $businessName,
                        'kind' => 'proposal_review',
                        'title' => 'Proposal review',
                        'description' => null,
                        'status' => 'open',
                        'dueAt' => self::nullableString(
                            $review['dueAt'] ?? null,
                        ),
                        'route' => '/governance',
                    ]);
                });

            collect($governance['reviews'] ?? [])
                ->filter(
                    static fn (array $row): bool => (bool) (
                        $row['canComplete'] ?? false
                    ),
                )
                ->each(function (array $row) use (
                    $items,
                    $businessId,
                    $businessName,
                ): void {
                    $items->push([
                        'businessId' => $businessId,
                        'businessName' => $businessName,
                        'kind' => 'record_review',
                        'title' => 'Business record review',
                        'description' => null,
                        'status' => 'open',
                        'dueAt' => self::nullableString(
                            $row['dueAt'] ?? null,
                        ),
                        'route' => '/governance',
                    ]);
                });
        }

        return $this->sortWork($items)
            ->take(150)
            ->values()
            ->all();
    }

    /**
     * @param  Collection<int,array<string,mixed>>  $items
     * @return Collection<int,array<string,mixed>>
     */
    private function sortWork(Collection $items): Collection
    {
        return $items->sort(function (
            array $left,
            array $right,
        ): int {
            $leftDue = self::nullableString($left['dueAt'] ?? null);
            $rightDue = self::nullableString($right['dueAt'] ?? null);

            if ($leftDue !== null || $rightDue !== null) {
                if ($leftDue === null) {
                    return 1;
                }

                if ($rightDue === null) {
                    return -1;
                }

                $comparison = strcmp($leftDue, $rightDue);

                if ($comparison !== 0) {
                    return $comparison;
                }
            }

            $businessComparison = strcmp(
                (string) ($left['businessName'] ?? ''),
                (string) ($right['businessName'] ?? ''),
            );

            if ($businessComparison !== 0) {
                return $businessComparison;
            }

            return strcmp(
                (string) ($left['title'] ?? ''),
                (string) ($right['title'] ?? ''),
            );
        });
    }

    private static function nullableString(mixed $value): ?string
    {
        return is_string($value) && $value !== '' ? $value : null;
    }
}
