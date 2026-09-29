<?php

declare(strict_types=1);

namespace App\Application\Continuity;

use App\Application\Governance\GovernanceActorContext;
use App\Application\Governance\MakeGovernedRecordEffective;
use App\Application\Governance\PrepareGovernedRecordForEffect;
use App\Application\Governance\RecordGovernanceOccurrence;
use App\Application\Records\CreateAmendedDraftVersion;
use App\Application\Records\CreateDraftRecordVersion;
use App\Application\Records\CreateFormalRecordFamily;
use App\Application\Records\CreateProposal;
use App\Application\Records\FreezeProposalVersion;
use App\Application\Records\SubmitRecordVersionForReview;
use App\Application\Records\TransitionFormalRecordVersion;
use App\Domain\Access\CapabilityCatalog;
use App\Domain\Access\ValueObjects\Capability;
use App\Domain\Governance\Enums\DecisionOutcome;
use App\Domain\Governance\Enums\DecisionStatus;
use App\Domain\Governance\Exceptions\MissingGovernanceDecision;
use App\Domain\Records\Enums\FormalRecordState;
use App\Domain\Records\ValueObjects\RecordScope;
use App\Infrastructure\Persistence\Eloquent\Businesses\Business;
use App\Infrastructure\Persistence\Eloquent\Governance\Decision;
use App\Infrastructure\Persistence\Eloquent\Identity\User;
use App\Infrastructure\Persistence\Eloquent\Records\FormalRecordFamily;
use App\Infrastructure\Persistence\Eloquent\Records\FormalRecordVersion;
use App\Infrastructure\Persistence\Eloquent\Records\ProposalVersionRecord;
use App\Infrastructure\Persistence\Eloquent\Records\RecordFamilyEffectiveHead;
use DateTimeInterface;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;
use RuntimeException;

final class ContinuityPlanWorkflow
{
    public function __construct(
        private readonly GovernanceActorContext $actorContext,
        private readonly CreateFormalRecordFamily $createFamily,
        private readonly CreateDraftRecordVersion $createDraftVersion,
        private readonly CreateAmendedDraftVersion $createAmendedDraft,
        private readonly SubmitRecordVersionForReview $submitForReview,
        private readonly TransitionFormalRecordVersion $transitionRecord,
        private readonly CreateProposal $createProposal,
        private readonly FreezeProposalVersion $freezeProposal,
        private readonly PrepareGovernedRecordForEffect $prepareEffect,
        private readonly MakeGovernedRecordEffective $makeEffective,
        private readonly RecordGovernanceOccurrence $occurrence,
        private readonly ContinuityRecordVisibility $visibility,
    ) {}

