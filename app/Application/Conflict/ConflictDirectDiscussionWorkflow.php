<?php

declare(strict_types=1);

namespace App\Application\Conflict;

use App\Domain\Conflict\Enums\ConflictCaseStage;
use App\Domain\Conflict\Enums\DirectDiscussionOutcome;
use App\Domain\Records\Exceptions\StaleRevision;
use App\Infrastructure\Persistence\Eloquent\Businesses\Business;
use App\Infrastructure\Persistence\Eloquent\Conflict\ConflictCase;
use App\Infrastructure\Persistence\Eloquent\Identity\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;

final class ConflictDirectDiscussionWorkflow
{
    public function __construct(
        private readonly ConflictRecordVisibility $visibility,
        private readonly ConflictCaseWorkflow $cases,
        private readonly RecordConflictOccurrence $occurrence,
    ) {}

    /**
     * @param  list<string>  $participantMembershipIds
     */
    public function record(
        User $user,
        Business $business,
        string $caseId,
        int $expectedCaseRevision,
        string $issuesDiscussed,
        string $partyPositionSummary,
        string $proposedSolutions,
        DirectDiscussionOutcome $outcome,
        array $participantMembershipIds,
        ?\DateTimeInterface $meetingAt = null,
        ?\DateTimeInterface $followUpAt = null,
    ): ?string {
        if (! $this->visibility->canManage($user, $business, $caseId)) {
            return null;
        }

        $issuesDiscussed = trim($issuesDiscussed);
        $partyPositionSummary = trim($partyPositionSummary);
        $proposedSolutions = trim($proposedSolutions);

        if (
            $issuesDiscussed === ''
            || $partyPositionSummary === ''
            || $proposedSolutions === ''
        ) {
            throw new InvalidArgumentException(
                'Direct Discussion requires issues, party positions and proposed solutions.',
            );
        }

        $participantIds = array_values(array_unique($participantMembershipIds));

        if ($participantIds === []) {
            throw new InvalidArgumentException(
                'Direct Discussion requires at least one case participant.',
            );
        }

        return DB::transaction(function () use (
            $user,
            $business,
            $caseId,
            $expectedCaseRevision,
            $issuesDiscussed,
            $partyPositionSummary,
            $proposedSolutions,
            $outcome,
            $participantIds,
            $meetingAt,
            $followUpAt,
        ): ?string {
            $case = ConflictCase::query()
                ->where('business_id', $business->getKey())
                ->whereKey($caseId)
                ->lockForUpdate()
                ->first();

            if (
                $case === null
                || $case->stage !== ConflictCaseStage::DirectDiscussion
            ) {
                return null;
            }

            if ((int) $case->revision !== $expectedCaseRevision) {
                throw new StaleRevision(
                    $expectedCaseRevision,
                    (int) $case->revision,
                );
            }

            $validParticipants = DB::table('conflict_case_participants')
                ->where('business_id', $business->getKey())
                ->where('conflict_case_id', $caseId)
                ->where('status', 'active')
                ->whereIn('membership_id', $participantIds)
                ->pluck('membership_id')
                ->filter()
                ->map(static fn ($id): string => (string) $id)
                ->unique()
                ->values()
                ->all();

            sort($validParticipants);
            $expectedParticipants = $participantIds;
            sort($expectedParticipants);

            if ($validParticipants !== $expectedParticipants) {
                throw new InvalidArgumentException(
                    'Direct Discussion participants must be active recorded case participants.',
                );
            }

            $membership = DB::table('memberships')
                ->join('users', 'users.id', '=', 'memberships.user_id')
                ->where('memberships.business_id', $business->getKey())
                ->where('users.id', $user->getKey())
                ->where('memberships.access_status', 'active')
                ->value('memberships.id');

            if ($membership === null) {
                return null;
            }

            $discussionId = (string) Str::uuid7();

            DB::table('conflict_direct_discussions')->insert([
                'id' => $discussionId,
                'business_id' => $business->getKey(),
                'conflict_case_id' => $caseId,
                'meeting_at' => $meetingAt ?? now(),
                'issues_discussed' => $issuesDiscussed,
                'party_position_summary' => $partyPositionSummary,
                'proposed_solutions' => $proposedSolutions,
                'outcome' => $outcome->value,
                'follow_up_at' => $followUpAt,
                'recorded_by_membership_id' => $membership,
                'created_at' => now(),
            ]);

            foreach ($participantIds as $participantId) {
                DB::table(
                    'conflict_direct_discussion_participants',
                )->insert([
                    'id' => (string) Str::uuid7(),
                    'business_id' => $business->getKey(),
                    'conflict_direct_discussion_id' => $discussionId,
                    'membership_id' => $participantId,
                    'created_at' => now(),
                ]);
            }

            $target = match ($outcome) {
                DirectDiscussionOutcome::Resolved => ConflictCaseStage::Resolved,
                DirectDiscussionOutcome::ContinueMediation => ConflictCaseStage::Mediation,
                DirectDiscussionOutcome::ContinueFormalDecision => ConflictCaseStage::FormalDecision,
            };

            $transitioned = $this->cases->transition(
                $user,
                $business,
                $caseId,
                $expectedCaseRevision,
                $target,
                'direct_discussion_'.$outcome->value,
                $outcome === DirectDiscussionOutcome::Resolved
                    ? 'direct_discussion'
                    : null,
                $outcome === DirectDiscussionOutcome::Resolved
                    ? $discussionId
                    : null,
            );

            if ($transitioned === null) {
                return null;
            }

            $this->occurrence->record(
                $user,
                $business,
                'conflict.direct_discussion.recorded',
                $caseId,
                [
                    'outcome' => $outcome->value,
                    'revision' => $expectedCaseRevision + 1,
                ],
            );

            return $discussionId;
        });
    }
}
