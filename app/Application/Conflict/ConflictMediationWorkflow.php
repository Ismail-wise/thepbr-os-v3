<?php

declare(strict_types=1);

namespace App\Application\Conflict;

use App\Domain\Conflict\Enums\ConflictCaseStage;
use App\Domain\Conflict\Enums\MediationResponseOutcome;
use App\Domain\Records\Exceptions\StaleRevision;
use App\Infrastructure\Persistence\Eloquent\Businesses\Business;
use App\Infrastructure\Persistence\Eloquent\Conflict\ConflictCase;
use App\Infrastructure\Persistence\Eloquent\Conflict\ConflictMediation;
use App\Infrastructure\Persistence\Eloquent\Identity\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;

final class ConflictMediationWorkflow
{
    public function __construct(
        private readonly ConflictRecordVisibility $visibility,
        private readonly ConflictCaseWorkflow $cases,
        private readonly RecordConflictOccurrence $occurrence,
    ) {}

    public function schedule(
        User $user,
        Business $business,
        string $caseId,
        int $expectedCaseRevision,
        string $mediatorType,
        string $neutralityCheck,
        \DateTimeInterface $mediationAt,
        ?string $mediatorMembershipId = null,
        ?string $externalMediatorReference = null,
        ?\DateTimeInterface $responseDeadline = null,
    ): ?string {
        if (! $this->visibility->canManage($user, $business, $caseId)) {
            return null;
        }

        $mediatorType = trim($mediatorType);
        $neutralityCheck = trim($neutralityCheck);
        $externalMediatorReference = $this->nullableText(
            $externalMediatorReference,
        );

        if (
            ! in_array($mediatorType, ['internal', 'external'], true)
            || $neutralityCheck === ''
        ) {
            throw new InvalidArgumentException(
                'Mediation type and neutrality/conflict check are required.',
            );
        }

        if ($mediatorType === 'internal') {
            if (
                $mediatorMembershipId === null
                || ! $this->activeMember(
                    $business,
                    $mediatorMembershipId,
                )
                || $externalMediatorReference !== null
            ) {
                throw new InvalidArgumentException(
                    'Internal mediation requires one active same-Business mediator.',
                );
            }
        } elseif (
            $mediatorMembershipId !== null
            || $externalMediatorReference === null
        ) {
            throw new InvalidArgumentException(
                'External mediation requires an external mediator reference only.',
            );
        }

        return DB::transaction(function () use (
            $user,
            $business,
            $caseId,
            $expectedCaseRevision,
            $mediatorType,
            $neutralityCheck,
            $mediationAt,
            $mediatorMembershipId,
            $externalMediatorReference,
            $responseDeadline,
        ): ?string {
            $case = ConflictCase::query()
                ->where('business_id', $business->getKey())
                ->whereKey($caseId)
                ->lockForUpdate()
                ->first();

            if (
                $case === null
                || $case->stage !== ConflictCaseStage::Mediation
            ) {
                return null;
            }

            if ((int) $case->revision !== $expectedCaseRevision) {
                throw new StaleRevision(
                    $expectedCaseRevision,
                    (int) $case->revision,
                );
            }

            $creatorId = $this->activeActorMembershipId(
                $user,
                $business,
            );

            if ($creatorId === null) {
                return null;
            }

            if ($mediatorMembershipId !== null) {
                DB::table('conflict_case_participants')->insert([
                    'id' => (string) Str::uuid7(),
                    'business_id' => $business->getKey(),
                    'conflict_case_id' => $caseId,
                    'membership_id' => $mediatorMembershipId,
                    'external_reference' => null,
                    'participant_role' => 'mediator',
                    'status' => 'active',
                    'added_at' => now(),
                    'removed_at' => null,
                ]);
            }

            $mediationId = (string) Str::uuid7();

            DB::table('conflict_mediations')->insert([
                'id' => $mediationId,
                'business_id' => $business->getKey(),
                'conflict_case_id' => $caseId,
                'mediator_type' => $mediatorType,
                'mediator_membership_id' => $mediatorMembershipId,
                'external_mediator_reference' => $externalMediatorReference,
                'mediation_at' => $mediationAt,
                'neutrality_check' => $neutralityCheck,
                'summary' => null,
                'proposed_settlement' => null,
                'response_deadline' => $responseDeadline,
                'status' => 'planned',
                'revision' => 1,
                'created_by_membership_id' => $creatorId,
                'completed_at' => null,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            $this->occurrence->record(
                $user,
                $business,
                'conflict.mediation.scheduled',
                $caseId,
                ['status' => 'planned'],
            );

            return $mediationId;
        });
    }

    public function recordResponse(
        User $user,
        Business $business,
        string $caseId,
        string $mediationId,
        MediationResponseOutcome $response,
        ?string $note = null,
    ): bool {
        if (! $this->visibility->canView($user, $business, $caseId)) {
            return false;
        }

        return DB::transaction(function () use (
            $user,
            $business,
            $caseId,
            $mediationId,
            $response,
            $note,
        ): bool {
            $mediation = ConflictMediation::query()
                ->where('business_id', $business->getKey())
                ->where('conflict_case_id', $caseId)
                ->whereKey($mediationId)
                ->lockForUpdate()
                ->first();

            if (
                $mediation === null
                || $mediation->status !== 'planned'
            ) {
                return false;
            }

            $membershipId = $this->activeActorMembershipId(
                $user,
                $business,
            );

            if ($membershipId === null) {
                return false;
            }

            $isParty = DB::table('conflict_case_participants')
                ->where('business_id', $business->getKey())
                ->where('conflict_case_id', $caseId)
                ->where('membership_id', $membershipId)
                ->where('participant_role', 'party')
                ->where('status', 'active')
                ->exists();

            if (! $isParty) {
                return false;
            }

            $exists = DB::table('conflict_mediation_responses')
                ->where('business_id', $business->getKey())
                ->where('conflict_mediation_id', $mediationId)
                ->where('membership_id', $membershipId)
                ->exists();

            if ($exists) {
                return false;
            }

            DB::table('conflict_mediation_responses')->insert([
                'id' => (string) Str::uuid7(),
                'business_id' => $business->getKey(),
                'conflict_mediation_id' => $mediationId,
                'membership_id' => $membershipId,
                'response' => $response->value,
                'response_note' => $this->nullableText($note),
                'responded_at' => now(),
                'created_at' => now(),
            ]);

            $this->occurrence->record(
                $user,
                $business,
                'conflict.mediation.response_recorded',
                $caseId,
                ['response' => $response->value],
            );

            return true;
        });
    }

    public function complete(
        User $user,
        Business $business,
        string $caseId,
        string $mediationId,
        int $expectedCaseRevision,
        int $expectedMediationRevision,
        string $summary,
        string $proposedSettlement,
    ): ?ConflictMediation {
        if (! $this->visibility->canManage($user, $business, $caseId)) {
            return null;
        }

        $summary = trim($summary);
        $proposedSettlement = trim($proposedSettlement);

        if ($summary === '' || $proposedSettlement === '') {
            throw new InvalidArgumentException(
                'Completed Mediation requires summary and proposed settlement.',
            );
        }

        return DB::transaction(function () use (
            $user,
            $business,
            $caseId,
            $mediationId,
            $expectedCaseRevision,
            $expectedMediationRevision,
            $summary,
            $proposedSettlement,
        ): ?ConflictMediation {
            $case = ConflictCase::query()
                ->where('business_id', $business->getKey())
                ->whereKey($caseId)
                ->lockForUpdate()
                ->first();

            $mediation = ConflictMediation::query()
                ->where('business_id', $business->getKey())
                ->where('conflict_case_id', $caseId)
                ->whereKey($mediationId)
                ->lockForUpdate()
                ->first();

            if (
                $case === null
                || $case->stage !== ConflictCaseStage::Mediation
                || $mediation === null
                || $mediation->status !== 'planned'
            ) {
                return null;
            }

            if ((int) $case->revision !== $expectedCaseRevision) {
                throw new StaleRevision(
                    $expectedCaseRevision,
                    (int) $case->revision,
                );
            }

            if ((int) $mediation->revision !== $expectedMediationRevision) {
                throw new StaleRevision(
                    $expectedMediationRevision,
                    (int) $mediation->revision,
                );
            }

            $partyIds = DB::table('conflict_case_participants')
                ->where('business_id', $business->getKey())
                ->where('conflict_case_id', $caseId)
                ->where('participant_role', 'party')
                ->where('status', 'active')
                ->whereNotNull('membership_id')
                ->pluck('membership_id')
                ->map(static fn ($id): string => (string) $id)
                ->unique()
                ->values()
                ->all();

            if ($partyIds === []) {
                throw new InvalidArgumentException(
                    'Mediation completion requires recorded Membership parties.',
                );
            }

            $responses = DB::table('conflict_mediation_responses')
                ->where('business_id', $business->getKey())
                ->where('conflict_mediation_id', $mediationId)
                ->whereIn('membership_id', $partyIds)
                ->get(['membership_id', 'response']);

            if ($responses->count() !== count($partyIds)) {
                throw new InvalidArgumentException(
                    'All recorded Membership parties must respond before Mediation completes.',
                );
            }

            $allAccepted = $responses->every(
                static fn ($row): bool => $row->response
                        === MediationResponseOutcome::Accepted->value,
            );

            $hasRejected = $responses->contains(
                static fn ($row): bool => $row->response
                        === MediationResponseOutcome::Rejected->value,
            );

            $updated = ConflictMediation::query()
                ->where('business_id', $business->getKey())
                ->whereKey($mediationId)
                ->where('revision', $expectedMediationRevision)
                ->update([
                    'summary' => $summary,
                    'proposed_settlement' => $proposedSettlement,
                    'status' => 'completed',
                    'revision' => $expectedMediationRevision + 1,
                    'completed_at' => now(),
                    'updated_at' => now(),
                ]);

            if ($updated !== 1) {
                $current = ConflictMediation::query()
                    ->where('business_id', $business->getKey())
                    ->whereKey($mediationId)
                    ->value('revision');

                throw new StaleRevision(
                    $expectedMediationRevision,
                    (int) $current,
                );
            }

            if ($allAccepted) {
                $target = ConflictCaseStage::Settlement;
                $noteCode = 'mediation_accepted';
            } elseif ($hasRejected) {
                $target = ConflictCaseStage::FormalDecision;
                $noteCode = 'mediation_rejected';
            } else {
                $target = ConflictCaseStage::Escalation;
                $noteCode = 'mediation_needs_changes';
            }

            if ($this->cases->transition(
                $user,
                $business,
                $caseId,
                $expectedCaseRevision,
                $target,
                $noteCode,
            ) === null) {
                return null;
            }

            $this->occurrence->record(
                $user,
                $business,
                'conflict.mediation.completed',
                $caseId,
                [
                    'status' => 'completed',
                    'accepted' => $allAccepted,
                    'case_revision' => $expectedCaseRevision + 1,
                ],
            );

            return $mediation->fresh();
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
