<?php

declare(strict_types=1);

namespace App\Application\Reporting;

use App\Application\Access\AuthorizeBusinessCapability;
use App\Application\Access\ResolveMembershipCapabilities;
use App\Domain\Access\CapabilityCatalog;
use App\Domain\Access\ValueObjects\Capability;
use App\Infrastructure\Persistence\Eloquent\Businesses\Business;
use App\Infrastructure\Persistence\Eloquent\Identity\User;
use App\Infrastructure\Persistence\Eloquent\Reporting\BusinessPackExport;

final class GetReportsWorkspace
{
    public function __construct(
        private readonly ResolveMembershipCapabilities $memberships,
        private readonly AuthorizeBusinessCapability $authorize,
        private readonly AuthorizeBusinessPackDownload $downloadAuthorization,
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
                new Capability(CapabilityCatalog::REPORTS_VIEW),
            )->allowed
        ) {
            return null;
        }

        $canManage = $this->authorize->decide(
            $user,
            $business,
            $business,
            new Capability(CapabilityCatalog::REPORTS_MANAGE),
        )->allowed;

        $exports = BusinessPackExport::query()
            ->where('business_id', $business->getKey())
            ->where(
                'requested_by_membership_id',
                $membership->getKey(),
            )
            ->orderByDesc('created_at')
            ->limit(100)
            ->get()
            ->map(function (BusinessPackExport $export) use (
                $user,
                $business,
            ): array {
                return [
                    'id' => (string) $export->getKey(),
                    'status' => $export->status->value,
                    'output_language' => (string) $export->output_language,
                    'requested_scope' => $export->requested_scope ?? [],
                    'manifest_hash' => $export->manifest_hash,
                    'content_sha256' => $export->content_sha256,
                    'size_bytes' => $export->size_bytes,
                    'as_of_at' => $export->as_of_at?->format(DATE_ATOM),
                    'created_at' => $export->created_at?->format(DATE_ATOM),
                    'available_at' => $export->available_at?->format(DATE_ATOM),
                    'can_download' => $this->downloadAuthorization
                        ->canDownload(
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
            ],
            'permissions' => [
                'view' => true,
                'manage' => $canManage,
            ],
            'supported_scopes' => [
                'capital',
                'contributions',
                'ownership',
                'governance',
                'operations',
                'finance',
                'rewards',
                'risk',
                'continuity',
                'conflict',
                'partner_changes',
                'exit',
                'closure',
                'documents',
            ],
            'exports' => $exports,
        ];
    }
}
