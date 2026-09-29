<?php

declare(strict_types=1);

namespace App\Application\Continuity;

use App\Application\Governance\GovernanceActorContext;
use App\Domain\Access\CapabilityCatalog;
use App\Infrastructure\Persistence\Eloquent\Businesses\Business;
use App\Infrastructure\Persistence\Eloquent\Continuity\ContinuityEmergencyAccessActivation;
use App\Infrastructure\Persistence\Eloquent\Continuity\ContinuityTest;
use App\Infrastructure\Persistence\Eloquent\Identity\User;
use Illuminate\Support\Facades\DB;

final class GetContinuityWorkspace
{
    public function __construct(
        private readonly GovernanceActorContext $actorContext,
        private readonly ContinuityRecordVisibility $visibility,
    ) {}

    /** @return array<string,mixed>|null */
    public function execute(User $user, Business $business): ?array
    {
        if ($this->actorContext->membership(
            $user,
            $business,
            CapabilityCatalog::CONTINUITY_VIEW,
        ) === null) {
            return null;
        }

        $canManage = $this->actorContext->membership(
            $user,
            $business,
            CapabilityCatalog::CONTINUITY_MANAGE,
        ) !== null;

        $head = DB::table('record_family_effective_heads as h')
            ->join('formal_record_versions as v', 'v.id', '=', 'h.formal_record_version_id')
            ->join('formal_record_families as f', 'f.id', '=', 'v.formal_record_family_id')
            ->where('h.business_id', $business->getKey())
            ->where('f.business_id', $business->getKey())
            ->where('f.record_type', 'continuity_plan')
            ->first(['v.id', 'v.version_number', 'v.effective_from', 'v.content_hash']);

        $current = null;

        if ($head !== null) {
            $versionId = (string) $head->id;
            $header = DB::table('continuity_plan_versions')
                ->where('business_id', $business->getKey())
                ->where('formal_record_version_id', $versionId)
                ->first();

            $access = DB::table('continuity_emergency_access_records')
                ->where('business_id', $business->getKey())
                ->where('formal_record_version_id', $versionId)
                ->orderBy('system_asset')
                ->get()
                ->filter(fn ($row): bool => $this->visibility->canView(
                    $user,
                    $business,
                    ContinuityRecordVisibility::EMERGENCY_ACCESS_RESOURCE,
                    (string) $row->id,
                    true,
                ))
                ->values();

            $current = [
                'formal_record_version_id' => $versionId,
                'version_number' => (int) $head->version_number,
                'effective_from' => $head->effective_from,
                'content_hash' => (string) $head->content_hash,
                'header' => $header,
                'critical_functions' => DB::table('continuity_critical_functions')
                    ->where('business_id', $business->getKey())
                    ->where('formal_record_version_id', $versionId)
                    ->orderBy('recovery_priority')
                    ->get(),
                'emergency_access' => $access,
                'interim_authority_plans' => DB::table('continuity_interim_authority_plans')
                    ->where('business_id', $business->getKey())
                    ->where('formal_record_version_id', $versionId)
                    ->orderBy('created_at')
                    ->get(),
                'successors' => DB::table('continuity_successor_candidates')
                    ->where('business_id', $business->getKey())
                    ->where('formal_record_version_id', $versionId)
                    ->orderBy('created_at')
                    ->get(),
                'communication_steps' => DB::table('continuity_communication_steps')
                    ->where('business_id', $business->getKey())
                    ->where('formal_record_version_id', $versionId)
                    ->orderBy('sequence')
                    ->get(),
                'recovery_actions' => DB::table('continuity_recovery_actions')
                    ->where('business_id', $business->getKey())
                    ->where('formal_record_version_id', $versionId)
                    ->orderBy('sequence')
                    ->get(),
            ];
        }

        $tests = ContinuityTest::query()
            ->where('business_id', $business->getKey())
            ->orderByDesc('created_at')
            ->limit(100)
            ->get();

        $activations = ContinuityEmergencyAccessActivation::query()
            ->where('business_id', $business->getKey())
            ->orderByDesc('created_at')
            ->limit(100)
            ->get()
            ->filter(fn ($row): bool => $this->visibility->canView(
                $user,
                $business,
                ContinuityEmergencyAccessActivation::class,
                (string) $row->getKey(),
                true,
            ))
            ->values();

        $versions = DB::table('formal_record_versions as v')
            ->join('formal_record_families as f', function ($join): void {
                $join->on('f.id', '=', 'v.formal_record_family_id')
                    ->on('f.business_id', '=', 'v.business_id');
            })
            ->where('v.business_id', $business->getKey())
            ->where('f.record_type', 'continuity_plan')
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

        $canViewOperations = $this->actorContext->membership(
            $user,
            $business,
            CapabilityCatalog::OPERATIONS_VIEW,
        ) !== null;

        $operationsVersionId = $this->effectiveOperationsVersion($business);
        $operationsPrerequisiteStatus = ! $canViewOperations
            ? 'unknown'
            : ($operationsVersionId === null ? 'missing' : 'met');

        $roles = ! $canViewOperations || $operationsVersionId === null
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
            'tests' => $tests,
            'activations' => $activations,
            'versions' => $versions,
            'memberships' => $memberships,
            'operations_roles' => $roles,
            'prerequisites' => [
                'operations_register' => [
                    'status' => $operationsPrerequisiteStatus,
                    'can_open_operations' => $canViewOperations,
                ],
            ],
            'attention' => [
                'failed_tests' => $tests->where('result', 'failed')->count(),
                'active_activations' => $activations->where('status', 'active')->count(),
                'requested_activations' => $activations->where('status', 'requested')->count(),
                'continuity_gaps' => $current === null
                    ? 0
                    : collect($current['critical_functions'])
                        ->whereIn('status', ['partial', 'gap'])
                        ->count(),
            ],
        ];
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
