<?php

declare(strict_types=1);

namespace App\Application\Governance;

use App\Domain\Access\CapabilityCatalog;
use App\Infrastructure\Persistence\Eloquent\Businesses\Business;
use App\Infrastructure\Persistence\Eloquent\Identity\User;
use App\Infrastructure\Persistence\Eloquent\Members\Membership;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;

final class GovernanceMeetingWorkflow
{
    public function __construct(
        private readonly GovernanceActorContext $actorContext,
        private readonly ResolveGovernanceAuthority $authority,
        private readonly RecordGovernanceOccurrence $occurrence,
    ) {}

    /** @return array<string,mixed>|null */
    public function workspace(
        User $user,
        Business $business,
    ): ?array {
        if ($this->actorContext->membership(
            $user,
            $business,
            CapabilityCatalog::GOVERNANCE_RECORDS_VIEW,
        ) === null) {
            return null;
        }

        $source = $this->authority->currentSource($business);

        $meetings = DB::table('governance_meetings')
            ->where('business_id', $business->getKey())
            ->orderByDesc('scheduled_at')
            ->get();

        $attendees = DB::table('governance_meeting_attendees')
            ->where('business_id', $business->getKey())
            ->orderBy('governance_meeting_id')
            ->get();

        $memberships = DB::table('memberships as membership')
            ->join(
                'users as user',
                'user.id',
                '=',
                'membership.user_id',
            )
            ->where('membership.business_id', $business->getKey())
            ->where('membership.access_status', 'active')
            ->orderBy('user.email')
            ->get([
                'membership.id',
                'user.email',
            ]);

        return [
            'business' => [
                'id' => (string) $business->getKey(),
                'name' => (string) $business->name,
            ],
            'permissions' => [
                'manage' => $this->actorContext->membership(
                    $user,
                    $business,
                    CapabilityCatalog::GOVERNANCE_RECORDS_MANAGE,
                ) !== null,
            ],
            'current_source' => $source === null
                ? null
                : [
                    'kind' => $source['source_kind'],
                    'formal_record_version_id' => (string) $source['version']->getKey(),
                ],
            'meetings' => $meetings,
            'attendees' => $attendees,
            'memberships' => $memberships,
        ];
    }

