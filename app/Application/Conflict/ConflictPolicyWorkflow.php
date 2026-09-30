<?php

declare(strict_types=1);

namespace App\Application\Conflict;

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

final class ConflictPolicyWorkflow
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
    ) {}

    /**
     * @param  array<string,mixed>  $payload
     * @return array{formal_record_version_id:string,version_number:int}|null
     */
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
            CapabilityCatalog::CONFLICT_MANAGE,
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
            $normalized,
            $contentHash,
            $effectiveFrom,
            $reviewDueAt,
        ): ?array {
            $capability = new Capability(CapabilityCatalog::RECORDS_MANAGE);

            $family = FormalRecordFamily::query()
                ->where('business_id', $business->getKey())
                ->where('record_type', 'conflict_resolution_policy')
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
                        'conflict_resolution_policy',
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
                    'Initial Conflict Resolution Procedure.',
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
                        'An existing Conflict Resolution Procedure draft/review must finish before another version can begin.',
                    );
                }

                $version = $this->createAmendedDraft->execute(
                    $user,
                    $business,
                    $capability,
                    (string) $head->formal_record_version_id,
                    $contentHash,
                    'Conflict Resolution Procedure amendment.',
                    $effectiveFrom,
                    null,
                    $reviewDueAt,
                );
            }

            if ($version === null) {
                return null;
            }

            $this->insertSnapshot($business, $version, $normalized);

            $this->occurrence->record(
                $user,
                $business,
                'conflict.policy.draft_created',
                'conflict_policy',
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

    /**
     * @return array{proposal_id:string,proposal_version_id:string}|null
     */
    public function submitForGovernance(
        User $user,
        Business $business,
        string $formalRecordVersionId,
        int $expectedRevision,
    ): ?array {
        if ($this->actorContext->membership(
            $user,
            $business,
            CapabilityCatalog::CONFLICT_MANAGE,
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
                'conflict.policy.submitted',
                'conflict_policy',
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
                'Conflict Resolution Procedure review target is invalid.',
            );
        }

        if ($this->actorContext->membership(
            $user,
            $business,
            CapabilityCatalog::CONFLICT_MANAGE,
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
        $version = $this->version($business, $formalRecordVersionId);

        if ($version === null) {
            return false;
        }

        $decisionType = DB::table('conflict_policy_versions')
            ->where('business_id', $business->getKey())
            ->where('formal_record_version_id', $formalRecordVersionId)
            ->value('formal_decision_type');

        if (! is_string($decisionType) || $decisionType === '') {
            return false;
        }

        $proposalIds = ProposalVersionRecord::query()
            ->where('business_id', $business->getKey())
            ->where('formal_record_version_id', $formalRecordVersionId)
            ->pluck('proposal_version_id');

        $decisions = Decision::query()
            ->where('business_id', $business->getKey())
            ->whereIn('proposal_version_id', $proposalIds)
            ->where('decision_type', $decisionType)
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
        $ownerId = trim(
            (string) ($payload['conflict_owner_membership_id'] ?? ''),
        );

        if (! $this->activeMember($business, $ownerId)) {
            throw new InvalidArgumentException(
                'Conflict owner must be an active same-Business Membership.',
            );
        }

        $governanceVersion = $this->effectiveRecordVersion(
            $business,
            'governance_charter',
        );
        $operationsVersion = $this->effectiveRecordVersion(
            $business,
            'operations_register',
        );

        if ($governanceVersion === null || $operationsVersion === null) {
            throw new InvalidArgumentException(
                'Current Effective Governance and Operations are required.',
            );
        }

        $decisionTypes = [];
        foreach ([
            'formal_decision_type',
            'deadlock_decision_type',
            'misconduct_decision_type',
            'urgent_risk_decision_type',
            'settlement_decision_type',
        ] as $key) {
            $value = trim((string) ($payload[$key] ?? ''));

            if (
                $value === ''
                || ! $this->governanceDecisionTypeExists(
                    $business,
                    $governanceVersion,
                    $value,
                )
            ) {
                throw new InvalidArgumentException(
                    "Conflict Procedure decision type {$key} is invalid.",
                );
            }

            $decisionTypes[$key] = $value;
        }

        $reviewFrequency = trim(
            (string) ($payload['review_frequency'] ?? ''),
        );

        if ($reviewFrequency === '') {
            throw new InvalidArgumentException(
                'Conflict Procedure review frequency is required.',
            );
        }

        $escalationRules = [];
        $seenSteps = [];

        foreach (
            array_values((array) ($payload['escalation_rules'] ?? [])) as $index => $raw
        ) {
            if (! is_array($raw)) {
                throw new InvalidArgumentException(
                    'Conflict escalation rule must be structured.',
                );
            }

            $step = trim((string) ($raw['step_key'] ?? ''));
            $entry = trim((string) ($raw['entry_condition'] ?? ''));
            $resolution = trim(
                (string) ($raw['resolution_exit_condition'] ?? ''),
            );
            $roleId = $this->nullableText(
                $raw['operations_role_id'] ?? null,
            );
            $decisionType = $this->nullableText(
                $raw['decision_type'] ?? null,
            );
            $maxDays = ($raw['max_days'] ?? null) === null
                ? null
                : (int) $raw['max_days'];

            if (
                $step === ''
                || isset($seenSteps[$step])
                || $entry === ''
                || $resolution === ''
                || ($maxDays !== null && $maxDays < 1)
            ) {
                throw new InvalidArgumentException(
                    'Conflict escalation rule is invalid.',
                );
            }

            if (
                $roleId !== null
                && ! $this->operationsRoleExists(
                    $business,
                    $operationsVersion,
                    $roleId,
                )
            ) {
                throw new InvalidArgumentException(
                    'Conflict escalation role must belong to captured Operations.',
                );
            }

            if (
                $decisionType !== null
                && ! $this->governanceDecisionTypeExists(
                    $business,
                    $governanceVersion,
                    $decisionType,
                )
            ) {
                throw new InvalidArgumentException(
                    'Conflict escalation Decision Type is invalid.',
                );
            }

            $seenSteps[$step] = true;
            $escalationRules[] = [
                'sequence' => $index + 1,
                'step_key' => $step,
                'entry_condition' => $entry,
                'operations_role_id' => $roleId,
                'max_days' => $maxDays,
                'required_evidence' => $this->nullableText(
                    $raw['required_evidence'] ?? null,
                ),
                'decision_type' => $decisionType,
                'resolution_exit_condition' => $resolution,
                'next_step_key' => $this->nullableText(
                    $raw['next_step_key'] ?? null,
                ),
                'status' => ($raw['status'] ?? 'active') === 'inactive'
                    ? 'inactive'
                    : 'active',
            ];
        }

        if ($escalationRules === []) {
            throw new InvalidArgumentException(
                'Conflict Procedure requires an escalation path.',
            );
        }

        $specialRules = [];
        $expectedDecision = [
            'deadlock' => $decisionTypes['deadlock_decision_type'],
            'misconduct' => $decisionTypes['misconduct_decision_type'],
            'urgent_risk' => $decisionTypes['urgent_risk_decision_type'],
        ];

        foreach (
            array_values((array) ($payload['special_path_rules'] ?? [])) as $raw
        ) {
            if (! is_array($raw)) {
                throw new InvalidArgumentException(
                    'Conflict special-path rule must be structured.',
                );
            }

            $pathType = trim((string) ($raw['path_type'] ?? ''));
            $entry = trim((string) ($raw['entry_condition'] ?? ''));
            $procedure = trim((string) ($raw['procedure_summary'] ?? ''));
            $deadline = (int) ($raw['review_deadline_days'] ?? 0);
            $roleId = $this->nullableText(
                $raw['operations_role_id'] ?? null,
            );

            if (
                ! array_key_exists($pathType, $expectedDecision)
                || isset($specialRules[$pathType])
                || $entry === ''
                || $procedure === ''
                || $deadline < 1
            ) {
                throw new InvalidArgumentException(
                    'Conflict special-path rule is invalid.',
                );
            }

            if (
                $roleId !== null
                && ! $this->operationsRoleExists(
                    $business,
                    $operationsVersion,
                    $roleId,
                )
            ) {
                throw new InvalidArgumentException(
                    'Conflict special-path role must belong to captured Operations.',
                );
            }

            $specialRules[$pathType] = [
                'path_type' => $pathType,
                'entry_condition' => $entry,
                'procedure_summary' => $procedure,
                'operations_role_id' => $roleId,
                'review_deadline_days' => $deadline,
                'decision_type' => $expectedDecision[$pathType],
                'external_handoff_rule' => $this->nullableText(
                    $raw['external_handoff_rule'] ?? null,
                ),
            ];
        }

        if (array_keys($specialRules) !== [
            'deadlock',
            'misconduct',
            'urgent_risk',
        ]) {
            ksort($specialRules);
            $required = ['deadlock', 'misconduct', 'urgent_risk'];
            sort($required);

            if (array_keys($specialRules) !== $required) {
                throw new InvalidArgumentException(
                    'Conflict Procedure requires Deadlock, Misconduct and Urgent Risk paths.',
                );
            }
        }

        return [
            'governance_formal_record_version_id' => $governanceVersion,
            'operations_formal_record_version_id' => $operationsVersion,
            'conflict_owner_membership_id' => $ownerId,
            ...$decisionTypes,
            'review_frequency' => $reviewFrequency,
            'notes' => $this->nullableText($payload['notes'] ?? null),
            'escalation_rules' => $escalationRules,
            'special_path_rules' => array_values($specialRules),
        ];
    }

    private function insertSnapshot(
        Business $business,
        FormalRecordVersion $version,
        array $normalized,
    ): void {
        $businessId = (string) $business->getKey();
        $versionId = (string) $version->getKey();

        DB::table('conflict_policy_versions')->insert([
            'id' => (string) Str::uuid7(),
            'business_id' => $businessId,
            'formal_record_version_id' => $versionId,
            'governance_formal_record_version_id' => $normalized['governance_formal_record_version_id'],
            'operations_formal_record_version_id' => $normalized['operations_formal_record_version_id'],
            'conflict_owner_membership_id' => $normalized['conflict_owner_membership_id'],
            'formal_decision_type' => $normalized['formal_decision_type'],
            'deadlock_decision_type' => $normalized['deadlock_decision_type'],
            'misconduct_decision_type' => $normalized['misconduct_decision_type'],
            'urgent_risk_decision_type' => $normalized['urgent_risk_decision_type'],
            'settlement_decision_type' => $normalized['settlement_decision_type'],
            'review_frequency' => $normalized['review_frequency'],
            'notes' => $normalized['notes'],
            'created_at' => now(),
        ]);

        foreach ($normalized['escalation_rules'] as $row) {
            DB::table('conflict_escalation_rules')->insert([
                'id' => (string) Str::uuid7(),
                'business_id' => $businessId,
                'formal_record_version_id' => $versionId,
                ...$row,
                'created_at' => now(),
            ]);
        }

        foreach ($normalized['special_path_rules'] as $row) {
            DB::table('conflict_special_path_rules')->insert([
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
                $join->on(
                    'f.id',
                    '=',
                    'formal_record_versions.formal_record_family_id',
                )->on(
                    'f.business_id',
                    '=',
                    'formal_record_versions.business_id',
                );
            })
            ->where(
                'formal_record_versions.business_id',
                $business->getKey(),
            )
            ->where('formal_record_versions.id', $versionId)
            ->where('f.record_type', 'conflict_resolution_policy')
            ->select('formal_record_versions.*')
            ->first();
    }

    private function effectiveRecordVersion(
        Business $business,
        string $recordType,
    ): ?string {
        $id = DB::table('record_family_effective_heads as h')
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
            ->where('h.business_id', $business->getKey())
            ->where('f.business_id', $business->getKey())
            ->where('f.record_type', $recordType)
            ->value('v.id');

        return $id === null ? null : (string) $id;
    }

    private function governanceDecisionTypeExists(
        Business $business,
        string $governanceVersionId,
        string $decisionType,
    ): bool {
        return DB::table('governance_charter_rules')
            ->where('business_id', $business->getKey())
            ->where('formal_record_version_id', $governanceVersionId)
            ->where('decision_type', $decisionType)
            ->exists();
    }

    private function operationsRoleExists(
        Business $business,
        string $operationsVersionId,
        string $roleId,
    ): bool {
        return DB::table('operations_roles')
            ->where('business_id', $business->getKey())
            ->where('formal_record_version_id', $operationsVersionId)
            ->where('id', $roleId)
            ->where('status', 'active')
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
                JSON_THROW_ON_ERROR
                    | JSON_UNESCAPED_SLASHES
                    | JSON_UNESCAPED_UNICODE,
            ),
        );
    }
}
