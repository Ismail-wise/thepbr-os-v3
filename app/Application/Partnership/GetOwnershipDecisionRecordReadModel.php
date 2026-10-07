<?php

declare(strict_types=1);

namespace App\Application\Partnership;

use App\Domain\Access\CapabilityCatalog;
use App\Domain\Partnership\OwnershipDecisionRecordContract;
use App\Infrastructure\Persistence\Eloquent\Businesses\Business;
use App\Infrastructure\Persistence\Eloquent\Identity\User;
use App\Infrastructure\Persistence\Eloquent\Members\Membership;
use Illuminate\Support\Facades\DB;

final class GetOwnershipDecisionRecordReadModel
{
    public function __construct(
        private readonly PartnershipActorContext $actor,
        private readonly GetOwnershipDecisionRecordSource $source,
    ) {}

    /** @return array<string,mixed>|null */
    public function execute(User $user, Business $business): ?array
    {
        if (! $this->actor->allows($user, $business, CapabilityCatalog::OWNERSHIP_VIEW)
            || ! $this->actor->allows($user, $business, CapabilityCatalog::RECORDS_VIEW)) {
            return null;
        }

        $businessId = (string) $business->getKey();
        $source = $this->source->execute($business);
        $currentRegisterId = $source === null
            ? null
            : (string) $source['register']->id;

        $rows = DB::table('ownership_decision_records as record')
            ->join('ownership_register_versions as register', function ($join): void {
                $join->on('register.id', '=', 'record.ownership_register_version_id')
                    ->on('register.business_id', '=', 'record.business_id');
            })
            ->where('record.business_id', $businessId)
            ->orderByDesc('record.created_at')
            ->get([
                'record.*',
                'register.version_number',
                'register.status as register_status',
                'register.effective_from',
                'register.approved_at',
            ]);

        $history = $rows->map(function (object $row) use ($business, $currentRegisterId): array {
            $approvedBy = $this->approvedBy($business, (string) $row->governance_decision_id);

            return [
                'id' => (string) $row->id,
                'registerVersionId' => (string) $row->ownership_register_version_id,
                'registerVersionNumber' => (int) $row->version_number,
                'current' => $currentRegisterId !== null
                    && (string) $row->ownership_register_version_id === $currentRegisterId,
                'status' => match ((string) $row->register_status) {
                    'effective' => 'Current / Effective',
                    'superseded' => 'Superseded',
                    'archived' => 'Archived',
                    default => ucfirst(str_replace('_', ' ', (string) $row->register_status)),
                },
                'effectiveDate' => substr((string) $row->effective_from, 0, 10),
                'reviewDate' => (string) $row->review_date,
                'decisionSummary' => (string) $row->decision_summary,
                'evidenceReferences' => json_decode((string) $row->evidence_references, true, 512, JSON_THROW_ON_ERROR),
                'owner' => $this->membershipLabel($business, (string) $row->decision_owner_membership_id),
                'approvedBy' => $approvedBy,
                'approvalDate' => $row->approved_at === null ? null : (string) $row->approved_at,
                'recordedAt' => (string) $row->created_at,
            ];
        })->all();

        $current = collect($history)->firstWhere('current', true);

        $canManage = $this->actor->allows(
            $user,
            $business,
            CapabilityCatalog::OWNERSHIP_MANAGE,
        ) && $this->actor->allows(
            $user,
            $business,
            CapabilityCatalog::RECORDS_MANAGE,
        );

        return [
            'contractVersion' => OwnershipDecisionRecordContract::READ_MODEL_VERSION,
            'recordContractVersion' => OwnershipDecisionRecordContract::CONTRACT_VERSION,
            'available' => $source !== null,
            'recorded' => is_array($current),
            'current' => $current,
            'history' => $history,
            'canManage' => $canManage,
            'ownerOptions' => $canManage ? $this->ownerOptions($business) : [],
            'defaultReviewDate' => now()->addYear()->toDateString(),
            'semantics' => [
                'appendOnly' => true,
                'approvedByDerived' => true,
                'approvalDateDerived' => true,
                'effectiveDateDerived' => true,
                'recordChangesOwnership' => false,
            ],
        ];
    }

    /** @return list<string> */
    private function approvedBy(Business $business, string $decisionId): array
    {
        $memberships = DB::table('approvals as approval')
            ->join(
                'decision_participants as participant',
                function ($join): void {
                    $join
                        ->on(
                            'participant.id',
                            '=',
                            'approval.decision_participant_id',
                        )
                        ->on(
                            'participant.business_id',
                            '=',
                            'approval.business_id',
                        )
                        ->on(
                            'participant.decision_id',
                            '=',
                            'approval.decision_id',
                        );
                },
            )
            ->where('approval.business_id', $business->getKey())
            ->where('approval.decision_id', $decisionId)
            ->where('approval.outcome', 'approved')
            ->where('participant.status', 'eligible')
            ->pluck('approval.membership_id')
            ->merge(
                DB::table('votes as vote')
                    ->join(
                        'decision_participants as participant',
                        function ($join): void {
                            $join
                                ->on(
                                    'participant.id',
                                    '=',
                                    'vote.decision_participant_id',
                                )
                                ->on(
                                    'participant.business_id',
                                    '=',
                                    'vote.business_id',
                                )
                                ->on(
                                    'participant.decision_id',
                                    '=',
                                    'vote.decision_id',
                                );
                        },
                    )
                    ->where('vote.business_id', $business->getKey())
                    ->where('vote.decision_id', $decisionId)
                    ->where('vote.choice', 'for')
                    ->where('participant.status', 'eligible')
                    ->pluck('vote.membership_id'),
            )
            ->unique()
            ->values();

        return $memberships
            ->map(fn (mixed $id): string => $this->membershipLabel($business, (string) $id))
            ->all();
    }

    /** @return list<array{id:string,name:string}> */
    private function ownerOptions(Business $business): array
    {
        return Membership::query()
            ->with(['user.profile'])
            ->where('business_id', $business->getKey())
            ->where('access_status', 'active')
            ->get()
            ->map(fn (Membership $membership): array => [
                'id' => (string) $membership->getKey(),
                'name' => $this->label($membership),
            ])
            ->sortBy('name')
            ->values()
            ->all();
    }

    private function membershipLabel(Business $business, string $membershipId): string
    {
        $membership = Membership::query()
            ->with(['user.profile'])
            ->where('business_id', $business->getKey())
            ->whereKey($membershipId)
            ->first();

        return $membership === null
            ? 'Unavailable member'
            : $this->label($membership);
    }

    private function label(Membership $membership): string
    {
        $display = trim((string) ($membership->user?->profile?->display_name ?? ''));

        return $display !== ''
            ? $display
            : (string) ($membership->user?->email ?? 'Business member');
    }
}