    /** @param array<string,mixed> $payload */
    public function createDraft(
        User $user,
        Business $business,
        array $payload,
        DateTimeInterface $effectiveFrom,
        ?DateTimeInterface $reviewDueAt = null,
    ): ?array {
        $creator = $this->actorContext->membership(
            $user,
            $business,
            CapabilityCatalog::CONTINUITY_MANAGE,
        );

        if (
            $creator === null
            || $this->actorContext->membership(
                $user,
                $business,
                CapabilityCatalog::RECORDS_MANAGE,
            ) === null
        ) {
            return null;
        }

        $normalized = $this->normalize($business, $payload);
        $contentHash = $this->contentHash($normalized);

        return DB::transaction(function () use (
            $user,
            $business,
            $creator,
            $normalized,
            $contentHash,
            $effectiveFrom,
            $reviewDueAt,
        ): ?array {
            $capability = new Capability(CapabilityCatalog::RECORDS_MANAGE);
            $family = FormalRecordFamily::query()
                ->where('business_id', $business->getKey())
                ->where('record_type', 'continuity_plan')
                ->where('subject_type', 'business')
                ->where('subject_id', $business->getKey())
                ->lockForUpdate()
                ->first();

            if ($family === null) {
                $family = $this->createFamily->execute(
                    $user,
                    $business,
                    $capability,
                    new RecordScope(
                        'continuity_plan',
                        'business',
                        (string) $business->getKey(),
                    ),
                );

                if ($family === null) {
                    return null;
                }

                $version = $this->createDraftVersion->execute(
                    $user,
                    $business,
                    $capability,
                    (string) $family->getKey(),
                    $contentHash,
                    'Initial Business Continuity Plan.',
                    $effectiveFrom,
                    null,
                    $reviewDueAt,
                );
            } else {
                $head = RecordFamilyEffectiveHead::query()
                    ->where('business_id', $business->getKey())
                    ->where('formal_record_family_id', $family->getKey())
                    ->lockForUpdate()
                    ->first();

                if ($head === null) {
                    throw new RuntimeException(
                        'An existing Continuity Plan draft/review must finish before another version can begin.',
                    );
                }

                $version = $this->createAmendedDraft->execute(
                    $user,
                    $business,
                    $capability,
                    (string) $head->formal_record_version_id,
                    $contentHash,
                    'Business Continuity Plan amendment.',
                    $effectiveFrom,
                    null,
                    $reviewDueAt,
                );
            }

            if ($version === null) {
                return null;
            }

            $this->insertSnapshot(
                $business,
                $version,
                $normalized,
                (string) $creator->getKey(),
            );

            $this->occurrence->record(
                $user,
                $business,
                'continuity.plan.draft_created',
                'continuity_plan',
                (string) $version->formal_record_family_id,
                [
                    'formal_record_version_id' => (string) $version->getKey(),
                    'version_number' => (int) $version->version_number,
                ],
                (string) $version->getKey(),
            );

            return [
                'formal_record_version_id' => (string) $version->getKey(),
                'version_number' => (int) $version->version_number,
            ];
        });
    }

    public function submitForGovernance(
        User $user,
        Business $business,
        string $formalRecordVersionId,
        int $expectedRevision,
    ): ?array {
        if ($this->actorContext->membership(
            $user,
            $business,
            CapabilityCatalog::CONTINUITY_MANAGE,
        ) === null) {
            return null;
        }

        $version = $this->version($business, $formalRecordVersionId);

        if ($version === null) {
            return null;
        }

        return DB::transaction(function () use (
            $user,
            $business,
            $version,
            $expectedRevision,
        ): ?array {
            $capability = new Capability(CapabilityCatalog::RECORDS_MANAGE);
            $frozen = $this->submitForReview->execute(
                $user,
                $business,
                $capability,
                (string) $version->getKey(),
                $expectedRevision,
            );

            if ($frozen === null) {
                return null;
            }

            $proposal = $this->createProposal->execute(
                $user,
                $business,
                $capability,
                (string) $frozen->content_hash,
            );

            if ($proposal === null) {
                return null;
            }

            $proposalVersion = $this->freezeProposal->execute(
                $user,
                $business,
                $capability,
                (string) $proposal->getKey(),
                1,
                [(string) $frozen->getKey()],
            );

            if ($proposalVersion === null) {
                return null;
            }

            $this->occurrence->record(
                $user,
                $business,
                'continuity.plan.submitted',
                'continuity_plan',
                (string) $frozen->formal_record_family_id,
                [
                    'formal_record_version_id' => (string) $frozen->getKey(),
                    'proposal_version_id' => (string) $proposalVersion->getKey(),
                ],
                (string) $frozen->getKey(),
            );

            return [
                'proposal_id' => (string) $proposal->getKey(),
                'proposal_version_id' => (string) $proposalVersion->getKey(),
            ];
        });
    }

