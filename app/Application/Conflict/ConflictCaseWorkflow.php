<?php

declare(strict_types=1);

namespace App\Application\Conflict;

use App\Application\Governance\GovernanceActorContext;
use App\Domain\Access\CapabilityCatalog;
use App\Domain\Conflict\Enums\ConflictCaseStage;
use App\Domain\Conflict\Enums\ConflictCaseStatus;
use App\Domain\Conflict\Enums\ConflictType;
use App\Domain\Conflict\Services\ConflictCaseStateMachine;
use App\Domain\Records\Exceptions\StaleRevision;
use App\Infrastructure\Persistence\Eloquent\Businesses\Business;
use App\Infrastructure\Persistence\Eloquent\Conflict\ConflictCase;
use App\Infrastructure\Persistence\Eloquent\Identity\User;
use App\Infrastructure\Persistence\Eloquent\Members\Membership;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;

final class ConflictCaseWorkflow
{
    public function __construct(
        private readonly GovernanceActorContext $actorContext,
        private readonly ConflictRecordVisibility $visibility,
        private readonly ConflictCaseStateMachine $stateMachine,
        private readonly RecordConflictOccurrence $occurrence,
    ) {}

    /**
     * @param  array<string,mixed>  $payload
     * @return array{id:string,case_number:string,revision:int}|null
     */
    public function open(
        User $user,
        Business $business,
        array $payload,
    ): ?array {
        $creator = $this->actorContext->membership(
            $user,
            $business,
            CapabilityCatalog::CONFLICT_MANAGE,
        );

        if ($creator === null) {
            return null;
        }

        $policy = $this->currentEffectivePolicy($business);

        if ($policy === null) {
            throw new InvalidArgumentException(
                'A Current Effective Conflict Resolution Procedure is required before opening a Conflict Case.',
            );
        }

        $type = ConflictType::tryFrom(
            trim((string) ($payload['conflict_type'] ?? '')),
        );

        $description = trim((string) ($payload['description'] ?? ''));
        $impact = trim((string) ($payload['business_impact'] ?? ''));
        $urgency = trim((string) ($payload['urgency'] ?? 'normal'));

        if (
            $type === null
            || $description === ''
            || $impact === ''
            || ! in_array(
                $urgency,
                ['low', 'normal', 'high', 'critical'],
                true,
            )
        ) {
            throw new InvalidArgumentException(
                'Conflict Case classification and impact are required.',
            );
        }

        $ownerId = (string) $policy->conflict_owner_membership_id;

        if (! $this->activeMember($business, $ownerId)) {
            throw new InvalidArgumentException(
                'Conflict Procedure owner is no longer an active Membership.',
            );
        }

        $participants = $this->normalizeParticipants(
            $business,
            (array) ($payload['participants'] ?? []),
        );

        if (
            count(array_filter(
                $participants,
                static fn (array $row): bool => $row['participant_role'] === 'party',
            )) < 1
        ) {
            throw new InvalidArgumentException(
                'Conflict Case requires at least one recorded party.',
            );
        }

        $viewIds = $this->normalizeAccessIds(
            $business,
            (array) ($payload['view_membership_ids'] ?? []),
        );
        $manageIds = $this->normalizeAccessIds(
            $business,
            (array) ($payload['manage_membership_ids'] ?? []),
        );

        return DB::transaction(function () use (
            $user,
            $business,
            $creator,
            $policy,
            $type,
            $description,
            $impact,
            $urgency,
            $ownerId,
            $participants,
            $viewIds,
            $manageIds,
            $payload,
        ): array {
            $caseId = (string) Str::uuid7();
            $caseNumber = 'CON-'.strtoupper(substr(
                str_replace('-', '', $caseId),
                0,
                16,
            ));

            $case = ConflictCase::query()->create([
                'id' => $caseId,
                'business_id' => $business->getKey(),
                'case_number' => $caseNumber,
                'conflict_policy_formal_record_version_id' => $policy->formal_record_version_id,
                'raised_at' => now(),
                'raised_by_membership_id' => $creator->getKey(),
                'conflict_owner_membership_id' => $ownerId,
                'conflict_type' => $type->value,
                'description' => $description,
                'business_impact' => $impact,
                'urgency' => $urgency,
                'related_rule_reference' => $this->nullableText(
                    $payload['related_rule_reference'] ?? null,
                ),
                'confidentiality' => 'restricted',
                'stage' => ConflictCaseStage::Intake->value,
                'status' => ConflictCaseStatus::Open->value,
                'review_due_at' => $payload['review_due_at'] ?? null,
                'resolved_at' => null,
                'resolution_source_type' => null,
                'resolution_source_id' => null,
                'revision' => 1,
            ]);

            foreach ($participants as $row) {
                DB::table('conflict_case_participants')->insert([
                    'id' => (string) Str::uuid7(),
                    'business_id' => $business->getKey(),
                    'conflict_case_id' => $caseId,
                    ...$row,
                    'status' => 'active',
                    'added_at' => now(),
                    'removed_at' => null,
                ]);
            }

            DB::table('conflict_case_updates')->insert([
                'id' => (string) Str::uuid7(),
                'business_id' => $business->getKey(),
                'conflict_case_id' => $caseId,
                'sequence' => 1,
                'from_stage' => ConflictCaseStage::Intake->value,
                'to_stage' => ConflictCaseStage::Intake->value,
                'from_status' => ConflictCaseStatus::Open->value,
                'to_status' => ConflictCaseStatus::Open->value,
                'note_code' => 'case_opened',
                'actor_membership_id' => $creator->getKey(),
                'occurred_at' => now(),
                'created_at' => now(),
            ]);

            $this->visibility->grantRestrictedAccess(
                $business,
                $caseId,
                array_values(array_unique([
                    (string) $creator->getKey(),
                    $ownerId,
                    ...$manageIds,
                ])),
                true,
            );

            $viewOnly = array_values(array_diff(
                $viewIds,
                [
                    (string) $creator->getKey(),
                    $ownerId,
                    ...$manageIds,
                ],
            ));

            $this->visibility->grantRestrictedAccess(
                $business,
                $caseId,
                $viewOnly,
                false,
            );

            $this->occurrence->record(
                $user,
                $business,
                'conflict.case.opened',
                $caseId,
                [
                    'stage' => ConflictCaseStage::Intake->value,
                    'status' => ConflictCaseStatus::Open->value,
                    'revision' => 1,
                ],
            );

            return [
                'id' => (string) $case->getKey(),
                'case_number' => $caseNumber,
                'revision' => 1,
            ];
        });
    }

