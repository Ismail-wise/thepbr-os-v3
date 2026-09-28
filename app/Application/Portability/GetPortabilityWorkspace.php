<?php

declare(strict_types=1);

namespace App\Application\Portability;

use App\Application\Access\AuthorizeBusinessCapability;
use App\Application\Access\ResolveMembershipCapabilities;
use App\Domain\Access\CapabilityCatalog;
use App\Domain\Access\ValueObjects\Capability;
use App\Infrastructure\Persistence\Eloquent\Businesses\Business;
use App\Infrastructure\Persistence\Eloquent\Identity\User;
use App\Infrastructure\Persistence\Eloquent\Portability\BusinessArchiveTransition;
use App\Infrastructure\Persistence\Eloquent\Portability\BusinessPortabilityExport;

final class GetPortabilityWorkspace
{
    public function __construct(
        private readonly ResolveMembershipCapabilities $memberships,
        private readonly AuthorizeBusinessCapability $authorize,
        private readonly AuthorizeBusinessExportDownload $downloads,
    ) {}

    /**
     * @return array<string,mixed>|null
     */
    public function execute(
        User $user,
        Business $business,
    ): ?array {
        $membership = $this->memberships->activeMembership(
            $user,
            $business,
        );

        if (
            $membership === null
            || ! $this->authorize->decide(
                $user,
                $business,
                $business,
                new Capability(CapabilityCatalog::PORTABILITY_VIEW),
            )->allowed
        ) {
            return null;
        }

        $canManage = $this->authorize->decide(
            $user,
            $business,
            $business,
            new Capability(CapabilityCatalog::PORTABILITY_MANAGE),
        )->allowed;

        $archiveTransitions = BusinessArchiveTransition::query()
            ->where('business_id', $business->getKey())
            ->orderByDesc('occurred_at')
            ->orderByDesc('created_at')
            ->limit(100)
            ->get()
            ->map(static fn (
                BusinessArchiveTransition $transition,
            ): array => [
                'id' => (string) $transition->getKey(),
                'from_status' => $transition->from_status->value,
                'to_status' => $transition->to_status->value,
                'reason' => (string) $transition->reason,
                'occurred_at' => $transition->occurred_at?->toAtomString(),
            ])
            ->all();

        $exports = BusinessPortabilityExport::query()
            ->where('business_id', $business->getKey())
            ->where(
                'requested_by_membership_id',
                $membership->getKey(),
            )
            ->orderByDesc('created_at')
            ->limit(100)
            ->get()
            ->map(function (
                BusinessPortabilityExport $export,
            ) use ($user, $business): array {
                return [
                    'id' => (string) $export->getKey(),
                    'status' => $export->status->value,
                    'requested_categories' => $export->requested_categories ?? [],
                    'excluded_categories' => $export->excluded_categories ?? [],
                    'manifest_hash' => $export->manifest_hash,
                    'content_sha256' => $export->content_sha256,
                    'size_bytes' => $export->size_bytes,
                    'requested_at' => $export->requested_at?->toAtomString(),
                    'available_at' => $export->available_at?->toAtomString(),
                    'can_download' => $this->downloads->canDownload(
                        $user,
                        $business,
                        (string) $export->getKey(),
                    ),
                ];
            })
            ->all();

        return [
            'business' => [
                'id' => (string) $business->getKey(),
                'name' => (string) $business->name,
                'workspace_status' => $business->workspace_status->value,
            ],
            'permissions' => [
                'view' => true,
                'manage' => $canManage,
            ],
            'supported_categories' => [
                'business',
                'formal_records',
                'documents',
                'ownership',
                'partners',
            ],
            'archive_transitions' => $archiveTransitions,
            'exports' => $exports,
        ];
    }
}
