<?php

declare(strict_types=1);

namespace App\Application\Conflict;

use App\Domain\Conflict\Enums\ConflictCaseStage;
use App\Domain\Conflict\Enums\ConflictType;
use App\Domain\Records\Exceptions\StaleRevision;
use App\Infrastructure\Persistence\Eloquent\Businesses\Business;
use App\Infrastructure\Persistence\Eloquent\Conflict\ConflictCase;
use App\Infrastructure\Persistence\Eloquent\Conflict\ConflictInvestigation;
use App\Infrastructure\Persistence\Eloquent\Identity\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;

final class ConflictInvestigationWorkflow
{
    public function __construct(
        private readonly ConflictRecordVisibility $visibility,
        private readonly ConflictCaseWorkflow $cases,
        private readonly RecordConflictOccurrence $occurrence,
    ) {}

    public function open(
        User $user,
        Business $business,
        string $caseId,
        int $expectedCaseRevision,
        string $investigationOwnerMembershipId,
        string $allegation,
        ?string $temporaryRestrictionProposal = null,
    ): ?string {
        if (! $this->visibility->canManage($user, $business, $caseId)) {
            return null;
        }

        $allegation = trim($allegation);

        if (
            $allegation === ''
            || ! $this->activeMember(
                $business,
                $investigationOwnerMembershipId,
            )
        ) {
            throw new InvalidArgumentException(
                'Conflict Investigation requires an allegation and active owner.',
            );
        }

        return DB::transaction(function () use (
            $user,
            $business,
            $caseId,
            $expectedCaseRevision,
            $investigationOwnerMembershipId,
            $allegation,
            $temporaryRestrictionProposal,
        ): ?string {
            $case = ConflictCase::query()
                ->where('business_id', $business->getKey())
                ->whereKey($caseId)
                ->first();

            if ($case === null) {
                return null;
            }

            if ((int) $case->revision !== $expectedCaseRevision) {
                throw new StaleRevision(
                    $expectedCaseRevision,
                    (int) $case->revision,
                );
            }

            if (! in_array(
                $case->conflict_type,
                [
                    ConflictType::Misconduct,
                    ConflictType::AgreementBreach,
                ],
                true,
            )) {
                return null;
            }

            if ($case->stage !== ConflictCaseStage::MisconductInvestigation) {
                if ($this->cases->transition(
                    $user,
                    $business,
                    $caseId,
                    $expectedCaseRevision,
                    ConflictCaseStage::MisconductInvestigation,
                    'investigation_opened',
                ) === null) {
                    return null;
                }
            }

            $actor = $this->activeActorMembershipId($user, $business);

            if ($actor === null) {
                return null;
            }

            $id = (string) Str::uuid7();

            ConflictInvestigation::query()->create([
                'id' => $id,
                'business_id' => $business->getKey(),
                'conflict_case_id' => $caseId,
                'allegation' => $allegation,
                'investigation_owner_membership_id' => $investigationOwnerMembershipId,
                'temporary_restriction_proposal' => $this->nullableText(
                    $temporaryRestrictionProposal,
                ),
                'status' => 'open',
                'revision' => 1,
                'opened_at' => now(),
                'completed_at' => null,
                'created_by_membership_id' => $actor,
            ]);

            $this->occurrence->record(
                $user,
                $business,
                'conflict.investigation.opened',
                $caseId,
                ['status' => 'open'],
            );

            return $id;
        });
    }

    public function completeWithFinding(
        User $user,
        Business $business,
        string $caseId,
        string $investigationId,
        int $expectedCaseRevision,
        int $expectedInvestigationRevision,
        string $finding,
        ?string $sanctionRemedyRecommendation = null,
        ?string $appealReference = null,
        bool $exitTriggerRecommended = false,
    ): bool {
        if (! $this->visibility->canManage($user, $business, $caseId)) {
            return false;
        }

        $finding = trim($finding);

        if ($finding === '') {
            throw new InvalidArgumentException(
                'Conflict Investigation finding is required.',
            );
        }

        return DB::transaction(function () use (
            $user,
            $business,
            $caseId,
            $investigationId,
            $expectedCaseRevision,
            $expectedInvestigationRevision,
            $finding,
            $sanctionRemedyRecommendation,
            $appealReference,
            $exitTriggerRecommended,
        ): bool {
            $case = ConflictCase::query()
                ->where('business_id', $business->getKey())
                ->whereKey($caseId)
                ->lockForUpdate()
                ->first();

            $investigation = ConflictInvestigation::query()
                ->where('business_id', $business->getKey())
                ->where('conflict_case_id', $caseId)
                ->whereKey($investigationId)
                ->lockForUpdate()
                ->first();

            if (
                $case === null
                || $case->stage !== ConflictCaseStage::MisconductInvestigation
                || $investigation === null
                || ! in_array(
                    $investigation->status,
                    ['open', 'investigating'],
                    true,
                )
            ) {
                return false;
            }

            if ((int) $case->revision !== $expectedCaseRevision) {
                throw new StaleRevision(
                    $expectedCaseRevision,
                    (int) $case->revision,
                );
            }

            if (
                (int) $investigation->revision
                !== $expectedInvestigationRevision
            ) {
                throw new StaleRevision(
                    $expectedInvestigationRevision,
                    (int) $investigation->revision,
                );
            }

            $actor = $this->activeActorMembershipId($user, $business);

            if ($actor === null) {
                return false;
            }

            DB::table('conflict_investigation_findings')->insert([
                'id' => (string) Str::uuid7(),
                'business_id' => $business->getKey(),
                'conflict_investigation_id' => $investigationId,
                'finding' => $finding,
                'sanction_remedy_recommendation' => $this->nullableText(
                    $sanctionRemedyRecommendation,
                ),
                'appeal_reference' => $this->nullableText($appealReference),
                'exit_trigger_recommended' => $exitTriggerRecommended,
                'recorded_by_membership_id' => $actor,
                'recorded_at' => now(),
                'created_at' => now(),
            ]);

            $updated = ConflictInvestigation::query()
                ->where('business_id', $business->getKey())
                ->whereKey($investigationId)
                ->where('revision', $expectedInvestigationRevision)
                ->update([
                    'status' => 'finding_recorded',
                    'revision' => $expectedInvestigationRevision + 1,
                    'completed_at' => now(),
                ]);

            if ($updated !== 1) {
                return false;
            }

            if ($this->cases->transition(
                $user,
                $business,
                $caseId,
                $expectedCaseRevision,
                ConflictCaseStage::FormalDecision,
                'investigation_finding_recorded',
            ) === null) {
                return false;
            }

            $this->occurrence->record(
                $user,
                $business,
                'conflict.investigation.finding_recorded',
                $caseId,
                [
                    'status' => 'finding_recorded',
                    'exit_trigger_recommended' => $exitTriggerRecommended,
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