    public function transition(
        User $user,
        Business $business,
        string $caseId,
        int $expectedRevision,
        ConflictCaseStage $target,
        ?string $noteCode = null,
        ?string $resolutionSourceType = null,
        ?string $resolutionSourceId = null,
    ): ?ConflictCase {
        if (! $this->visibility->canManage($user, $business, $caseId)) {
            return null;
        }

        return DB::transaction(function () use (
            $user,
            $business,
            $caseId,
            $expectedRevision,
            $target,
            $noteCode,
            $resolutionSourceType,
            $resolutionSourceId,
        ): ?ConflictCase {
            $case = ConflictCase::query()
                ->where('business_id', $business->getKey())
                ->whereKey($caseId)
                ->lockForUpdate()
                ->first();

            if ($case === null) {
                return null;
            }

            $actualRevision = (int) $case->revision;

            if ($actualRevision !== $expectedRevision) {
                throw new StaleRevision(
                    $expectedRevision,
                    $actualRevision,
                );
            }

            $fromStage = $case->stage;
            $fromStatus = $case->status;

            $this->stateMachine->assertStageTransition(
                $fromStage,
                $fromStatus,
                $target,
            );

            $targetStatus = $this->stateMachine->statusForStage($target);

            if ($target === ConflictCaseStage::Resolved) {
                if (
                    ($resolutionSourceType === null)
                    !== ($resolutionSourceId === null)
                ) {
                    throw new InvalidArgumentException(
                        'Conflict resolution source type/id must be bound together.',
                    );
                }

                if (
                    $resolutionSourceId !== null
                    && ! Str::isUuid($resolutionSourceId)
                ) {
                    throw new InvalidArgumentException(
                        'Conflict resolution source ID must be a UUID.',
                    );
                }
            } elseif (
                $resolutionSourceType !== null
                || $resolutionSourceId !== null
            ) {
                throw new InvalidArgumentException(
                    'Resolution source can only be recorded when resolving the case.',
                );
            }

            $updated = ConflictCase::query()
                ->where('business_id', $business->getKey())
                ->whereKey($caseId)
                ->where('revision', $expectedRevision)
                ->update([
                    'stage' => $target->value,
                    'status' => $targetStatus->value,
                    'resolved_at' => $target === ConflictCaseStage::Resolved
                        ? now()
                        : null,
                    'resolution_source_type' => $resolutionSourceType,
                    'resolution_source_id' => $resolutionSourceId,
                    'revision' => $expectedRevision + 1,
                    'updated_at' => now(),
                ]);

            if ($updated !== 1) {
                $current = ConflictCase::query()
                    ->where('business_id', $business->getKey())
                    ->whereKey($caseId)
                    ->value('revision');

                throw new StaleRevision(
                    $expectedRevision,
                    (int) $current,
                );
            }

            $membership = $this->actorContext->membership(
                $user,
                $business,
                CapabilityCatalog::CONFLICT_MANAGE,
            );

            if ($membership === null) {
                return null;
            }

            $sequence = (int) DB::table('conflict_case_updates')
                ->where('business_id', $business->getKey())
                ->where('conflict_case_id', $caseId)
                ->max('sequence') + 1;

            DB::table('conflict_case_updates')->insert([
                'id' => (string) Str::uuid7(),
                'business_id' => $business->getKey(),
                'conflict_case_id' => $caseId,
                'sequence' => $sequence,
                'from_stage' => $fromStage->value,
                'to_stage' => $target->value,
                'from_status' => $fromStatus->value,
                'to_status' => $targetStatus->value,
                'note_code' => $this->safeNoteCode($noteCode),
                'actor_membership_id' => $membership->getKey(),
                'occurred_at' => now(),
                'created_at' => now(),
            ]);

            $this->occurrence->record(
                $user,
                $business,
                'conflict.case.transitioned',
                $caseId,
                [
                    'from_stage' => $fromStage->value,
                    'to_stage' => $target->value,
                    'from_status' => $fromStatus->value,
                    'to_status' => $targetStatus->value,
                    'revision' => $expectedRevision + 1,
                ],
            );

            return $case->fresh();
        });
    }