    public function advanceContentReview(
        User $user,
        Business $business,
        string $formalRecordVersionId,
        FormalRecordState $target,
    ): bool {
        if (! in_array($target, [
            FormalRecordState::UnderReview,
            FormalRecordState::Approved,
            FormalRecordState::ChangesRequested,
        ], true)) {
            throw new InvalidArgumentException(
                'Continuity Plan content review target is invalid.',
            );
        }

        if ($this->actorContext->membership(
            $user,
            $business,
            CapabilityCatalog::CONTINUITY_MANAGE,
        ) === null) {
            return false;
        }

        return $this->transitionRecord->execute(
            $user,
            $business,
            new Capability(CapabilityCatalog::RECORDS_MANAGE),
            $formalRecordVersionId,
            $target,
        ) !== null;
    }

    public function syncApprovedDecision(
        User $user,
        Business $business,
        string $formalRecordVersionId,
    ): bool {
        if ($this->actorContext->membership(
            $user,
            $business,
            CapabilityCatalog::CONTINUITY_MANAGE,
        ) === null) {
            return false;
        }

        $version = $this->version($business, $formalRecordVersionId);

        if ($version === null) {
            return false;
        }

        $header = DB::table('continuity_plan_versions')
            ->where('business_id', $business->getKey())
            ->where('formal_record_version_id', $formalRecordVersionId)
            ->first();

        if ($header === null) {
            return false;
        }

        $proposalIds = ProposalVersionRecord::query()
            ->where('business_id', $business->getKey())
            ->where('formal_record_version_id', $formalRecordVersionId)
            ->pluck('proposal_version_id');

        $decisions = Decision::query()
            ->where('business_id', $business->getKey())
            ->whereIn('proposal_version_id', $proposalIds)
            ->where('decision_type', $header->governance_decision_type)
            ->where('status', DecisionStatus::Decided->value)
            ->where('outcome', DecisionOutcome::Approved->value)
            ->get();

        if ($decisions->count() !== 1) {
            throw new MissingGovernanceDecision;
        }

        $decisionId = (string) $decisions->first()->getKey();

        return $this->prepareEffect->execute(
            $user,
            $business,
            $decisionId,
            $formalRecordVersionId,
        ) && $this->makeEffective->execute(
            $user,
            $business,
            $decisionId,
            $formalRecordVersionId,
        );
    }

