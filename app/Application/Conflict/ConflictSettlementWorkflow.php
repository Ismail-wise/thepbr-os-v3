<?php

declare(strict_types=1);

namespace App\Application\Conflict;

use App\Application\Documents\AuthorizeDocumentAccess;
use App\Application\Governance\CreateSignatureRequest;
use App\Application\Governance\GovernanceActorContext;
use App\Application\Governance\MakeGovernedRecordEffective;
use App\Application\Governance\PrepareGovernedRecordForEffect;
use App\Application\Records\CreateAmendedDraftVersion;
use App\Application\Records\CreateDraftRecordVersion;
use App\Application\Records\CreateFormalRecordFamily;
use App\Application\Records\CreateProposal;
use App\Application\Records\FreezeProposalVersion;
use App\Application\Records\SubmitRecordVersionForReview;
use App\Application\Records\TransitionFormalRecordVersion;
use App\Domain\Access\CapabilityCatalog;
use App\Domain\Access\ValueObjects\Capability;
use App\Domain\Conflict\Enums\ConflictCaseStage;
use App\Domain\Documents\Enums\DocumentAccessRight;
use App\Domain\Governance\Enums\DecisionOutcome;
use App\Domain\Governance\Enums\DecisionStatus;
use App\Domain\Governance\Enums\SignatureRequestStatus;
use App\Domain\Records\Enums\FormalRecordState;
use App\Domain\Records\ValueObjects\RecordScope;
use App\Infrastructure\Persistence\Eloquent\Businesses\Business;
use App\Infrastructure\Persistence\Eloquent\Conflict\ConflictCase;
use App\Infrastructure\Persistence\Eloquent\Conflict\ConflictSettlementVersion;
use App\Infrastructure\Persistence\Eloquent\Documents\Document;
use App\Infrastructure\Persistence\Eloquent\Documents\DocumentVersion;
use App\Infrastructure\Persistence\Eloquent\Governance\AuthoritySnapshot;
use App\Infrastructure\Persistence\Eloquent\Governance\Decision;
use App\Infrastructure\Persistence\Eloquent\Governance\SignatureRequest;
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

final class ConflictSettlementWorkflow
{
    public function __construct(
        private readonly GovernanceActorContext $actorContext,
        private readonly ConflictRecordVisibility $visibility,
        private readonly CreateFormalRecordFamily $createFamily,
        private readonly CreateDraftRecordVersion $createDraftVersion,
        private readonly CreateAmendedDraftVersion $createAmendedDraft,
        private readonly SubmitRecordVersionForReview $submitForReview,
        private readonly TransitionFormalRecordVersion $transitionRecord,
        private readonly CreateProposal $createProposal,
        private readonly FreezeProposalVersion $freezeProposal,
        private readonly PrepareGovernedRecordForEffect $prepareEffect,
        private readonly MakeGovernedRecordEffective $makeEffective,
        private readonly AuthorizeDocumentAccess $documentAccess,
        private readonly CreateSignatureRequest $createSignatureRequest,
        private readonly ConflictCaseWorkflow $cases,
        private readonly RecordConflictOccurrence $occurrence,
    ) {}