    /**
     * @param  list<string>  $attendeeMembershipIds
     */
    public function schedule(
        User $user,
        Business $business,
        string $title,
        CarbonInterface $scheduledAt,
        int $quorumRequired,
        string $agendaOwnerMembershipId,
        string $minutesOwnerMembershipId,
        string $agenda,
        array $attendeeMembershipIds,
        ?CarbonInterface $noticeSentAt = null,
    ): ?string {
        $creator = $this->actorContext->membership(
            $user,
            $business,
            CapabilityCatalog::GOVERNANCE_RECORDS_MANAGE,
        );

        if ($creator === null) {
            return null;
        }

        $title = trim($title);
        $agenda = trim($agenda);

        if ($title === '' || $agenda === '' || $quorumRequired < 1) {
            throw new InvalidArgumentException(
                'Meeting requires title, agenda and quorum of at least one.',
            );
        }

        $attendees = array_values(array_unique(array_map(
            static fn (mixed $id): string => trim((string) $id),
            $attendeeMembershipIds,
        )));

        if (
            $attendees === []
            || in_array('', $attendees, true)
            || $quorumRequired > count($attendees)
        ) {
            throw new InvalidArgumentException(
                'Meeting attendee list must support the stated quorum.',
            );
        }

        $membershipIds = array_values(array_unique([
            $agendaOwnerMembershipId,
            $minutesOwnerMembershipId,
            ...$attendees,
        ]));

        $activeCount = Membership::query()
            ->where('business_id', $business->getKey())
            ->whereIn('id', $membershipIds)
            ->where('access_status', 'active')
            ->count();

        if ($activeCount !== count($membershipIds)) {
            return null;
        }

        $source = $this->authority->currentSource($business);

        if ($source === null) {
            return null;
        }

        return DB::transaction(function () use (
            $user,
            $business,
            $creator,
            $source,
            $title,
            $scheduledAt,
            $noticeSentAt,
            $quorumRequired,
            $agendaOwnerMembershipId,
            $minutesOwnerMembershipId,
            $agenda,
            $attendees,
        ): string {
            $id = (string) Str::uuid7();

            DB::table('governance_meetings')->insert([
                'id' => $id,
                'business_id' => $business->getKey(),
                'authority_source_formal_record_version_id' => $source['version']->getKey(),
                'title' => $title,
                'meeting_type' => 'governance',
                'status' => 'scheduled',
                'scheduled_at' => $scheduledAt,
                'notice_sent_at' => $noticeSentAt,
                'quorum_required' => $quorumRequired,
                'quorum_present' => 0,
                'agenda_owner_membership_id' => $agendaOwnerMembershipId,
                'minutes_owner_membership_id' => $minutesOwnerMembershipId,
                'agenda' => $agenda,
                'minutes' => null,
                'created_by_membership_id' => $creator->getKey(),
                'held_at' => null,
                'cancelled_at' => null,
                'revision' => 1,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            foreach ($attendees as $membershipId) {
                DB::table('governance_meeting_attendees')->insert([
                    'id' => (string) Str::uuid7(),
                    'business_id' => $business->getKey(),
                    'governance_meeting_id' => $id,
                    'membership_id' => $membershipId,
                    'attendance_status' => 'invited',
                    'created_at' => now(),
                ]);
            }

            $this->occurrence->record(
                $user,
                $business,
                'governance.meeting.scheduled',
                'governance_meeting',
                $id,
                [
                    'quorum_required' => $quorumRequired,
                    'authority_source_kind' => $source['source_kind'],
                ],
            );

            return $id;
        });
    }

    /**
     * @param  array<string,string>  $attendanceByMembership
     */
    public function hold(
        User $user,
        Business $business,
        string $meetingId,
        string $minutes,
        array $attendanceByMembership,
    ): ?bool {
        if ($this->actorContext->membership(
            $user,
            $business,
            CapabilityCatalog::GOVERNANCE_RECORDS_MANAGE,
        ) === null) {
            return null;
        }

        $minutes = trim($minutes);

        if ($minutes === '') {
            throw new InvalidArgumentException(
                'Held Meeting requires Minutes.',
            );
        }

        return DB::transaction(function () use (
            $user,
            $business,
            $meetingId,
            $minutes,
            $attendanceByMembership,
        ): ?bool {
            $meeting = DB::table('governance_meetings')
                ->where('business_id', $business->getKey())
                ->where('id', $meetingId)
                ->lockForUpdate()
                ->first();

            if ($meeting === null || $meeting->status !== 'scheduled') {
                return null;
            }

            $attendees = DB::table('governance_meeting_attendees')
                ->where('business_id', $business->getKey())
                ->where('governance_meeting_id', $meetingId)
                ->lockForUpdate()
                ->get();

            $expectedIds = $attendees
                ->pluck('membership_id')
                ->map(static fn ($id): string => (string) $id)
                ->sort()
                ->values()
                ->all();

            $provided = [];

            foreach ($attendanceByMembership as $membershipId => $status) {
                $membershipId = trim((string) $membershipId);
                $status = trim((string) $status);

                if (! in_array(
                    $status,
                    ['present', 'remote', 'absent', 'recused'],
                    true,
                )) {
                    throw new InvalidArgumentException(
                        'Meeting attendance status is invalid.',
                    );
                }

                $provided[$membershipId] = $status;
            }

            $providedIds = array_keys($provided);
            sort($providedIds);

            if ($providedIds !== $expectedIds) {
                throw new InvalidArgumentException(
                    'Meeting attendance must resolve every invited Membership exactly once.',
                );
            }

            $quorumPresent = 0;

            foreach ($provided as $membershipId => $status) {
                DB::table('governance_meeting_attendees')
                    ->where('business_id', $business->getKey())
                    ->where('governance_meeting_id', $meetingId)
                    ->where('membership_id', $membershipId)
                    ->update(['attendance_status' => $status]);

                if (in_array($status, ['present', 'remote'], true)) {
                    $quorumPresent++;
                }
            }

            DB::table('governance_meetings')
                ->where('business_id', $business->getKey())
                ->where('id', $meetingId)
                ->update([
                    'status' => 'held',
                    'quorum_present' => $quorumPresent,
                    'minutes' => $minutes,
                    'held_at' => now(),
                    'revision' => ((int) $meeting->revision) + 1,
                    'updated_at' => now(),
                ]);

            $this->occurrence->record(
                $user,
                $business,
                'governance.meeting.held',
                'governance_meeting',
                $meetingId,
                [
                    'quorum_required' => (int) $meeting->quorum_required,
                    'quorum_present' => $quorumPresent,
                ],
            );

            return true;
        });
    }

    public function cancel(
        User $user,
        Business $business,
        string $meetingId,
    ): ?bool {
        if ($this->actorContext->membership(
            $user,
            $business,
            CapabilityCatalog::GOVERNANCE_RECORDS_MANAGE,
        ) === null) {
            return null;
        }

        return DB::transaction(function () use (
            $user,
            $business,
            $meetingId,
        ): ?bool {
            $meeting = DB::table('governance_meetings')
                ->where('business_id', $business->getKey())
                ->where('id', $meetingId)
                ->lockForUpdate()
                ->first();

            if ($meeting === null || $meeting->status !== 'scheduled') {
                return null;
            }

            DB::table('governance_meetings')
                ->where('business_id', $business->getKey())
                ->where('id', $meetingId)
                ->update([
                    'status' => 'cancelled',
                    'cancelled_at' => now(),
                    'revision' => ((int) $meeting->revision) + 1,
                    'updated_at' => now(),
                ]);

            $this->occurrence->record(
                $user,
                $business,
                'governance.meeting.cancelled',
                'governance_meeting',
                $meetingId,
            );

            return true;
        });
    }
}