    public function close(
        User $user,
        Business $business,
        string $caseId,
        int $expectedRevision,
    ): ?ConflictCase {
        if (! $this->visibility->canManage($user, $business, $caseId)) {
            return null;
        }

        return DB::transaction(function () use (
            $user,
            $business,
            $caseId,
            $expectedRevision,
        ): ?ConflictCase {
            $case = ConflictCase::query()
                ->where('business_id', $business->getKey())
                ->whereKey($caseId)
                ->lockForUpdate()
                ->first();

            if ($case === null) {
                return null;
            }

            $actualRevision = (int) $case->revision;

            if ($actualRevision !== $expectedRevision) {
                throw new StaleRevision(
                    $expectedRevision,
                    $actualRevision,
                );
            }

            $this->stateMachine->assertClose(
                $case->stage,
                $case->status,
            );

            ConflictCase::query()
                ->where('business_id', $business->getKey())
                ->whereKey($caseId)
                ->where('revision', $expectedRevision)
                ->update([
                    'status' => ConflictCaseStatus::Closed->value,
                    'revision' => $expectedRevision + 1,
                    'updated_at' => now(),
                ]);

            $membership = $this->actorContext->membership(
                $user,
                $business,
                CapabilityCatalog::CONFLICT_MANAGE,
            );

            if ($membership === null) {
                return null;
            }

            $sequence = (int) DB::table('conflict_case_updates')
                ->where('business_id', $business->getKey())
                ->where('conflict_case_id', $caseId)
                ->max('sequence') + 1;

            DB::table('conflict_case_updates')->insert([
                'id' => (string) Str::uuid7(),
                'business_id' => $business->getKey(),
                'conflict_case_id' => $caseId,
                'sequence' => $sequence,
                'from_stage' => ConflictCaseStage::Resolved->value,
                'to_stage' => ConflictCaseStage::Resolved->value,
                'from_status' => ConflictCaseStatus::Resolved->value,
                'to_status' => ConflictCaseStatus::Closed->value,
                'note_code' => 'case_closed',
                'actor_membership_id' => $membership->getKey(),
                'occurred_at' => now(),
                'created_at' => now(),
            ]);

            $this->occurrence->record(
                $user,
                $business,
                'conflict.case.closed',
                $caseId,
                [
                    'stage' => ConflictCaseStage::Resolved->value,
                    'status' => ConflictCaseStatus::Closed->value,
                    'revision' => $expectedRevision + 1,
                ],
            );

            return $case->fresh();
        });
    }

    /**
     * Explicit case authorization only; participant status is irrelevant.
     *
     * @param  list<string>  $membershipIds
     */
    public function grantAccess(
        User $user,
        Business $business,
        string $caseId,
        array $membershipIds,
        bool $manage = false,
    ): bool {
        if (! $this->visibility->canManage($user, $business, $caseId)) {
            return false;
        }

        $this->visibility->grantRestrictedAccess(
            $business,
            $caseId,
            $this->normalizeAccessIds($business, $membershipIds),
            $manage,
        );

        $this->occurrence->record(
            $user,
            $business,
            'conflict.case.access_granted',
            $caseId,
            ['manage' => $manage],
        );

        return true;
    }