    /**
     * @param  array<string,mixed>  $payload
     * @return array{formal_record_version_id:string,version_number:int}|null
     */
    public function createDraft(
        User $user,
        Business $business,
        string $caseId,
        array $payload,
        DateTimeInterface $effectiveFrom,
        ?DateTimeInterface $reviewDueAt = null,
    ): ?array {
        if (
            ! $this->visibility->canManage($user, $business, $caseId)
            || $this->actorContext->membership(
                $user,
                $business,
                CapabilityCatalog::RECORDS_MANAGE,
            ) === null
        ) {
            return null;
        }

        $case = ConflictCase::query()
            ->where('business_id', $business->getKey())
            ->whereKey($caseId)
            ->first();

        if (
            $case === null
            || $case->stage !== ConflictCaseStage::Settlement
        ) {
            return null;
        }

        $normalized = $this->normalize($business, $case, $payload);
        $contentHash = $this->contentHash($normalized);

        return DB::transaction(function () use (
            $user,
            $business,
            $caseId,
            $normalized,
            $contentHash,
            $effectiveFrom,
            $reviewDueAt,
        ): ?array {
            $capability = new Capability(CapabilityCatalog::RECORDS_MANAGE);

            $family = FormalRecordFamily::query()
                ->where('business_id', $business->getKey())
                ->where('record_type', 'conflict_settlement')
                ->where('subject_type', 'conflict_case')
                ->where('subject_id', $caseId)
                ->lockForUpdate()
                ->first();

            if ($family === null) {
                $family = $this->createFamily->execute(
                    $user,
                    $business,
                    $capability,
                    new RecordScope(
                        'conflict_settlement',
                        'conflict_case',
                        $caseId,
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
                    'Initial Conflict Settlement Agreement.',
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
                        'An existing Conflict Settlement draft/review must finish before another version can begin.',
                    );
                }

                $version = $this->createAmendedDraft->execute(
                    $user,
                    $business,
                    $capability,
                    (string) $head->formal_record_version_id,
                    $contentHash,
                    'Conflict Settlement amendment.',
                    $effectiveFrom,
                    null,
                    $reviewDueAt,
                );
            }

            if ($version === null) {
                return null;
            }

            ConflictSettlementVersion::query()->create([
                'id' => (string) Str::uuid7(),
                'business_id' => $business->getKey(),
                'formal_record_version_id' => $version->getKey(),
                'conflict_case_id' => $caseId,
                ...$normalized,
                'created_at' => now(),
            ]);

            $this->occurrence->record(
                $user,
                $business,
                'conflict.settlement.draft_created',
                $caseId,
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
        string $caseId,
        string $formalRecordVersionId,
        int $expectedRevision,
    ): ?array {
        if (! $this->visibility->canManage($user, $business, $caseId)) {
            return null;
        }

        $version = $this->version(
            $business,
            $caseId,
            $formalRecordVersionId,
        );

        if ($version === null) {
            return null;
        }

        return DB::transaction(function () use (
            $user,
            $business,
            $caseId,
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
                'conflict.settlement.submitted',
                $caseId,
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
        string $caseId,
        string $formalRecordVersionId,
        FormalRecordState $target,
    ): bool {
        if (! in_array($target, [
            FormalRecordState::UnderReview,
            FormalRecordState::Approved,
            FormalRecordState::ChangesRequested,
        ], true)) {
            throw new InvalidArgumentException(
                'Conflict Settlement review target is invalid.',
            );
        }

        if (! $this->visibility->canManage($user, $business, $caseId)) {
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

    public function bindDocument(
        User $user,
        Business $business,
        string $caseId,
        string $formalRecordVersionId,
        string $documentVersionId,
    ): bool {
        if (! $this->visibility->canManage($user, $business, $caseId)) {
            return false;
        }

        $version = $this->version(
            $business,
            $caseId,
            $formalRecordVersionId,
        );

        if ($version === null || $version->frozen_at === null) {
            return false;
        }

        $documentVersion = DocumentVersion::query()
            ->where('business_id', $business->getKey())
            ->whereKey($documentVersionId)
            ->first();

        if ($documentVersion === null) {
            return false;
        }

        $document = Document::query()
            ->where('business_id', $business->getKey())
            ->whereKey($documentVersion->document_id)
            ->first();

        if (
            $document === null
            || $this->documentAccess->allows(
                $user,
                $business,
                $document,
                DocumentAccessRight::Manage,
                CapabilityCatalog::RECORDS_MANAGE,
            ) === null
        ) {
            return false;
        }

        $membership = $this->actorContext->membership(
            $user,
            $business,
            CapabilityCatalog::RECORDS_MANAGE,
        );

        if ($membership === null) {
            return false;
        }

        return DB::transaction(function () use (
            $user,
            $business,
            $caseId,
            $version,
            $documentVersionId,
            $membership,
        ): bool {
            if (DB::table('conflict_settlement_document_bindings')
                ->where(
                    'formal_record_version_id',
                    $version->getKey(),
                )
                ->exists()) {
                return false;
            }

            DB::table('conflict_settlement_document_bindings')->insert([
                'id' => (string) Str::uuid7(),
                'business_id' => $business->getKey(),
                'formal_record_version_id' => $version->getKey(),
                'document_version_id' => $documentVersionId,
                'captured_settlement_content_hash' => (string) $version->content_hash,
                'bound_by_membership_id' => $membership->getKey(),
                'bound_at' => now(),
            ]);

            $this->occurrence->record(
                $user,
                $business,
                'conflict.settlement.document_bound',
                $caseId,
                ['formal_record_version_id' => (string) $version->getKey()],
                (string) $version->getKey(),
            );

            return true;
        });
    }

    public function requestRequiredSignature(
        User $user,
        Business $business,
        string $caseId,
        string $formalRecordVersionId,
    ): ?SignatureRequest {
        if (! $this->visibility->canManage($user, $business, $caseId)) {
            return null;
        }

        $decision = $this->approvedDecision(
            $business,
            $caseId,
            $formalRecordVersionId,
        );

        if ($decision === null) {
            return null;
        }

        $snapshot = AuthoritySnapshot::query()
            ->where('business_id', $business->getKey())
            ->whereKey($decision->authority_snapshot_id)
            ->first();

        if ($snapshot === null || ! $snapshot->signature_required) {
            return null;
        }

        $binding = DB::table(
            'conflict_settlement_document_bindings',
        )
            ->where('business_id', $business->getKey())
            ->where(
                'formal_record_version_id',
                $formalRecordVersionId,
            )
            ->first();

        if ($binding === null) {
            return null;
        }

        return $this->createSignatureRequest->execute(
            $user,
            $business,
            (string) $decision->getKey(),
            (string) $binding->document_version_id,
        );
    }

    public function makeEffectiveAndResolve(
        User $user,
        Business $business,
        string $caseId,
        int $expectedCaseRevision,
        string $formalRecordVersionId,
    ): bool {
        if (! $this->visibility->canManage($user, $business, $caseId)) {
            return false;
        }

        return DB::transaction(function () use (
            $user,
            $business,
            $caseId,
            $expectedCaseRevision,
            $formalRecordVersionId,
        ): bool {
            $case = ConflictCase::query()
                ->where('business_id', $business->getKey())
                ->whereKey($caseId)
                ->lockForUpdate()
                ->first();

            if (
                $case === null
                || $case->stage !== ConflictCaseStage::Settlement
                || (int) $case->revision !== $expectedCaseRevision
            ) {
                return false;
            }

            $decision = $this->approvedDecision(
                $business,
                $caseId,
                $formalRecordVersionId,
            );

            if ($decision === null) {
                return false;
            }

            $snapshot = AuthoritySnapshot::query()
                ->where('business_id', $business->getKey())
                ->whereKey($decision->authority_snapshot_id)
                ->first();

            if ($snapshot === null) {
                return false;
            }

            if (
                $snapshot->signature_required
                && ! $this->hasExactCompletedSignature(
                    $business,
                    $decision,
                    $formalRecordVersionId,
                )
            ) {
                return false;
            }

            if (! $this->prepareEffect->execute(
                $user,
                $business,
                (string) $decision->getKey(),
                $formalRecordVersionId,
            )) {
                return false;
            }

            if (! $this->makeEffective->execute(
                $user,
                $business,
                (string) $decision->getKey(),
                $formalRecordVersionId,
            )) {
                return false;
            }

            $alreadyLinked = DB::table(
                'conflict_case_settlement_links',
            )
                ->where('business_id', $business->getKey())
                ->where('conflict_case_id', $caseId)
                ->where(
                    'settlement_formal_record_version_id',
                    $formalRecordVersionId,
                )
                ->exists();

            if (! $alreadyLinked) {
                DB::table('conflict_case_settlement_links')->insert([
                    'id' => (string) Str::uuid7(),
                    'business_id' => $business->getKey(),
                    'conflict_case_id' => $caseId,
                    'settlement_formal_record_version_id' => $formalRecordVersionId,
                    'decision_id' => $decision->getKey(),
                    'linked_at' => now(),
                ]);
            }

            if ($this->cases->transition(
                $user,
                $business,
                $caseId,
                $expectedCaseRevision,
                ConflictCaseStage::Resolved,
                'settlement_effective',
                'conflict_settlement',
                $formalRecordVersionId,
            ) === null) {
                throw new RuntimeException(
                    'Effective Settlement could not resolve exact Conflict Case revision.',
                );
            }

            $this->occurrence->record(
                $user,
                $business,
                'conflict.settlement.effective',
                $caseId,
                [
                    'formal_record_version_id' => $formalRecordVersionId,
                    'decision_id' => (string) $decision->getKey(),
                ],
                $formalRecordVersionId,
            );

            return true;
        });
    }

    /** @return array<string,mixed> */
    private function normalize(
        Business $business,
        ConflictCase $case,
        array $payload,
    ): array {
        $terms = trim((string) ($payload['settlement_terms'] ?? ''));
        $ownerId = trim(
            (string) ($payload['responsible_owner_membership_id'] ?? ''),
        );

        if ($terms === '' || ! $this->activeMember($business, $ownerId)) {
            throw new InvalidArgumentException(
                'Settlement terms and active responsible owner are required.',
            );
        }

        $policy = DB::table('conflict_policy_versions')
            ->where('business_id', $business->getKey())
            ->where(
                'formal_record_version_id',
                $case->conflict_policy_formal_record_version_id,
            )
            ->first();

        if ($policy === null) {
            throw new InvalidArgumentException(
                'Conflict Settlement requires the exact captured Conflict Procedure.',
            );
        }

        $sourceMediationId = $this->nullableText(
            $payload['source_mediation_id'] ?? null,
        );
        $sourceDecisionId = $this->nullableText(
            $payload['source_decision_id'] ?? null,
        );

        if ($sourceMediationId === null && $sourceDecisionId === null) {
            throw new InvalidArgumentException(
                'Settlement must derive from completed Mediation or an approved Governance Decision.',
            );
        }

        if (
            $sourceMediationId !== null
            && ! DB::table('conflict_mediations')
                ->where('business_id', $business->getKey())
                ->where('conflict_case_id', $case->getKey())
                ->where('id', $sourceMediationId)
                ->where('status', 'completed')
                ->exists()
        ) {
            throw new InvalidArgumentException(
                'Settlement Mediation source is invalid.',
            );
        }

        if ($sourceDecisionId !== null) {
            $decision = Decision::query()
                ->where('business_id', $business->getKey())
                ->whereKey($sourceDecisionId)
                ->where('status', DecisionStatus::Decided->value)
                ->where('outcome', DecisionOutcome::Approved->value)
                ->first();

            $caseDecision = $decision !== null
                && DB::table('conflict_decision_submissions')
                    ->where('business_id', $business->getKey())
                    ->where('conflict_case_id', $case->getKey())
                    ->where('decision_id', $sourceDecisionId)
                    ->exists();

            if (! $caseDecision) {
                throw new InvalidArgumentException(
                    'Settlement Governance Decision source is invalid.',
                );
            }
        }

        $amount = $payload['financial_settlement_minor_units'] ?? null;
        $currency = strtoupper(trim(
            (string) ($payload['currency'] ?? ''),
        ));

        if ($amount === '' || $amount === null) {
            $amount = null;
            $currency = '';
        } elseif (
            ! is_numeric($amount)
            || (int) $amount < 0
            || preg_match('/^[A-Z]{3}$/', $currency) !== 1
        ) {
            throw new InvalidArgumentException(
                'Settlement financial term is invalid.',
            );
        }

        return [
            'source_mediation_id' => $sourceMediationId,
            'source_decision_id' => $sourceDecisionId,
            'settlement_terms' => $terms,
            'required_actions_summary' => $this->nullableText(
                $payload['required_actions_summary'] ?? null,
            ),
            'responsible_owner_membership_id' => $ownerId,
            'due_at' => $payload['due_at'] ?? null,
            'financial_settlement_minor_units' => $amount === null
                ? null
                : (int) $amount,
            'currency' => $amount === null ? null : $currency,
            'confidentiality_terms' => $this->nullableText(
                $payload['confidentiality_terms'] ?? null,
            ),
            'future_conduct_terms' => $this->nullableText(
                $payload['future_conduct_terms'] ?? null,
            ),
            'review_date' => $payload['review_date'] ?? null,
            'settlement_decision_type' => (string) $policy->settlement_decision_type,
        ];
    }

    private function approvedDecision(
        Business $business,
        string $caseId,
        string $formalRecordVersionId,
    ): ?Decision {
        $settlement = ConflictSettlementVersion::query()
            ->where('business_id', $business->getKey())
            ->where('conflict_case_id', $caseId)
            ->where(
                'formal_record_version_id',
                $formalRecordVersionId,
            )
            ->first();

        if ($settlement === null) {
            return null;
        }

        $proposalIds = ProposalVersionRecord::query()
            ->where('business_id', $business->getKey())
            ->where(
                'formal_record_version_id',
                $formalRecordVersionId,
            )
            ->pluck('proposal_version_id');

        $decisions = Decision::query()
            ->where('business_id', $business->getKey())
            ->whereIn('proposal_version_id', $proposalIds)
            ->where(
                'decision_type',
                $settlement->settlement_decision_type,
            )
            ->where('status', DecisionStatus::Decided->value)
            ->where('outcome', DecisionOutcome::Approved->value)
            ->get();

        return $decisions->count() === 1
            ? $decisions->first()
            : null;
    }

    private function hasExactCompletedSignature(
        Business $business,
        Decision $decision,
        string $formalRecordVersionId,
    ): bool {
        $binding = DB::table(
            'conflict_settlement_document_bindings',
        )
            ->where('business_id', $business->getKey())
            ->where(
                'formal_record_version_id',
                $formalRecordVersionId,
            )
            ->first();

        if ($binding === null) {
            return false;
        }

        $version = FormalRecordVersion::query()
            ->where('business_id', $business->getKey())
            ->whereKey($formalRecordVersionId)
            ->first();

        $documentVersion = DocumentVersion::query()
            ->where('business_id', $business->getKey())
            ->whereKey($binding->document_version_id)
            ->first();

        if (
            $version === null
            || $documentVersion === null
            || (string) $binding->captured_settlement_content_hash
                !== (string) $version->content_hash
        ) {
            return false;
        }

        return SignatureRequest::query()
            ->where('business_id', $business->getKey())
            ->where('decision_id', $decision->getKey())
            ->where(
                'proposal_version_id',
                $decision->proposal_version_id,
            )
            ->where(
                'authority_snapshot_id',
                $decision->authority_snapshot_id,
            )
            ->where(
                'document_version_id',
                $documentVersion->getKey(),
            )
            ->where(
                'document_content_sha256',
                $documentVersion->content_sha256,
            )
            ->where(
                'status',
                SignatureRequestStatus::Completed->value,
            )
            ->exists();
    }

    private function version(
        Business $business,
        string $caseId,
        string $versionId,
    ): ?FormalRecordVersion {
        return FormalRecordVersion::query()
            ->join(
                'formal_record_families as f',
                function ($join): void {
                    $join->on(
                        'f.id',
                        '=',
                        'formal_record_versions.formal_record_family_id',
                    )->on(
                        'f.business_id',
                        '=',
                        'formal_record_versions.business_id',
                    );
                },
            )
            ->where(
                'formal_record_versions.business_id',
                $business->getKey(),
            )
            ->where('formal_record_versions.id', $versionId)
            ->where('f.record_type', 'conflict_settlement')
            ->where('f.subject_type', 'conflict_case')
            ->where('f.subject_id', $caseId)
            ->select('formal_record_versions.*')
            ->first();
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
