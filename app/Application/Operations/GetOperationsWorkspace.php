<?php

declare(strict_types=1);

namespace App\Application\Operations;

use App\Application\Governance\GovernanceActorContext;
use App\Domain\Access\CapabilityCatalog;
use App\Infrastructure\Persistence\Eloquent\Businesses\Business;
use App\Infrastructure\Persistence\Eloquent\Identity\User;
use Illuminate\Support\Facades\DB;

final class GetOperationsWorkspace
{
    public function __construct(
        private readonly GovernanceActorContext $actorContext,
    ) {}

    /**
     * @return array<string,mixed>|null
     */
    public function execute(
        User $user,
        Business $business,
    ): ?array {
        if ($this->actorContext->membership(
            $user,
            $business,
            CapabilityCatalog::OPERATIONS_VIEW,
        ) === null) {
            return null;
        }

        $canManage = $this->actorContext->membership(
            $user,
            $business,
            CapabilityCatalog::OPERATIONS_MANAGE,
        ) !== null;

        $head = DB::table('record_family_effective_heads as h')
            ->join(
                'formal_record_versions as v',
                function ($join): void {
                    $join
                        ->on('v.id', '=', 'h.formal_record_version_id')
                        ->on('v.business_id', '=', 'h.business_id');
                },
            )
            ->join(
                'formal_record_families as f',
                function ($join): void {
                    $join
                        ->on('f.id', '=', 'v.formal_record_family_id')
                        ->on('f.business_id', '=', 'v.business_id');
                },
            )
            ->where('h.business_id', $business->getKey())
            ->where('f.record_type', 'operations_register')
            ->first([
                'v.id',
                'v.version_number',
                'v.effective_from',
                'v.content_hash',
            ]);

        $current = null;

        if ($head !== null) {
            $versionId = (string) $head->id;

            $header = DB::table('operations_register_versions')
                ->where('business_id', $business->getKey())
                ->where('formal_record_version_id', $versionId)
                ->first();

            $roles = DB::table('operations_roles')
                ->where('business_id', $business->getKey())
                ->where('formal_record_version_id', $versionId)
                ->orderBy('role_key')
                ->get();

            $assignments = DB::table('operations_role_assignments')
                ->where('business_id', $business->getKey())
                ->where('formal_record_version_id', $versionId)
                ->orderBy('operations_role_id')
                ->orderBy('assignment_type')
                ->get();

            $raciItems = DB::table('operations_raci_items')
                ->where('business_id', $business->getKey())
                ->where('formal_record_version_id', $versionId)
                ->orderBy('sequence')
                ->get();

            $raciAssignments = DB::table(
                'operations_raci_assignments as a',
            )
                ->join(
                    'operations_raci_items as i',
                    function ($join): void {
                        $join
                            ->on(
                                'i.id',
                                '=',
                                'a.operations_raci_item_id',
                            )
                            ->on(
                                'i.business_id',
                                '=',
                                'a.business_id',
                            );
                    },
                )
                ->where('a.business_id', $business->getKey())
                ->where('i.formal_record_version_id', $versionId)
                ->get(['a.*']);

            $kpis = DB::table('operations_kpis')
                ->where('business_id', $business->getKey())
                ->where('formal_record_version_id', $versionId)
                ->orderBy('name')
                ->get();

            $actions = DB::table('operations_action_links as link')
                ->join(
                    'actions as action',
                    function ($join): void {
                        $join
                            ->on('action.id', '=', 'link.action_id')
                            ->on(
                                'action.business_id',
                                '=',
                                'link.business_id',
                            );
                    },
                )
                ->where('link.business_id', $business->getKey())
                ->where(
                    'link.formal_record_version_id',
                    $versionId,
                )
                ->orderByDesc('action.created_at')
                ->get([
                    'action.id',
                    'action.title',
                    'action.description',
                    'action.status',
                    'action.blocked_reason',
                    'action.assigned_membership_id',
                    'action.due_at',
                    'action.completed_at',
                    'link.operations_role_id',
                ]);

            $current = [
                'id' => $versionId,
                'version_number' => (int) $head->version_number,
                'effective_from' => $head->effective_from,
                'content_hash' => (string) $head->content_hash,
                'header' => $header,
                'roles' => $roles,
                'assignments' => $assignments,
                'raci_items' => $raciItems,
                'raci_assignments' => $raciAssignments,
                'kpis' => $kpis,
                'actions' => $actions,
            ];
        }

        $memberships = DB::table('memberships as m')
            ->join('users as u', 'u.id', '=', 'm.user_id')
            ->where('m.business_id', $business->getKey())
            ->where('m.access_status', 'active')
            ->orderBy('u.email')
            ->get([
                'm.id',
                'u.email',
            ]);

        $pendingVersions = DB::table('formal_record_versions as v')
            ->join(
                'formal_record_families as f',
                function ($join): void {
                    $join
                        ->on('f.id', '=', 'v.formal_record_family_id')
                        ->on('f.business_id', '=', 'v.business_id');
                },
            )
            ->where('v.business_id', $business->getKey())
            ->where('f.record_type', 'operations_register')
            ->orderByDesc('v.version_number')
            ->get([
                'v.id',
                'v.version_number',
                'v.revision',
                'v.frozen_at',
                'v.effective_from',
            ])
            ->map(
                static function (object $version) use (
                    $business,
                ): array {
                    $latestState = DB::table(
                        'record_version_state_transitions',
                    )
                        ->where(
                            'business_id',
                            $business->getKey(),
                        )
                        ->where(
                            'formal_record_version_id',
                            $version->id,
                        )
                        ->orderByDesc('sequence')
                        ->value('to_state');

                    return [
                        'id' => (string) $version->id,
                        'version_number' => (int) $version->version_number,
                        'revision' => (int) $version->revision,
                        'frozen_at' => $version->frozen_at,
                        'effective_from' => $version->effective_from,
                        'state' => $latestState === null
                            ? null
                            : (string) $latestState,
                    ];
                },
            )
            ->values();

        return [
            'business' => [
                'id' => (string) $business->getKey(),
                'name' => (string) $business->name,
            ],
            'permissions' => [
                'manage' => $canManage,
            ],
            'current' => $current,
            'memberships' => $memberships,
            'versions' => $pendingVersions,
        ];
    }
}
