<?php

declare(strict_types=1);

namespace App\Application\Account;

use App\Application\Businesses\ListAccessibleBusinesses;
use App\Infrastructure\Persistence\Eloquent\Businesses\Business;
use App\Infrastructure\Persistence\Eloquent\Identity\User;
use App\Infrastructure\Persistence\Eloquent\Identity\UserProfile;

final class GetAccountHome
{
    public function __construct(
        private readonly ListAccessibleBusinesses $businesses,
        private readonly GetAccountGovernanceInbox $governanceInbox,
        private readonly GetAccountWork $work,
    ) {}

    /** @return array<string,mixed> */
    public function execute(User $user): array
    {
        $profile = UserProfile::query()
            ->whereKey((string) $user->getKey())
            ->first();

        $businesses = $this->businesses
            ->handle($user)
            ->map(
                static fn (Business $business): array => [
                    'businessId' => (string) $business->getKey(),
                    'name' => (string) $business->name,
                    'stage' => $business->business_stage->value,
                    'setupPhase' => $business->setup_phase?->value,
                    'workspaceStatus' => $business->workspace_status->value,
                    'baseCurrency' => (string) $business->base_currency,
                ],
            )
            ->values();

        $inbox = $this->governanceInbox->execute($user);
        $work = collect($this->work->execute($user));

        $attention = collect();

        collect($inbox['approvals'])->each(
            static function (array $row) use ($attention): void {
                $attention->push([
                    ...$row,
                    'kind' => 'approval',
                    'title' => (string) $row['decisionLabel'],
                    'dueAt' => $row['openedAt'],
                ]);
            },
        );

        collect($inbox['signatures'])->each(
            static function (array $row) use ($attention): void {
                $attention->push([
                    ...$row,
                    'kind' => 'signature',
                    'title' => (string) $row['decisionLabel'],
                    'dueAt' => $row['requestedAt'],
                ]);
            },
        );

        $work->each(
            static function (array $row) use ($attention): void {
                $attention->push([
                    ...$row,
                    'kind' => (string) $row['kind'],
                ]);
            },
        );

        $attention = $attention
            ->sort(function (array $left, array $right): int {
                $priority = [
                    'approval' => 0,
                    'signature' => 1,
                    'proposal_review' => 2,
                    'record_review' => 2,
                    'operations_action' => 3,
                    'governance_action' => 3,
                ];

                $leftPriority = $priority[$left['kind'] ?? ''] ?? 9;
                $rightPriority = $priority[$right['kind'] ?? ''] ?? 9;

                if ($leftPriority !== $rightPriority) {
                    return $leftPriority <=> $rightPriority;
                }

                return strcmp(
                    (string) ($left['dueAt'] ?? '9999'),
                    (string) ($right['dueAt'] ?? '9999'),
                );
            })
            ->take(6)
            ->values()
            ->all();

        $notifications = collect($inbox['notifications'])
            ->where('status', 'unread')
            ->take(4)
            ->values()
            ->all();

        return [
            'account' => [
                'email' => (string) $user->email,
                'displayName' => $profile?->display_name,
            ],
            'attention' => $attention,
            'businesses' => $businesses->take(6)->all(),
            'notifications' => $notifications,
        ];
    }
}
