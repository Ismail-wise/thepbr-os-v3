<?php

declare(strict_types=1);

namespace App\Application\Risk;

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
use App\Domain\Records\Enums\FormalRecordState;
use App\Domain\Records\ValueObjects\RecordScope;
use App\Domain\Risk\Services\RiskScorer;
use App\Infrastructure\Persistence\Eloquent\Businesses\Business;
use App\Infrastructure\Persistence\Eloquent\Governance\Decision;
use App\Infrastructure\Persistence\Eloquent\Identity\User;
use App\Infrastructure\Persistence\Eloquent\Records\FormalRecordFamily;
use App\Infrastructure\Persistence\Eloquent\Records\FormalRecordVersion;
use App\Infrastructure\Persistence\Eloquent\Records\ProposalVersionRecord;
use App\Infrastructure\Persistence\Eloquent\Records\RecordFamilyEffectiveHead;
use App\Infrastructure\Persistence\Eloquent\Risk\RiskItem;
use App\Infrastructure\Persistence\Eloquent\Risk\RiskProtectionRecord;
use DateTimeInterface;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;
use RuntimeException;

final class RiskRegisterWorkflow
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
        private readonly RiskScorer $scorer,
        private readonly RiskRecordVisibility $visibility,
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
            CapabilityCatalog::RISK_MANAGE,
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
                ->where('record_type', 'risk_register')
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
                        'risk_register',
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
                    'Initial Risk Register.',
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
                        'An existing Risk Register draft/review must finish before another version can begin.',
                    );
                }

                $version = $this->createAmendedDraft->execute(
                    $user,
                    $business,
                    $capability,
                    (string) $head->formal_record_version_id,
                    $contentHash,
                    'Risk Register amendment.',
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
                'risk.register.draft_created',
                'risk_register',
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
            CapabilityCatalog::RISK_MANAGE,
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
                'risk.register.submitted',
                'risk_register',
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
                'Risk Register content review target is invalid.',
            );
        }

        if ($this->actorContext->membership(
            $user,
            $business,
            CapabilityCatalog::RISK_MANAGE,
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
        string $decisionType = 'risk_register_approval',
    ): bool {
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
            return false;
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
        $ownerId = trim((string) ($payload['risk_owner_membership_id'] ?? ''));

        if (! $this->activeMember($business, $ownerId)) {
            throw new InvalidArgumentException(
                'Risk owner must be an active same-Business Membership.',
            );
        }

        $low = (int) ($payload['low_max_score'] ?? 4);
        $medium = (int) ($payload['medium_max_score'] ?? 9);
        $high = (int) ($payload['high_max_score'] ?? 15);

        // Threshold-only validation uses a valid score input.
        $this->scorer->score(1, 1, $low, $medium, $high);

        $reviewFrequency = trim((string) ($payload['review_frequency'] ?? ''));

        if ($reviewFrequency === '') {
            throw new InvalidArgumentException('Risk review frequency is required.');
        }

        $operationsVersion = $this->effectiveOperationsVersion($business);
        $risks = [];

        foreach (array_values((array) ($payload['risks'] ?? [])) as $raw) {
            if (! is_array($raw)) {
                throw new InvalidArgumentException('Risk row must be structured.');
            }

            $category = trim((string) ($raw['category'] ?? ''));
            $title = trim((string) ($raw['title'] ?? ''));
            $description = trim((string) ($raw['description'] ?? ''));
            $mitigation = trim((string) ($raw['mitigation'] ?? ''));
            $response = trim((string) ($raw['response_plan'] ?? ''));
            $likelihood = (int) ($raw['likelihood'] ?? 0);
            $impact = (int) ($raw['impact'] ?? 0);
            $owner = $this->nullableText($raw['owner_membership_id'] ?? null);
            $role = $this->nullableText($raw['operations_role_id'] ?? null);

            if (
                ! in_array($category, [
                    'operational', 'financial', 'people_key_person', 'customer_liability',
                    'technology_cyber', 'legal_regulatory', 'ip_brand_confidentiality',
                    'strategic_partnership',
                ], true)
                || $title === ''
                || $description === ''
                || $mitigation === ''
                || $response === ''
            ) {
                throw new InvalidArgumentException('Risk row is incomplete.');
            }

            if ($owner !== null && ! $this->activeMember($business, $owner)) {
                throw new InvalidArgumentException('Risk owner is not an active same-Business Membership.');
            }

            if ($role !== null) {
                if ($operationsVersion === null || $owner === null) {
                    throw new InvalidArgumentException(
                        'Operational Risk ownership requires Current Effective Operations role and owner.',
                    );
                }

                $validAssignment = DB::table('operations_role_assignments')
                    ->where('business_id', $business->getKey())
                    ->where('formal_record_version_id', $operationsVersion)
                    ->where('operations_role_id', $role)
                    ->where('membership_id', $owner)
                    ->exists();

                if (! $validAssignment) {
                    throw new InvalidArgumentException(
                        'Risk owner must hold the exact referenced Operations role.',
                    );
                }
            }

            $score = $this->scorer->score(
                $likelihood,
                $impact,
                $low,
                $medium,
                $high,
            );

            $risks[] = [
                'category' => $category,
                'title' => $title,
                'description' => $description,
                'operations_formal_record_version_id' => $role === null ? null : $operationsVersion,
                'operations_role_id' => $role,
                'owner_membership_id' => $owner,
                'likelihood' => $likelihood,
                'impact' => $impact,
                'risk_score' => $score['score'],
                'risk_level' => $score['level'],
                'warning_indicator' => $this->nullableText($raw['warning_indicator'] ?? null),
                'mitigation' => $mitigation,
                'response_plan' => $response,
                'review_date' => $this->nullableText($raw['review_date'] ?? null),
                'status' => in_array(($raw['status'] ?? 'active'), ['active', 'monitoring', 'treated', 'accepted', 'closed'], true)
                    ? $raw['status'] : 'active',
                'confidentiality' => ($raw['confidentiality'] ?? 'standard') === 'restricted'
                    ? 'restricted' : 'standard',
            ];
        }

        if ($risks === []) {
            throw new InvalidArgumentException('Risk Register requires at least one Risk.');
        }

        $protections = [];

        foreach (array_values((array) ($payload['protections'] ?? [])) as $raw) {
            if (! is_array($raw)) {
                throw new InvalidArgumentException('Protection row must be structured.');
            }

            $this->assertNoSecretPayloadKeys($raw);

            $type = trim((string) ($raw['protection_type'] ?? ''));
            $subject = trim((string) ($raw['covered_subject'] ?? ''));
            $protectionOwner = trim((string) ($raw['owner_membership_id'] ?? ''));
            $riskIndex = $raw['risk_index'] ?? null;

            if (
                ! in_array($type, [
                    'insurance', 'ip_brand', 'confidentiality_data',
                    'system_access', 'misconduct', 'other',
                ], true)
                || $subject === ''
                || ! $this->activeMember($business, $protectionOwner)
                || ($riskIndex !== null && (! is_numeric($riskIndex) || ! isset($risks[(int) $riskIndex])))
            ) {
                throw new InvalidArgumentException('Protection row is invalid.');
            }

            $protections[] = [
                'risk_index' => $riskIndex === null ? null : (int) $riskIndex,
                'protection_type' => $type,
                'covered_subject' => $subject,
                'provider' => $this->nullableText($raw['provider'] ?? null),
                'policy_reference' => $this->nullableText($raw['policy_reference'] ?? null),
                'coverage_amount_minor_units' => $this->moneyOrNull($raw['coverage_amount_minor_units'] ?? null),
                'currency' => $this->currencyOrNull($raw['currency'] ?? null),
                'deductible_minor_units' => $this->moneyOrNull($raw['deductible_minor_units'] ?? null),
                'main_exclusions' => $this->nullableText($raw['main_exclusions'] ?? null),
                'premium_minor_units' => $this->moneyOrNull($raw['premium_minor_units'] ?? null),
                'start_date' => $this->nullableText($raw['start_date'] ?? null),
                'renewal_date' => $this->nullableText($raw['renewal_date'] ?? null),
                'owner_membership_id' => $protectionOwner,
                'access_rule' => $this->nullableText($raw['access_rule'] ?? null),
                'protection_method' => $this->nullableText($raw['protection_method'] ?? null),
                'confidentiality_requirement' => $this->nullableText($raw['confidentiality_requirement'] ?? null),
                'evidence_reference' => $this->nullableText($raw['evidence_reference'] ?? null),
                'review_date' => $this->nullableText($raw['review_date'] ?? null),
                'status' => $this->nullableText($raw['status'] ?? null) ?? 'active',
                'confidentiality' => ($raw['confidentiality'] ?? 'standard') === 'restricted'
                    ? 'restricted' : 'standard',
            ];
        }

        return [
            'risk_owner_membership_id' => $ownerId,
            'low_max_score' => $low,
            'medium_max_score' => $medium,
            'high_max_score' => $high,
            'review_frequency' => $reviewFrequency,
            'notes' => $this->nullableText($payload['notes'] ?? null),
            'risks' => $risks,
            'protections' => $protections,
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

        DB::table('risk_register_versions')->insert([
            'id' => (string) Str::uuid7(),
            'business_id' => $businessId,
            'formal_record_version_id' => $versionId,
            'risk_owner_membership_id' => $normalized['risk_owner_membership_id'],
            'low_max_score' => $normalized['low_max_score'],
            'medium_max_score' => $normalized['medium_max_score'],
            'high_max_score' => $normalized['high_max_score'],
            'review_frequency' => $normalized['review_frequency'],
            'notes' => $normalized['notes'],
            'created_at' => now(),
        ]);

        $riskIds = [];

        foreach ($normalized['risks'] as $index => $risk) {
            $id = (string) Str::uuid7();
            $riskIds[$index] = $id;

            RiskItem::query()->create([
                'id' => $id,
                'business_id' => $businessId,
                'formal_record_version_id' => $versionId,
                ...$risk,
            ]);

            if ($risk['confidentiality'] === 'restricted') {
                $this->visibility->grantRestrictedAccess(
                    $business,
                    RiskItem::class,
                    $id,
                    array_filter([
                        $creatorMembershipId,
                        $risk['owner_membership_id'],
                    ]),
                );
            }
        }

        foreach ($normalized['protections'] as $protection) {
            $id = (string) Str::uuid7();
            $riskIndex = $protection['risk_index'];
            unset($protection['risk_index']);

            RiskProtectionRecord::query()->create([
                'id' => $id,
                'business_id' => $businessId,
                'formal_record_version_id' => $versionId,
                'risk_item_id' => $riskIndex === null ? null : $riskIds[$riskIndex],
                ...$protection,
            ]);

            if ($protection['confidentiality'] === 'restricted') {
                $this->visibility->grantRestrictedAccess(
                    $business,
                    RiskProtectionRecord::class,
                    $id,
                    [
                        $creatorMembershipId,
                        $protection['owner_membership_id'],
                    ],
                );
            }
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
            ->where('f.record_type', 'risk_register')
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
                    'Protection records must never store passwords, PINs, OTPs, tokens or secret credentials.',
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
            throw new InvalidArgumentException('Protection money value is invalid.');
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
            throw new InvalidArgumentException('Protection currency is invalid.');
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