    /** @return array<string,mixed> */
    private function normalize(Business $business, array $payload): array
    {
        $this->assertNoSecretPayloadKeys($payload);

        $operationsVersionId = $this->effectiveOperationsVersion($business);

        if ($operationsVersionId === null) {
            throw new InvalidArgumentException(
                'Continuity Plan requires a Current Effective Operations Register.',
            );
        }

        $ownerId = trim((string) ($payload['continuity_owner_membership_id'] ?? ''));
        $decisionType = trim((string) ($payload['governance_decision_type'] ?? 'continuity_plan_approval'));
        $reviewFrequency = trim((string) ($payload['review_frequency'] ?? ''));
        $testFrequency = trim((string) ($payload['test_frequency'] ?? ''));

        if (
            ! $this->activeMember($business, $ownerId)
            || $decisionType === ''
            || $reviewFrequency === ''
            || $testFrequency === ''
        ) {
            throw new InvalidArgumentException('Continuity Plan header is invalid.');
        }

        $criticalFunctions = [];

        foreach (array_values((array) ($payload['critical_functions'] ?? [])) as $raw) {
            if (! is_array($raw)) {
                throw new InvalidArgumentException('Critical Function row must be structured.');
            }

            $roleId = trim((string) ($raw['operations_role_id'] ?? ''));
            $primary = trim((string) ($raw['primary_owner_membership_id'] ?? ''));
            $backup1 = trim((string) ($raw['first_backup_membership_id'] ?? ''));
            $backup2 = $this->nullableText($raw['second_backup_membership_id'] ?? null);
            $function = trim((string) ($raw['function_name'] ?? ''));
            $process = trim((string) ($raw['critical_process'] ?? ''));
            $downtime = (int) ($raw['maximum_downtime_minutes'] ?? 0);
            $priority = (int) ($raw['recovery_priority'] ?? 0);
            $resources = trim((string) ($raw['minimum_resources'] ?? ''));

            if (
                $function === ''
                || $process === ''
                || $downtime < 1
                || $priority < 1
                || $priority > 5
                || $resources === ''
                || ! $this->roleExists($business, $operationsVersionId, $roleId)
                || ! $this->activeMember($business, $primary)
                || ! $this->activeMember($business, $backup1)
                || ($backup2 !== null && ! $this->activeMember($business, $backup2))
                || $primary === $backup1
                || ($backup2 !== null && in_array($backup2, [$primary, $backup1], true))
            ) {
                throw new InvalidArgumentException('Critical Function backup design is invalid.');
            }

            if (! $this->assignedToRole($business, $operationsVersionId, $roleId, $primary)) {
                throw new InvalidArgumentException(
                    'Critical Function primary owner must hold the exact Operations role.',
                );
            }

            $criticalFunctions[] = [
                'operations_role_id' => $roleId,
                'function_name' => $function,
                'critical_process' => $process,
                'maximum_downtime_minutes' => $downtime,
                'primary_owner_membership_id' => $primary,
                'first_backup_membership_id' => $backup1,
                'second_backup_membership_id' => $backup2,
                'recovery_priority' => $priority,
                'minimum_resources' => $resources,
                'review_date' => $this->nullableText($raw['review_date'] ?? null),
                'status' => in_array(($raw['status'] ?? 'ready'), ['ready', 'partial', 'gap', 'inactive'], true)
                    ? $raw['status'] : 'ready',
            ];
        }

        if ($criticalFunctions === []) {
            throw new InvalidArgumentException(
                'Continuity Plan requires at least one Critical Function.',
            );
        }

        $emergencyAccess = [];

        foreach (array_values((array) ($payload['emergency_access'] ?? [])) as $raw) {
            if (! is_array($raw)) {
                throw new InvalidArgumentException('Emergency Access row must be structured.');
            }

            $this->assertNoSecretPayloadKeys($raw);

            $system = trim((string) ($raw['system_asset'] ?? ''));
            $primary = trim((string) ($raw['primary_access_membership_id'] ?? ''));
            $backup = trim((string) ($raw['backup_access_membership_id'] ?? ''));
            $level = trim((string) ($raw['access_level'] ?? ''));
            $procedure = trim((string) ($raw['emergency_access_procedure'] ?? ''));
            $removal = trim((string) ($raw['removal_trigger'] ?? ''));

            if (
                $system === ''
                || $level === ''
                || $procedure === ''
                || $removal === ''
                || ! $this->activeMember($business, $primary)
                || ! $this->activeMember($business, $backup)
                || $primary === $backup
            ) {
                throw new InvalidArgumentException('Emergency Access design is invalid.');
            }

            $emergencyAccess[] = [
                'system_asset' => $system,
                'primary_access_membership_id' => $primary,
                'backup_access_membership_id' => $backup,
                'access_level' => $level,
                'emergency_access_procedure' => $procedure,
                'secure_storage_reference' => $this->nullableText($raw['secure_storage_reference'] ?? null),
                'last_tested_date' => $this->nullableText($raw['last_tested_date'] ?? null),
                'review_date' => $this->nullableText($raw['review_date'] ?? null),
                'removal_trigger' => $removal,
                'status' => $this->nullableText($raw['status'] ?? null) ?? 'active',
                'confidentiality' => 'restricted',
            ];
        }

        $interim = [];

        foreach (array_values((array) ($payload['interim_authority_plans'] ?? [])) as $raw) {
            if (! is_array($raw)) {
                throw new InvalidArgumentException('Interim Authority Plan row must be structured.');
            }

            $roleId = trim((string) ($raw['operations_role_id'] ?? ''));
            $memberId = trim((string) ($raw['interim_membership_id'] ?? ''));
            $trigger = trim((string) ($raw['trigger'] ?? ''));
            $decision = trim((string) ($raw['governance_decision_type'] ?? ''));
            $decisionLimit = trim((string) ($raw['decision_limit'] ?? ''));
            $hours = (int) ($raw['maximum_interim_hours'] ?? 0);
            $reporting = trim((string) ($raw['reporting_requirement'] ?? ''));

            if (
                ! $this->roleExists($business, $operationsVersionId, $roleId)
                || ! $this->activeMember($business, $memberId)
                || $trigger === ''
                || $decision === ''
                || $decisionLimit === ''
                || $hours < 1
                || $reporting === ''
            ) {
                throw new InvalidArgumentException('Interim Authority Plan is invalid.');
            }

            $interim[] = [
                'operations_role_id' => $roleId,
                'interim_membership_id' => $memberId,
                'trigger' => $trigger,
                'governance_decision_type' => $decision,
                'spending_limit_minor_units' => $this->moneyOrNull($raw['spending_limit_minor_units'] ?? null),
                'currency' => $this->currencyOrNull($raw['currency'] ?? null),
                'decision_limit' => $decisionLimit,
                'maximum_interim_hours' => $hours,
                'reporting_requirement' => $reporting,
                'status' => ($raw['status'] ?? 'active') === 'inactive' ? 'inactive' : 'active',
            ];
        }

        $successors = [];

        foreach (array_values((array) ($payload['successors'] ?? [])) as $raw) {
            if (! is_array($raw)) {
                throw new InvalidArgumentException('Successor row must be structured.');
            }

            $roleId = trim((string) ($raw['operations_role_id'] ?? ''));
            $current = trim((string) ($raw['current_owner_membership_id'] ?? ''));
            $candidate = trim((string) ($raw['candidate_membership_id'] ?? ''));
            $readiness = trim((string) ($raw['readiness_level'] ?? ''));

            if (
                ! $this->roleExists($business, $operationsVersionId, $roleId)
                || ! $this->activeMember($business, $current)
                || ! $this->activeMember($business, $candidate)
                || $current === $candidate
                || ! in_array($readiness, ['not_ready', 'developing', 'ready', 'ready_now'], true)
            ) {
                throw new InvalidArgumentException('Successor row is invalid.');
            }

            if (! $this->assignedToRole($business, $operationsVersionId, $roleId, $current)) {
                throw new InvalidArgumentException(
                    'Successor current owner must hold the exact Operations role.',
                );
            }

            $successors[] = [
                'operations_role_id' => $roleId,
                'current_owner_membership_id' => $current,
                'candidate_membership_id' => $candidate,
                'readiness_level' => $readiness,
                'skills_gap' => $this->nullableText($raw['skills_gap'] ?? null),
                'development_required' => $this->nullableText($raw['development_required'] ?? null),
                'target_ready_date' => $this->nullableText($raw['target_ready_date'] ?? null),
                'status' => $this->nullableText($raw['status'] ?? null) ?? 'candidate',
            ];
        }

        $communications = [];

        foreach (array_values((array) ($payload['communication_steps'] ?? [])) as $index => $raw) {
            if (! is_array($raw)) {
                throw new InvalidArgumentException('Communication Plan row must be structured.');
            }

            $owner = trim((string) ($raw['owner_membership_id'] ?? ''));
            $event = trim((string) ($raw['event_type'] ?? ''));
            $stakeholder = trim((string) ($raw['stakeholder'] ?? ''));
            $channel = trim((string) ($raw['channel'] ?? ''));
            $timing = trim((string) ($raw['timing'] ?? ''));

            if (
                ! $this->activeMember($business, $owner)
                || $event === ''
                || $stakeholder === ''
                || $channel === ''
                || $timing === ''
            ) {
                throw new InvalidArgumentException('Communication Plan row is invalid.');
            }

            $communications[] = [
                'sequence' => $index + 1,
                'event_type' => $event,
                'stakeholder' => $stakeholder,
                'owner_membership_id' => $owner,
                'channel' => $channel,
                'timing' => $timing,
                'message_reference' => $this->nullableText($raw['message_reference'] ?? null),
                'governance_approval_may_be_required' => (bool) ($raw['governance_approval_may_be_required'] ?? false),
            ];
        }

        $recovery = [];

        foreach (array_values((array) ($payload['recovery_actions'] ?? [])) as $index => $raw) {
            if (! is_array($raw)) {
                throw new InvalidArgumentException('Recovery Action row must be structured.');
            }

            $band = trim((string) ($raw['timeline_band'] ?? ''));
            $action = trim((string) ($raw['action'] ?? ''));
            $roleId = trim((string) ($raw['operations_role_id'] ?? ''));

            if (
                ! in_array($band, ['0_24_hours', '1_7_days', '7_30_days'], true)
                || $action === ''
                || ! $this->roleExists($business, $operationsVersionId, $roleId)
            ) {
                throw new InvalidArgumentException('Recovery Action row is invalid.');
            }

            $recovery[] = [
                'sequence' => $index + 1,
                'timeline_band' => $band,
                'action' => $action,
                'operations_role_id' => $roleId,
                'required_resource' => $this->nullableText($raw['required_resource'] ?? null),
            ];
        }

        return [
            'operations_formal_record_version_id' => $operationsVersionId,
            'continuity_owner_membership_id' => $ownerId,
            'governance_decision_type' => $decisionType,
            'review_frequency' => $reviewFrequency,
            'test_frequency' => $testFrequency,
            'notes' => $this->nullableText($payload['notes'] ?? null),
            'critical_functions' => $criticalFunctions,
            'emergency_access' => $emergencyAccess,
            'interim_authority_plans' => $interim,
            'successors' => $successors,
            'communication_steps' => $communications,
            'recovery_actions' => $recovery,
        ];
    }