    /** @param list<string> $membershipIds */
    public function denyAccess(
        User $user,
        Business $business,
        string $caseId,
        array $membershipIds,
    ): bool {
        if (! $this->visibility->canManage($user, $business, $caseId)) {
            return false;
        }

        $this->visibility->denyRestrictedAccess(
            $business,
            $caseId,
            $this->normalizeMembershipIds(
                $business,
                $membershipIds,
                false,
            ),
        );

        $this->occurrence->record(
            $user,
            $business,
            'conflict.case.access_denied',
            $caseId,
        );

        return true;
    }

    private function currentEffectivePolicy(Business $business): ?object
    {
        return DB::table('record_family_effective_heads as h')
            ->join(
                'formal_record_versions as v',
                'v.id',
                '=',
                'h.formal_record_version_id',
            )
            ->join(
                'formal_record_families as f',
                'f.id',
                '=',
                'v.formal_record_family_id',
            )
            ->join(
                'conflict_policy_versions as p',
                function ($join): void {
                    $join
                        ->on(
                            'p.formal_record_version_id',
                            '=',
                            'v.id',
                        )
                        ->on('p.business_id', '=', 'v.business_id');
                },
            )
            ->where('h.business_id', $business->getKey())
            ->where('f.business_id', $business->getKey())
            ->where('f.record_type', 'conflict_resolution_policy')
            ->first([
                'p.formal_record_version_id',
                'p.conflict_owner_membership_id',
            ]);
    }

    /**
     * @param  array<int,mixed>  $rows
     * @return list<array<string,mixed>>
     */
    private function normalizeParticipants(
        Business $business,
        array $rows,
    ): array {
        $allowedRoles = [
            'party',
            'conflict_owner',
            'mediator',
            'investigator',
            'advisor',
            'legal',
            'observer',
        ];
        $result = [];

        foreach (array_values($rows) as $raw) {
            if (! is_array($raw)) {
                throw new InvalidArgumentException(
                    'Conflict participant must be structured.',
                );
            }

            $role = trim((string) ($raw['participant_role'] ?? ''));
            $membershipId = $this->nullableText(
                $raw['membership_id'] ?? null,
            );
            $external = $this->nullableText(
                $raw['external_reference'] ?? null,
            );

            if (
                ! in_array($role, $allowedRoles, true)
                || ($membershipId === null) === ($external === null)
            ) {
                throw new InvalidArgumentException(
                    'Conflict participant identity/role is invalid.',
                );
            }

            if (
                $membershipId !== null
                && ! $this->activeMember($business, $membershipId)
            ) {
                throw new InvalidArgumentException(
                    'Conflict participant Membership must be active and same-Business.',
                );
            }

            $result[] = [
                'membership_id' => $membershipId,
                'external_reference' => $external,
                'participant_role' => $role,
            ];
        }

        return $result;
    }

    /** @param array<int,mixed> $ids @return list<string> */
    private function normalizeAccessIds(
        Business $business,
        array $ids,
    ): array {
        return $this->normalizeMembershipIds($business, $ids, true);
    }

    /**
     * @param  array<int,mixed>  $ids
     * @return list<string>
     */
    private function normalizeMembershipIds(
        Business $business,
        array $ids,
        bool $activeOnly,
    ): array {
        $normalized = array_values(array_unique(array_filter(
            array_map(
                static fn (mixed $id): string => trim((string) $id),
                $ids,
            ),
            static fn (string $id): bool => Str::isUuid($id),
        )));

        if ($normalized === []) {
            return [];
        }

        $query = Membership::query()
            ->where('business_id', $business->getKey())
            ->whereIn('id', $normalized);

        if ($activeOnly) {
            $query->where('access_status', 'active');
        }

        $valid = $query
            ->pluck('id')
            ->map(static fn ($id): string => (string) $id)
            ->all();

        if (count($valid) !== count($normalized)) {
            throw new InvalidArgumentException(
                'Conflict access Membership must exist in the same Business.',
            );
        }

        return array_values($valid);
    }

    private function activeMember(Business $business, string $id): bool
    {
        return $id !== '' && Membership::query()
            ->where('business_id', $business->getKey())
            ->whereKey($id)
            ->where('access_status', 'active')
            ->exists();
    }

    private function safeNoteCode(?string $value): ?string
    {
        $value = $this->nullableText($value);

        if ($value === null) {
            return null;
        }

        if (preg_match('/^[a-z0-9_:-]{1,120}$/', $value) !== 1) {
            throw new InvalidArgumentException(
                'Conflict transition note code must be opaque.',
            );
        }

        return $value;
    }

    private function nullableText(mixed $value): ?string
    {
        $text = trim((string) ($value ?? ''));

        return $text === '' ? null : $text;
    }
}
