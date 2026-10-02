<?php

declare(strict_types=1);

namespace App\Application\Account;

use App\Application\Businesses\ListAccessibleBusinesses;
use App\Application\Governance\GetGovernanceCommandCenter;
use App\Infrastructure\Persistence\Eloquent\Identity\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

final class GetAccountGovernanceInbox
{
    public function __construct(
        private readonly ListAccessibleBusinesses $businesses,
        private readonly GetGovernanceCommandCenter $governance,
    ) {}

    /**
     * Account-level presentation composition over existing authorized
     * Business-scoped Governance read models.
     *
     * It creates no canonical truth and never infers hidden Business data.
     *
     * @return array{
     *   approvals:list<array<string,mixed>>,
     *   signatures:list<array<string,mixed>>,
     *   reviews:list<array<string,mixed>>,
     *   notifications:list<array<string,mixed>>
     * }
     */
    public function execute(User $user): array
    {
        $approvals = collect();
        $signatures = collect();
        $reviews = collect();
        $notifications = collect();

        foreach ($this->businesses->handle($user) as $business) {
            $workspace = $this->governance->execute($user, $business);

            if ($workspace === null) {
                continue;
            }

            $businessId = (string) $business->getKey();
            $businessName = (string) $business->name;

            $decisionLabels = collect($workspace['decisions'] ?? [])
                ->mapWithKeys(
                    static fn (array $row): array => [
                        (string) ($row['id'] ?? '') => self::decisionLabel(
                            (string) ($row['type'] ?? ''),
                        ),
                    ],
                );

            collect($workspace['decisions'] ?? [])
                ->filter(
                    static fn (array $row): bool => (bool) (
                        $row['actions']['canApprove'] ?? false
                    ),
                )
                ->each(function (array $row) use (
                    $approvals,
                    $businessId,
                    $businessName,
                ): void {
                    $approvals->push([
                        'businessId' => $businessId,
                        'businessName' => $businessName,
                        'decisionLabel' => self::decisionLabel(
                            (string) ($row['type'] ?? ''),
                        ),
                        'openedAt' => self::nullableString(
                            $row['openedAt'] ?? null,
                        ),
                        'route' => '/governance',
                    ]);
                });

            collect($workspace['signatureRequests'] ?? [])
                ->filter(
                    static fn (array $row): bool => (bool) (
                        $row['canSign'] ?? false
                    ),
                )
                ->each(function (array $row) use (
                    $signatures,
                    $decisionLabels,
                    $businessId,
                    $businessName,
                ): void {
                    $decisionId = (string) ($row['decisionId'] ?? '');

                    $signatures->push([
                        'businessId' => $businessId,
                        'businessName' => $businessName,
                        'decisionLabel' => (string) (
                            $decisionLabels->get($decisionId)
                            ?? 'Governance decision'
                        ),
                        'status' => (string) ($row['status'] ?? ''),
                        'requestedAt' => self::nullableString(
                            $row['requestedAt'] ?? null,
                        ),
                        'route' => '/governance',
                    ]);
                });

            collect($workspace['proposalVersions'] ?? [])
                ->filter(
                    static fn (array $row): bool => (bool) (
                        $row['canCompleteReview'] ?? false
                    ),
                )
                ->each(function (array $row) use (
                    $reviews,
                    $businessId,
                    $businessName,
                ): void {
                    $review = is_array($row['review'] ?? null)
                        ? $row['review']
                        : [];

                    $reviews->push([
                        'businessId' => $businessId,
                        'businessName' => $businessName,
                        'kind' => 'proposal_review',
                        'dueAt' => self::nullableString(
                            $review['dueAt'] ?? null,
                        ),
                        'route' => '/governance',
                    ]);
                });

            collect($workspace['reviews'] ?? [])
                ->filter(
                    static fn (array $row): bool => (bool) (
                        $row['canComplete'] ?? false
                    ),
                )
                ->each(function (array $row) use (
                    $reviews,
                    $businessId,
                    $businessName,
                ): void {
                    $reviews->push([
                        'businessId' => $businessId,
                        'businessName' => $businessName,
                        'kind' => 'record_review',
                        'dueAt' => self::nullableString(
                            $row['dueAt'] ?? null,
                        ),
                        'route' => '/governance',
                    ]);
                });

            collect($workspace['notifications'] ?? [])
                ->each(function (array $row) use (
                    $notifications,
                    $businessId,
                    $businessName,
                ): void {
                    $notifications->push([
                        'businessId' => $businessId,
                        'businessName' => $businessName,
                        'kind' => self::notificationKind(
                            (string) ($row['kind'] ?? ''),
                        ),
                        'status' => (string) ($row['status'] ?? ''),
                        'createdAt' => self::nullableString(
                            $row['createdAt'] ?? null,
                        ),
                        'route' => '/governance',
                    ]);
                });
        }

        return [
            'approvals' => $this->sortByDate(
                $approvals,
                'openedAt',
                false,
            )->take(100)->values()->all(),
            'signatures' => $this->sortByDate(
                $signatures,
                'requestedAt',
                false,
            )->take(100)->values()->all(),
            'reviews' => $this->sortByDate(
                $reviews,
                'dueAt',
                true,
            )->take(100)->values()->all(),
            'notifications' => $this->sortByDate(
                $notifications,
                'createdAt',
                false,
                descending: true,
            )->take(100)->values()->all(),
        ];
    }

    /**
     * @param  Collection<int,array<string,mixed>>  $rows
     * @return Collection<int,array<string,mixed>>
     */
    private function sortByDate(
        Collection $rows,
        string $field,
        bool $nullsLast,
        bool $descending = false,
    ): Collection {
        return $rows->sort(function (
            array $left,
            array $right,
        ) use ($field, $nullsLast, $descending): int {
            $leftDate = self::nullableString($left[$field] ?? null);
            $rightDate = self::nullableString($right[$field] ?? null);

            if ($leftDate === null || $rightDate === null) {
                if ($leftDate === $rightDate) {
                    return strcmp(
                        (string) ($left['businessName'] ?? ''),
                        (string) ($right['businessName'] ?? ''),
                    );
                }

                return $leftDate === null
                    ? ($nullsLast ? 1 : -1)
                    : ($nullsLast ? -1 : 1);
            }

            $comparison = strcmp($leftDate, $rightDate);

            return $descending ? -$comparison : $comparison;
        });
    }

    private static function decisionLabel(string $type): string
    {
        $label = trim(Str::headline($type));

        return $label === '' ? 'Governance decision' : $label;
    }

    private static function notificationKind(string $kind): string
    {
        return match ($kind) {
            'governance.action.assigned' => 'action_assigned',
            'governance.proposal_review.assigned' => 'proposal_review_assigned',
            'governance.review.assigned' => 'review_assigned',
            'governance.signature.requested' => 'signature_requested',
            default => 'governance_update',
        };
    }

    private static function nullableString(mixed $value): ?string
    {
        return is_string($value) && $value !== '' ? $value : null;
    }
}