    private function insertSnapshot(
        Business $business,
        FormalRecordVersion $version,
        array $normalized,
        string $creatorMembershipId,
    ): void {
        $businessId = (string) $business->getKey();
        $versionId = (string) $version->getKey();

        DB::table('continuity_plan_versions')->insert([
            'id' => (string) Str::uuid7(),
            'business_id' => $businessId,
            'formal_record_version_id' => $versionId,
            'operations_formal_record_version_id' => $normalized['operations_formal_record_version_id'],
            'continuity_owner_membership_id' => $normalized['continuity_owner_membership_id'],
            'governance_decision_type' => $normalized['governance_decision_type'],
            'review_frequency' => $normalized['review_frequency'],
            'test_frequency' => $normalized['test_frequency'],
            'notes' => $normalized['notes'],
            'created_at' => now(),
        ]);

        foreach ($normalized['critical_functions'] as $row) {
            DB::table('continuity_critical_functions')->insert([
                'id' => (string) Str::uuid7(),
                'business_id' => $businessId,
                'formal_record_version_id' => $versionId,
                ...$row,
                'created_at' => now(),
            ]);
        }

        foreach ($normalized['emergency_access'] as $row) {
            $id = (string) Str::uuid7();
            DB::table('continuity_emergency_access_records')->insert([
                'id' => $id,
                'business_id' => $businessId,
                'formal_record_version_id' => $versionId,
                ...$row,
                'created_at' => now(),
            ]);

            $this->visibility->grantRestrictedAccess(
                $business,
                ContinuityRecordVisibility::EMERGENCY_ACCESS_RESOURCE,
                $id,
                [
                    $creatorMembershipId,
                    $row['primary_access_membership_id'],
                    $row['backup_access_membership_id'],
                ],
            );
        }

        foreach ($normalized['interim_authority_plans'] as $row) {
            DB::table('continuity_interim_authority_plans')->insert([
                'id' => (string) Str::uuid7(),
                'business_id' => $businessId,
                'formal_record_version_id' => $versionId,
                ...$row,
                'created_at' => now(),
            ]);
        }

        foreach ($normalized['successors'] as $row) {
            DB::table('continuity_successor_candidates')->insert([
                'id' => (string) Str::uuid7(),
                'business_id' => $businessId,
                'formal_record_version_id' => $versionId,
                ...$row,
                'created_at' => now(),
            ]);
        }

        foreach ($normalized['communication_steps'] as $row) {
            DB::table('continuity_communication_steps')->insert([
                'id' => (string) Str::uuid7(),
                'business_id' => $businessId,
                'formal_record_version_id' => $versionId,
                ...$row,
                'created_at' => now(),
            ]);
        }

        foreach ($normalized['recovery_actions'] as $row) {
            DB::table('continuity_recovery_actions')->insert([
                'id' => (string) Str::uuid7(),
                'business_id' => $businessId,
                'formal_record_version_id' => $versionId,
                ...$row,
                'created_at' => now(),
            ]);
        }
    }

