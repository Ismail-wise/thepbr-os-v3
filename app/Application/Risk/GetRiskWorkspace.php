<?php

declare(strict_types=1);

namespace App\Application\Risk;

use App\Application\Governance\GovernanceActorContext;
use App\Domain\Access\CapabilityCatalog;
use App\Infrastructure\Persistence\Eloquent\Businesses\Business;
use App\Infrastructure\Persistence\Eloquent\Identity\User;
use App\Infrastructure\Persistence\Eloquent\Risk\RiskControlTest;
use App\Infrastructure\Persistence\Eloquent\Risk\RiskIncident;
use App\Infrastructure\Persistence\Eloquent\Risk\RiskItem;
use App\Infrastructure\Persistence\Eloquent\Risk\RiskProtectionRecord;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

final class GetRiskWorkspace
{
    public function __construct(
        private readonly GovernanceActorContext $actorContext,
        private readonly RiskRecordVisibility $visibility,
    ) {}

    /** @return array<string,mixed>|null */
    public function execute(User $user, Business $business): ?array
    {
        if ($this->actorContext->membership(
            $user,
            $business,
            CapabilityCatalog::RISK_VIEW,
        ) === null) {
            return null;
        }

        $canManage = $this->actorContext->membership(
            $user,
            $business,
            CapabilityCatalog::RISK_MANAGE,
        ) !== null;

        $head = DB::table('record_family_effective_heads as h')
            ->join('formal_record_versions as v', 'v.id', '=', 'h.formal_record_version_id')
            ->join('formal_record_families as f', 'f.id', '=', 'v.formal_record_family_id')
            ->where('h.business_id', $business->getKey())
            ->where('f.business_id', $business->getKey())
            ->where('f.record_type', 'risk_register')
            ->first(['v.id', 'v.version_number', 'v.effective_from', 'v.content_hash']);

        $current = null;

        if ($head !== null) {
            $versionId = (string) $head->id;
            $header = DB::table('risk_register_versions')
                ->where('business_id', $business->getKey())
                ->where('formal_record_version_id', $versionId)
                ->first();

            $risks = $this->filter(
                $user,
                $business,
                RiskItem::query()
                    ->where('business_id', $business->getKey())
                    ->where('formal_record_version_id', $versionId)
                    ->orderByDesc('risk_score')
                    ->get(),
                RiskItem::class,
            );

            $protections = $this->filter(
                $user,
                $business,
                RiskProtectionRecord::query()
                    ->where('business_id', $business->getKey())
                    ->where('formal_record_version_id', $versionId)
                    ->orderBy('protection_type')
                    ->get(),
                RiskProtectionRecord::class,
            );

            $current = [
                'formal_record_version_id' => $versionId,
                'version_number' => (int) $head->version_number,
                'effective_from' => $head->effective_from,
                'content_hash' => (string) $head->content_hash,
                'header' => $header,
                'risks' => $risks->values(),
                'protections' => $protections->values(),
            ];
        }

        $incidents = $this->filter(
            $user,
            $business,
            RiskIncident::query()
                ->where('business_id', $business->getKey())
                ->orderByDesc('incident_at')
                ->limit(100)
                ->get(),
            RiskIncident::class,
        );

        $tests = $this->filter(
            $user,
            $business,
            RiskControlTest::query()
                ->where('business_id', $business->getKey())
                ->orderByDesc('created_at')
                ->limit(100)
                ->get(),
            RiskControlTest::class,
        );

        $versions = DB::table('formal_record_versions as v')
            ->join('formal_record_families as f', function ($join): void {
                $join->on('f.id', '=', 'v.formal_record_family_id')
                    ->on('f.business_id', '=', 'v.business_id');
            })
            ->where('v.business_id', $business->getKey())
            ->where('f.record_type', 'risk_register')
            ->orderByDesc('v.version_number')
            ->get([
                'v.id',
                'v.version_number',
                'v.revision',
                'v.frozen_at',
                'v.effective_from',
                'v.review_due_at',
            ])
            ->map(function (object $version) use ($business): object {
                $version->state = DB::table('record_version_state_transitions')
                    ->where('business_id', $business->getKey())
                    ->where('formal_record_version_id', $version->id)
                    ->orderByDesc('sequence')
                    ->value('to_state');

                return $version;
            });

        $memberships = DB::table('memberships as m')
            ->join('users as u', 'u.id', '=', 'm.user_id')
            ->where('m.business_id', $business->getKey())
            ->where('m.access_status', 'active')
            ->orderBy('u.email')
            ->get(['m.id', 'u.email']);

        $operationsVersionId = $this->effectiveOperationsVersion($business);
        $roles = $operationsVersionId === null
            ? collect()
            : DB::table('operations_roles')
                ->where('business_id', $business->getKey())
                ->where('formal_record_version_id', $operationsVersionId)
                ->where('status', 'active')
                ->orderBy('name')
                ->get(['id', 'role_key', 'name']);

        return [
            'business' => [
                'id' => (string) $business->getKey(),
                'name' => (string) $business->name,
            ],
            'permissions' => ['manage' => $canManage],
            'current' => $current,
            'incidents' => $incidents->values(),
            'control_tests' => $tests->values(),
            'versions' => $versions,
            'memberships' => $memberships,
            'operations_roles' => $roles,
            'attention' => [
                'incidents' => $incidents
                    ->whereNotIn('status', ['resolved', 'closed'])
                    ->count(),
                'failed_tests' => $tests
                    ->where('result', 'failed')
                    ->count(),
                'overdue_risks' => $current === null
                    ? 0
                    : collect($current['risks'])
                        ->filter(fn ($risk): bool => $risk->review_date !== null
                            && (string) $risk->review_date < now()->toDateString()
                            && $risk->status !== 'closed'
                        )->count(),
            ],
        ];
    }

    private function filter(
        User $user,
        Business $business,
        Collection $rows,
        string $resourceType,
    ): Collection {
        return $rows->filter(fn ($row): bool => $this->visibility->canView(
            $user,
            $business,
            $resourceType,
            (string) $row->id,
            (string) $row->confidentiality,
        ));
    }

    private function effectiveOperationsVersion(Business $business): ?string
    {
        $id = DB::table('record_family_effective_heads as h')
            ->join('formal_record_versions as v', 'v.id', '=', 'h.formal_record_version_id')
            ->join('formal_record_families as f', 'f.id', '=', 'v.formal_record_family_id')
            ->where('h.business_id', $business->getKey())
            ->where('f.business_id', $business->getKey())
            ->where('f.record_type', 'operations_register')
            ->value('v.id');

        return $id === null ? null : (string) $id;
    }
}
