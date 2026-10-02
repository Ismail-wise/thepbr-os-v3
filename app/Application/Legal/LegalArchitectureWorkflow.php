<?php

declare(strict_types=1);

namespace App\Application\Legal;

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

final class LegalArchitectureWorkflow
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
        private readonly LegalRecordVisibility $visibility,
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
            CapabilityCatalog::LEGAL_MANAGE,
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

        $normalized = $this->normalize($payload);
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
                ->where('record_type', 'legal_architecture')
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
                        'legal_architecture',
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
                    'Initial Legal Architecture.',
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
                        'An existing Legal Architecture draft/review must finish before another version can begin.',
                    );
                }

                $version = $this->createAmendedDraft->execute(
                    $user,
                    $business,
                    $capability,
                    (string) $head->formal_record_version_id,
                    $contentHash,
                    'Legal Architecture amendment.',
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

            if ($normalized['confidentiality'] === 'restricted') {
                $this->visibility->grantRestrictedAccess(
                    $business,
                    (string) $version->getKey(),
                    [(string) $creator->getKey()],
                );
            }

            $this->occurrence->record(
                $user,
                $business,
                'legal.architecture.draft_created',
                'legal_architecture',
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
            CapabilityCatalog::LEGAL_MANAGE,
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
                'legal.architecture.submitted',
                'legal_architecture',
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
                'Legal Architecture content review target is invalid.',
            );
        }

        if ($this->actorContext->membership(
            $user,
            $business,
            CapabilityCatalog::LEGAL_MANAGE,
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
        string $decisionType = 'legal_architecture_approval',
    ): bool {
        if ($this->actorContext->membership(
            $user,
            $business,
            CapabilityCatalog::LEGAL_MANAGE,
        ) === null) {
            return false;
        }

        if ($this->version($business, $formalRecordVersionId) === null) {
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

    private function version(
        Business $business,
        string $formalRecordVersionId,
    ): ?FormalRecordVersion {
        return FormalRecordVersion::query()
            ->join(
                'formal_record_families as family',
                function ($join): void {
                    $join->on(
                        'family.id',
                        '=',
                        'formal_record_versions.formal_record_family_id',
                    )->on(
                        'family.business_id',
                        '=',
                        'formal_record_versions.business_id',
                    );
                },
            )
            ->where('formal_record_versions.business_id', $business->getKey())
            ->where('formal_record_versions.id', $formalRecordVersionId)
            ->where('family.record_type', 'legal_architecture')
            ->select('formal_record_versions.*')
            ->first();
    }

    /** @param array<string,mixed> $payload */
    private function normalize(array $payload): array
    {
        $legalForm = trim((string) ($payload['legal_form'] ?? ''));
        $primaryJurisdiction = $this->jurisdiction(
            $payload['primary_jurisdiction_code'] ?? null,
        );

        if ($legalForm === '') {
            throw new InvalidArgumentException('Legal form is required.');
        }

        $jurisdictions = $this->normalizeJurisdictions(
            (array) ($payload['jurisdictions'] ?? []),
            $primaryJurisdiction,
        );
        $registrations = $this->normalizeRegistrations(
            (array) ($payload['registrations'] ?? []),
            $primaryJurisdiction,
        );
        $licenses = $this->normalizeLicenses(
            (array) ($payload['licenses'] ?? []),
            $primaryJurisdiction,
        );
        $requirements = $this->normalizeRequirements(
            (array) ($payload['requirements'] ?? []),
            $primaryJurisdiction,
        );
        $reviews = $this->normalizeReviews(
            (array) ($payload['reviews'] ?? []),
            $requirements,
        );

        $confidentiality = trim((string) (
            $payload['confidentiality'] ?? 'standard'
        ));

        if (! in_array($confidentiality, ['standard', 'restricted'], true)) {
            throw new InvalidArgumentException(
                'Legal Architecture confidentiality is invalid.',
            );
        }

        return [
            'legal_form' => $legalForm,
            'entity_name' => $this->nullableText($payload['entity_name'] ?? null),
            'primary_jurisdiction_code' => $primaryJurisdiction,
            'governing_law_reference' => $this->nullableText(
                $payload['governing_law_reference'] ?? null,
            ),
            'registered_address' => $this->nullableText(
                $payload['registered_address'] ?? null,
            ),
            'confidentiality' => $confidentiality,
            'notes' => $this->nullableText($payload['notes'] ?? null),
            'jurisdictions' => $jurisdictions,
            'registrations' => $registrations,
            'licenses' => $licenses,
            'requirements' => $requirements,
            'reviews' => $reviews,
        ];
    }

    /** @return list<array<string,mixed>> */
    private function normalizeJurisdictions(
        array $rows,
        string $primaryJurisdiction,
    ): array {
        $normalized = [];

        foreach (array_values($rows) as $row) {
            if (! is_array($row)) {
                throw new InvalidArgumentException(
                    'Jurisdiction Applicability row must be structured.',
                );
            }

            $scopeType = trim((string) ($row['scope_type'] ?? ''));
            $applicability = trim((string) ($row['applicability'] ?? ''));

            if (
                ! in_array($scopeType, [
                    'entity', 'registration', 'license_permit', 'tax',
                    'employment', 'contract', 'data', 'other',
                ], true)
                || ! in_array($applicability, [
                    'applicable', 'not_applicable', 'needs_review',
                ], true)
            ) {
                throw new InvalidArgumentException(
                    'Jurisdiction Applicability row is invalid.',
                );
            }

            $normalized[] = [
                'jurisdiction_code' => $this->jurisdiction(
                    $row['jurisdiction_code'] ?? null,
                ),
                'scope_type' => $scopeType,
                'scope_reference' => $this->nullableText(
                    $row['scope_reference'] ?? null,
                ),
                'applicability' => $applicability,
                'rationale' => $this->nullableText($row['rationale'] ?? null),
                'legal_review_required' => (bool) (
                    $row['legal_review_required'] ?? false
                ),
            ];
        }

        if ($normalized === []) {
            $normalized[] = [
                'jurisdiction_code' => $primaryJurisdiction,
                'scope_type' => 'entity',
                'scope_reference' => null,
                'applicability' => 'applicable',
                'rationale' => null,
                'legal_review_required' => false,
            ];
        }

        return $normalized;
    }

    /** @return list<array<string,mixed>> */
    private function normalizeRegistrations(
        array $rows,
        string $primaryJurisdiction,
    ): array {
        $normalized = [];

        foreach (array_values($rows) as $row) {
            if (! is_array($row)) {
                throw new InvalidArgumentException(
                    'Registration row must be structured.',
                );
            }

            $type = trim((string) ($row['registration_type'] ?? ''));
            $authority = trim((string) ($row['authority'] ?? ''));
            $status = trim((string) ($row['status'] ?? 'planned'));

            if (
                $type === ''
                || $authority === ''
                || ! in_array($status, [
                    'planned', 'pending', 'active', 'suspended',
                    'expired', 'cancelled', 'closed',
                ], true)
            ) {
                throw new InvalidArgumentException('Registration row is invalid.');
            }

            $normalized[] = [
                'registration_type' => $type,
                'authority' => $authority,
                'reference_number' => $this->nullableText(
                    $row['reference_number'] ?? null,
                ),
                'jurisdiction_code' => $this->jurisdiction(
                    $row['jurisdiction_code'] ?? $primaryJurisdiction,
                ),
                'registration_date' => $this->nullableText(
                    $row['registration_date'] ?? null,
                ),
                'status' => $status,
                'evidence_reference' => $this->nullableText(
                    $row['evidence_reference'] ?? null,
                ),
            ];
        }

        return $normalized;
    }

    /** @return list<array<string,mixed>> */
    private function normalizeLicenses(
        array $rows,
        string $primaryJurisdiction,
    ): array {
        $normalized = [];

        foreach (array_values($rows) as $row) {
            if (! is_array($row)) {
                throw new InvalidArgumentException(
                    'License / Permit row must be structured.',
                );
            }

            $name = trim((string) ($row['name'] ?? ''));
            $authority = trim((string) ($row['authority'] ?? ''));
            $status = trim((string) ($row['status'] ?? 'planned'));

            if (
                $name === ''
                || $authority === ''
                || ! in_array($status, [
                    'planned', 'pending', 'active', 'suspended',
                    'expired', 'cancelled', 'closed',
                ], true)
            ) {
                throw new InvalidArgumentException(
                    'License / Permit row is invalid.',
                );
            }

            $normalized[] = [
                'name' => $name,
                'authority' => $authority,
                'reference_number' => $this->nullableText(
                    $row['reference_number'] ?? null,
                ),
                'jurisdiction_code' => $this->jurisdiction(
                    $row['jurisdiction_code'] ?? $primaryJurisdiction,
                ),
                'start_date' => $this->nullableText($row['start_date'] ?? null),
                'expiry_date' => $this->nullableText($row['expiry_date'] ?? null),
                'review_date' => $this->nullableText($row['review_date'] ?? null),
                'status' => $status,
                'evidence_reference' => $this->nullableText(
                    $row['evidence_reference'] ?? null,
                ),
            ];
        }

        return $normalized;
    }

    /** @return list<array<string,mixed>> */
    private function normalizeRequirements(
        array $rows,
        string $primaryJurisdiction,
    ): array {
        $normalized = [];

        foreach (array_values($rows) as $row) {
            if (! is_array($row)) {
                throw new InvalidArgumentException(
                    'Legal Requirement row must be structured.',
                );
            }

            $key = trim((string) ($row['requirement_key'] ?? ''));
            $title = trim((string) ($row['title'] ?? ''));
            $category = trim((string) ($row['category'] ?? ''));
            $description = trim((string) ($row['description'] ?? ''));
            $status = trim((string) ($row['status'] ?? 'identified'));

            if (
                $key === ''
                || $title === ''
                || $category === ''
                || $description === ''
                || ! in_array($status, [
                    'identified', 'met', 'warning', 'blocked', 'not_applicable',
                ], true)
            ) {
                throw new InvalidArgumentException(
                    'Legal Requirement row is invalid.',
                );
            }

            $normalized[] = [
                'requirement_key' => $key,
                'title' => $title,
                'category' => $category,
                'description' => $description,
                'jurisdiction_code' => $this->jurisdiction(
                    $row['jurisdiction_code'] ?? $primaryJurisdiction,
                ),
                'source_authority' => $this->nullableText(
                    $row['source_authority'] ?? null,
                ),
                'applicable_from' => $this->nullableText(
                    $row['applicable_from'] ?? null,
                ),
                'applicable_until' => $this->nullableText(
                    $row['applicable_until'] ?? null,
                ),
                'status' => $status,
                'legal_review_required' => (bool) (
                    $row['legal_review_required'] ?? false
                ),
                'evidence_reference' => $this->nullableText(
                    $row['evidence_reference'] ?? null,
                ),
            ];
        }

        return $normalized;
    }

    /** @return list<array<string,mixed>> */
    private function normalizeReviews(
        array $rows,
        array $requirements,
    ): array {
        $normalized = [];

        foreach (array_values($rows) as $row) {
            if (! is_array($row)) {
                throw new InvalidArgumentException(
                    'Legal Review row must be structured.',
                );
            }

            $type = trim((string) ($row['review_type'] ?? ''));
            $name = trim((string) ($row['reviewer_name'] ?? ''));
            $capacity = trim((string) ($row['reviewer_capacity'] ?? ''));
            $reviewDate = trim((string) ($row['review_date'] ?? ''));
            $outcome = trim((string) ($row['outcome'] ?? ''));

            if (
                $type === ''
                || $name === ''
                || $capacity === ''
                || $reviewDate === ''
                || ! in_array($outcome, [
                    'pending', 'passed', 'qualified', 'issues_found', 'rejected',
                ], true)
            ) {
                throw new InvalidArgumentException('Legal Review row is invalid.');
            }

            $requirementIndex = $row['requirement_index'] ?? null;

            if (
                $requirementIndex !== null
                && (! is_numeric($requirementIndex)
                    || ! isset($requirements[(int) $requirementIndex]))
            ) {
                throw new InvalidArgumentException(
                    'Legal Review requirement reference is invalid.',
                );
            }

            $normalized[] = [
                'requirement_index' => $requirementIndex === null
                    ? null
                    : (int) $requirementIndex,
                'review_type' => $type,
                'reviewer_name' => $name,
                'reviewer_capacity' => $capacity,
                'reviewer_organization' => $this->nullableText(
                    $row['reviewer_organization'] ?? null,
                ),
                'review_date' => $reviewDate,
                'outcome' => $outcome,
                'notes' => $this->nullableText($row['notes'] ?? null),
                'evidence_reference' => $this->nullableText(
                    $row['evidence_reference'] ?? null,
                ),
            ];
        }

        return $normalized;
    }

    /**
     * @param array<string,mixed> $snapshot
     */
    private function insertSnapshot(
        Business $business,
        FormalRecordVersion $version,
        array $snapshot,
        string $creatorMembershipId,
    ): void {
        $businessId = (string) $business->getKey();
        $versionId = (string) $version->getKey();
        $now = now();

        DB::table('legal_structure_versions')->insert([
            'id' => (string) Str::uuid7(),
            'business_id' => $businessId,
            'formal_record_version_id' => $versionId,
            'legal_form' => $snapshot['legal_form'],
            'entity_name' => $snapshot['entity_name'],
            'primary_jurisdiction_code' => $snapshot['primary_jurisdiction_code'],
            'governing_law_reference' => $snapshot['governing_law_reference'],
            'registered_address' => $snapshot['registered_address'],
            'confidentiality' => $snapshot['confidentiality'],
            'notes' => $snapshot['notes'],
            'created_by_membership_id' => $creatorMembershipId,
            'created_at' => $now,
        ]);

        foreach ($snapshot['jurisdictions'] as $row) {
            DB::table('legal_jurisdiction_applicabilities')->insert([
                'id' => (string) Str::uuid7(),
                'business_id' => $businessId,
                'formal_record_version_id' => $versionId,
                ...$row,
                'created_at' => $now,
            ]);
        }

        foreach ($snapshot['registrations'] as $row) {
            DB::table('legal_registrations')->insert([
                'id' => (string) Str::uuid7(),
                'business_id' => $businessId,
                'formal_record_version_id' => $versionId,
                ...$row,
                'created_at' => $now,
            ]);
        }

        foreach ($snapshot['licenses'] as $row) {
            DB::table('legal_license_permits')->insert([
                'id' => (string) Str::uuid7(),
                'business_id' => $businessId,
                'formal_record_version_id' => $versionId,
                ...$row,
                'created_at' => $now,
            ]);
        }

        $requirementIds = [];

        foreach ($snapshot['requirements'] as $index => $row) {
            $requirementId = (string) Str::uuid7();
            $requirementIds[$index] = $requirementId;

            DB::table('legal_requirements')->insert([
                'id' => $requirementId,
                'business_id' => $businessId,
                'formal_record_version_id' => $versionId,
                ...$row,
                'created_at' => $now,
            ]);
        }

        foreach ($snapshot['reviews'] as $row) {
            $requirementIndex = $row['requirement_index'];
            unset($row['requirement_index']);

            DB::table('legal_reviews')->insert([
                'id' => (string) Str::uuid7(),
                'business_id' => $businessId,
                'formal_record_version_id' => $versionId,
                'legal_requirement_id' => $requirementIndex === null
                    ? null
                    : $requirementIds[$requirementIndex],
                ...$row,
                'created_at' => $now,
            ]);
        }
    }

    private function jurisdiction(mixed $value): string
    {
        $code = strtoupper(trim((string) ($value ?? '')));

        if (
            $code === ''
            || strlen($code) > 24
            || preg_match('/^[A-Z0-9][A-Z0-9._-]*$/', $code) !== 1
        ) {
            throw new InvalidArgumentException(
                'Jurisdiction code is required and must use a stable uppercase code.',
            );
        }

        return $code;
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