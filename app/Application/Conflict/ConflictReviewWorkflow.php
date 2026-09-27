<?php

declare(strict_types=1);

namespace App\Application\Conflict;

use App\Domain\Records\Exceptions\StaleRevision;
use App\Infrastructure\Persistence\Eloquent\Businesses\Business;
use App\Infrastructure\Persistence\Eloquent\Identity\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;

final class ConflictReviewWorkflow
{
    public function __construct(
        private readonly ConflictRecordVisibility $visibility,
        private readonly RecordConflictOccurrence $occurrence,
    ) {}

    public function create(
        User $user,
        Business $business,
        string $caseId,
        string $reviewerMembershipId,
        ?\DateTimeInterface $dueAt = null,
    ): ?string {
        if (! $this->visibility->canManage($user, $business, $caseId)) {
            return null;
        }

        $actor = $this->activeActorMembershipId($user, $business);

        if (
            $actor === null
            || ! $this->activeMember($business, $reviewerMembershipId)
        ) {
            return null;
        }

        $id = (string) Str::uuid7();

        DB::table('conflict_case_reviews')->insert([
            'id' => $id,
            'business_id' => $business->getKey(),
            'conflict_case_id' => $caseId,
            'reviewer_membership_id' => $reviewerMembershipId,
            'created_by_membership_id' => $actor,
            'due_at' => $dueAt,
            'status' => 'open',
            'outcome' => null,
            'notes' => null,
            'resolved_at' => null,
            'revision' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->occurrence->record(
            $user,
            $business,
            'conflict.review.created',
            $caseId,
            ['status' => 'open'],
        );

        return $id;
    }

    public function complete(
        User $user,
        Business $business,
        string $caseId,
        string $reviewId,
        int $expectedRevision,
        string $outcome,
        ?string $notes = null,
    ): bool {
        if (! $this->visibility->canManage($user, $business, $caseId)) {
            return false;
        }

        if (! in_array(
            $outcome,
            ['continue', 'resolved', 'escalate', 'amend_policy'],
            true,
        )) {
            throw new InvalidArgumentException(
                'Conflict Review outcome is invalid.',
            );
        }

        return DB::transaction(function () use (
            $user,
            $business,
            $caseId,
            $reviewId,
            $expectedRevision,
            $outcome,
            $notes,
        ): bool {
            $row = DB::table('conflict_case_reviews')
                ->where('business_id', $business->getKey())
                ->where('conflict_case_id', $caseId)
                ->where('id', $reviewId)
                ->lockForUpdate()
                ->first();

            if ($row === null || $row->status !== 'open') {
                return false;
            }

            if ((int) $row->revision !== $expectedRevision) {
                throw new StaleRevision(
                    $expectedRevision,
                    (int) $row->revision,
                );
            }

            $actor = $this->activeActorMembershipId($user, $business);

            if (
                $actor === null
                || $actor !== (string) $row->reviewer_membership_id
            ) {
                return false;
            }

            $updated = DB::table('conflict_case_reviews')
                ->where('business_id', $business->getKey())
                ->where('id', $reviewId)
                ->where('revision', $expectedRevision)
                ->update([
                    'status' => 'completed',
                    'outcome' => $outcome,
                    'notes' => $this->nullableText($notes),
                    'resolved_at' => now(),
                    'revision' => $expectedRevision + 1,
                    'updated_at' => now(),
                ]);

            if ($updated !== 1) {
                return false;
            }

            $this->occurrence->record(
                $user,
                $business,
                'conflict.review.completed',
                $caseId,
                [
                    'status' => 'completed',
                    'outcome' => $outcome,
                ],
            );

            return true;
        });
    }

    private function activeActorMembershipId(
        User $user,
        Business $business,
    ): ?string {
        $id = DB::table('memberships')
            ->where('business_id', $business->getKey())
            ->where('user_id', $user->getKey())
            ->where('access_status', 'active')
            ->value('id');

        return $id === null ? null : (string) $id;
    }

    private function activeMember(
        Business $business,
        string $membershipId,
    ): bool {
        return DB::table('memberships')
            ->where('business_id', $business->getKey())
            ->where('id', $membershipId)
            ->where('access_status', 'active')
            ->exists();
    }

    private function nullableText(mixed $value): ?string
    {
        $text = trim((string) ($value ?? ''));

        return $text === '' ? null : $text;
    }
}