    private function version(
        Business $business,
        string $versionId,
    ): ?FormalRecordVersion {
        return FormalRecordVersion::query()
            ->join('formal_record_families as f', function ($join): void {
                $join->on('f.id', '=', 'formal_record_versions.formal_record_family_id')
                    ->on('f.business_id', '=', 'formal_record_versions.business_id');
            })
            ->where('formal_record_versions.business_id', $business->getKey())
            ->where('formal_record_versions.id', $versionId)
            ->where('f.record_type', 'continuity_plan')
            ->select('formal_record_versions.*')
            ->first();
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

    private function roleExists(
        Business $business,
        string $operationsVersionId,
        string $roleId,
    ): bool {
        return $roleId !== '' && DB::table('operations_roles')
            ->where('business_id', $business->getKey())
            ->where('formal_record_version_id', $operationsVersionId)
            ->where('id', $roleId)
            ->where('status', 'active')
            ->exists();
    }

    private function assignedToRole(
        Business $business,
        string $operationsVersionId,
        string $roleId,
        string $membershipId,
    ): bool {
        return DB::table('operations_role_assignments')
            ->where('business_id', $business->getKey())
            ->where('formal_record_version_id', $operationsVersionId)
            ->where('operations_role_id', $roleId)
            ->where('membership_id', $membershipId)
            ->exists();
    }

    private function activeMember(Business $business, string $id): bool
    {
        return $id !== '' && DB::table('memberships')
            ->where('business_id', $business->getKey())
            ->where('id', $id)
            ->where('access_status', 'active')
            ->exists();
    }

    /** @param array<string,mixed> $payload */
    private function assertNoSecretPayloadKeys(array $payload): void
    {
        foreach ($payload as $key => $value) {
            if (preg_match(
                '/password|passcode|\bpin\b|secret|otp|token|credential/i',
                (string) $key,
            ) === 1) {
                throw new InvalidArgumentException(
                    'Continuity Plan must never store passwords, PINs, OTPs, tokens or secret credentials.',
                );
            }

            if (is_array($value)) {
                $this->assertNoSecretPayloadKeys($value);
            }
        }
    }

    private function moneyOrNull(mixed $value): ?int
    {
        if ($value === null || $value === '') {
            return null;
        }

        if (! is_numeric($value) || (int) $value < 0) {
            throw new InvalidArgumentException('Continuity money value is invalid.');
        }

        return (int) $value;
    }

    private function currencyOrNull(mixed $value): ?string
    {
        $currency = strtoupper(trim((string) ($value ?? '')));

        if ($currency === '') {
            return null;
        }

        if (preg_match('/^[A-Z]{3}$/', $currency) !== 1) {
            throw new InvalidArgumentException('Continuity currency is invalid.');
        }

        return $currency;
    }

    private function nullableText(mixed $value): ?string
    {
        $text = trim((string) ($value ?? ''));

        return $text === '' ? null : $text;
    }

    /** @param array<string,mixed> $payload */
    private function contentHash(array $payload): string
    {
        return hash(
            'sha256',
            json_encode(
                $payload,
                JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE,
            ),
        );
    }
}
