<?php

declare(strict_types=1);

namespace App\Application\Governance;

use App\Application\Records\CreateAmendedDraftVersion;
use App\Application\Records\CreateDraftRecordVersion;
use App\Application\Records\CreateFormalRecordFamily;
use App\Application\Records\CreateProposal;
use App\Application\Records\FreezeProposalVersion;
use App\Application\Records\SubmitRecordVersionForReview;
use App\Application\Records\TransitionFormalRecordVersion;
use App\Domain\Access\CapabilityCatalog;
use App\Domain\Access\ValueObjects\Capability;
use App\Domain\Governance\Enums\DecisionMethod;
use App\Domain\Governance\ValueObjects\AuthorityThreshold;
use App\Domain\Records\Enums\FormalRecordState;
use App\Domain\Records\ValueObjects\RecordScope;
use App\Infrastructure\Persistence\Eloquent\Businesses\Business;
use App\Infrastructure\Persistence\Eloquent\Identity\User;
use App\Infrastructure\Persistence\Eloquent\Members\Membership;
use App\Infrastructure\Persistence\Eloquent\Records\FormalRecordFamily;
use App\Infrastructure\Persistence\Eloquent\Records\FormalRecordVersion;
use App\Infrastructure\Persistence\Eloquent\Records\RecordFamilyEffectiveHead;
use DateTimeInterface;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;
use RuntimeException;

final class GovernanceCharterWorkflow
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
        $membership = $this->actorContext->membership(
            $user,
            $business,
            CapabilityCatalog::GOVERNANCE_RECORDS_MANAGE,
        );

        if (
            $membership === null
            || $this->actorContext->membership(
                $user,
                $business,
                CapabilityCatalog::RECORDS_MANAGE,
            ) === null
        ) {
            return null;
        }

        $normalized = $this->normalizePayload(
            $business,
            $payload,
        );
        $contentHash = $this->contentHash($normalized);

        return DB::transaction(function () use (
            $user,
            $business,
            $normalized,
            $contentHash,
            $effectiveFrom,
            $reviewDueAt,
        ): ?array {
            $capability = new Capability(
                CapabilityCatalog::RECORDS_MANAGE,
            );

            $family = FormalRecordFamily::query()
                ->where('business_id', $business->getKey())
                ->where('record_type', 'governance_charter')
                ->where('subject_type', 'business')
                ->where('subject_id', (string) $business->getKey())
                ->lockForUpdate()
                ->first();

            $version = null;

            if ($family === null) {
                $family = $this->createFamily->execute(
                    $user,
                    $business,
                    $capability,
                    new RecordScope(
                        'governance_charter',
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
                    'Initial Governance Charter.',
                    $effectiveFrom,
                    null,
                    $reviewDueAt,
                );
            } else {
                $effectiveHead = RecordFamilyEffectiveHead::query()
                    ->where('business_id', $business->getKey())
                    ->where(
                        'formal_record_family_id',
                        $family->getKey(),
                    )
                    ->lockForUpdate()
                    ->first();

                if ($effectiveHead === null) {
                    throw new RuntimeException(
                        'An existing Governance Charter draft/review must finish before another version can begin.',
                    );
                }

                $version = $this->createAmendedDraft->execute(
                    $user,
                    $business,
                    $capability,
                    (string) $effectiveHead->formal_record_version_id,
                    $contentHash,
                    'Governance Charter amendment.',
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
            );

            $this->occurrence->record(
                $user,
                $business,
                'governance.charter.draft_created',
                'governance_charter',
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
        $version = FormalRecordVersion::query()
            ->where('business_id', $business->getKey())
            ->whereKey($formalRecordVersionId)
            ->first();

        if ($version === null) {
            return null;
        }

        $family = FormalRecordFamily::query()
            ->where('business_id', $business->getKey())
            ->whereKey($version->formal_record_family_id)
            ->where('record_type', 'governance_charter')
            ->first();

        if ($family === null) {
            return null;
        }

        return DB::transaction(function () use (
            $user,
            $business,
            $version,
            $expectedRevision,
        ): ?array {
            $capability = new Capability(
                CapabilityCatalog::RECORDS_MANAGE,
            );

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
                'governance.charter.submitted',
                'governance_charter',
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
        if (! in_array(
            $target,
            [
                FormalRecordState::UnderReview,
                FormalRecordState::Approved,
                FormalRecordState::ChangesRequested,
            ],
            true,
        )) {
            throw new InvalidArgumentException(
                'Governance Charter content review target is invalid.',
            );
        }

        if ($this->actorContext->membership(
            $user,
            $business,
            CapabilityCatalog::GOVERNANCE_RECORDS_MANAGE,
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

    /**
     * @param  array<string,mixed>  $payload
     * @return array<string,mixed>
     */
    private function normalizePayload(
        Business $business,
        array $payload,
    ): array {
        $businessId = (string) $business->getKey();

        $requiredText = [
            'governance_owner_membership_id',
            'voting_basis',
            'default_approval_rule',
            'minutes_owner_membership_id',
            'conflict_of_interest_rule',
            'deadlock_rule',
        ];

        foreach ($requiredText as $key) {
            if (
                ! isset($payload[$key])
                || trim((string) $payload[$key]) === ''
            ) {
                throw new InvalidArgumentException(
                    sprintf('Governance Charter %s is required.', $key),
                );
            }
        }

        $memberIds = [
            trim((string) $payload['governance_owner_membership_id']),
            trim((string) $payload['minutes_owner_membership_id']),
        ];

        $rulesInput = $payload['rules'] ?? null;

        if (! is_array($rulesInput) || $rulesInput === []) {
            throw new InvalidArgumentException(
                'Governance Charter requires at least one Decision/Authority rule.',
            );
        }

        $rules = [];
        $seenRanges = [];

        foreach (array_values($rulesInput) as $index => $rawRule) {
            if (! is_array($rawRule)) {
                throw new InvalidArgumentException(
                    'Governance Charter rule must be structured.',
                );
            }

            $decisionType = trim(
                (string) ($rawRule['decision_type'] ?? ''),
            );
            $category = trim((string) ($rawRule['category'] ?? ''));
            $method = DecisionMethod::from(
                trim((string) ($rawRule['decision_method'] ?? '')),
            );

            if (
                $decisionType === ''
                || ! in_array(
                    $category,
                    [
                        'daily_operating',
                        'management',
                        'major_business',
                        'ownership_structural',
                        'custom',
                    ],
                    true,
                )
            ) {
                throw new InvalidArgumentException(
                    'Governance Decision rule type/category is invalid.',
                );
            }

            $threshold = new AuthorityThreshold(
                $method,
                (int) ($rawRule['required_approvals'] ?? 0),
                (int) ($rawRule['required_votes'] ?? 0),
                (int) ($rawRule['quorum_count'] ?? 0),
            );

            $amountMin = $this->normalizeMoney(
                $rawRule['amount_min'] ?? null,
            );
            $amountMax = $this->normalizeMoney(
                $rawRule['amount_max'] ?? null,
            );

            if (
                $amountMin !== null
                && $amountMax !== null
                && (float) $amountMax < (float) $amountMin
            ) {
                throw new InvalidArgumentException(
                    'Governance authority amount maximum must not be below minimum.',
                );
            }

            $rangeKey = implode('|', [
                $decisionType,
                $amountMin ?? 'null',
                $amountMax ?? 'null',
            ]);

            if (isset($seenRanges[$rangeKey])) {
                throw new InvalidArgumentException(
                    'Governance Charter cannot contain duplicate Decision/amount authority ranges.',
                );
            }
            $seenRanges[$rangeKey] = true;

            $actorsInput = $rawRule['actors'] ?? null;

            if (! is_array($actorsInput) || $actorsInput === []) {
                throw new InvalidArgumentException(
                    'Every Governance rule requires explicit actors.',
                );
            }

            $actors = [];
            $actorMemberships = [];
            $decisionOwnerCount = 0;
            $approvalCount = 0;
            $voteCount = 0;

            foreach (array_values($actorsInput) as $actorInput) {
                if (! is_array($actorInput)) {
                    throw new InvalidArgumentException(
                        'Governance actor must be structured.',
                    );
                }

                $membershipId = trim(
                    (string) ($actorInput['membership_id'] ?? ''),
                );
                $capacity = trim(
                    (string) ($actorInput['capacity'] ?? ''),
                );

                if (
                    $membershipId === ''
                    || $capacity === ''
                    || isset($actorMemberships[$membershipId])
                ) {
                    throw new InvalidArgumentException(
                        'Governance rule actor Membership/capacity must be unique and nonblank.',
                    );
                }
                $actorMemberships[$membershipId] = true;
                $memberIds[] = $membershipId;

                $isOwner = (bool) (
                    $actorInput['is_decision_owner'] ?? false
                );
                $isConsulted = (bool) (
                    $actorInput['is_consulted'] ?? false
                );
                $canApprove = (bool) (
                    $actorInput['can_approve'] ?? false
                );
                $canVote = (bool) (
                    $actorInput['can_vote'] ?? false
                );
                $canSign = (bool) (
                    $actorInput['can_sign'] ?? false
                );

                if (
                    ! $isOwner
                    && ! $isConsulted
                    && ! $canApprove
                    && ! $canVote
                    && ! $canSign
                ) {
                    throw new InvalidArgumentException(
                        'Governance actor must have an explicit matrix responsibility.',
                    );
                }

                $decisionOwnerCount += $isOwner ? 1 : 0;
                $approvalCount += $canApprove ? 1 : 0;
                $voteCount += $canVote ? 1 : 0;

                $actors[] = [
                    'membership_id' => $membershipId,
                    'capacity' => $capacity,
                    'is_decision_owner' => $isOwner,
                    'is_consulted' => $isConsulted,
                    'can_approve' => $canApprove,
                    'can_vote' => $canVote,
                    'can_sign' => $canSign,
                ];
            }

            if ($decisionOwnerCount !== 1) {
                throw new InvalidArgumentException(
                    'Every Governance Decision rule requires exactly one Decision Owner.',
                );
            }

            $authorityActorCount = count(array_filter(
                $actors,
                static fn (array $actor): bool => $actor['can_approve']
                    || $actor['can_vote']
                    || $actor['can_sign'],
            ));

            if (
                $threshold->requiredApprovals > $approvalCount
                || $threshold->requiredVotes > $voteCount
                || $threshold->quorumCount > $authorityActorCount
            ) {
                throw new InvalidArgumentException(
                    'Governance rule threshold exceeds its eligible authority actors.',
                );
            }

            $rules[] = [
                'sequence' => $index + 1,
                'decision_type' => $decisionType,
                'category' => $category,
                'decision_method' => $method->value,
                'required_approvals' => $threshold->requiredApprovals,
                'required_votes' => $threshold->requiredVotes,
                'quorum_count' => $threshold->quorumCount,
                'signature_required' => (bool) (
                    $rawRule['signature_required'] ?? false
                ),
                'reserved_matter' => (bool) (
                    $rawRule['reserved_matter'] ?? false
                ),
                'meeting_required' => (bool) (
                    $rawRule['meeting_required'] ?? false
                ),
                'record_required' => (bool) (
                    $rawRule['record_required'] ?? true
                ),
                'amount_min' => $amountMin,
                'amount_max' => $amountMax,
                'actors' => $actors,
            ];
        }

        $memberIds = array_values(array_unique($memberIds));

        $activeMembers = Membership::query()
            ->where('business_id', $businessId)
            ->whereIn('id', $memberIds)
            ->where('access_status', 'active')
            ->pluck('id')
            ->map(static fn ($id): string => (string) $id)
            ->all();

        if (count($activeMembers) !== count($memberIds)) {
            throw new InvalidArgumentException(
                'Governance Charter may reference only active Memberships in the current Business.',
            );
        }

        return [
            'governance_owner_membership_id' => trim((string) $payload['governance_owner_membership_id']),
            'voting_basis' => trim((string) $payload['voting_basis']),
            'default_approval_rule' => trim((string) $payload['default_approval_rule']),
            'meeting_frequency' => $this->nullableText(
                $payload['meeting_frequency'] ?? null,
            ),
            'default_quorum_count' => (int) ($payload['default_quorum_count'] ?? 0),
            'minutes_owner_membership_id' => trim((string) $payload['minutes_owner_membership_id']),
            'conflict_of_interest_rule' => trim((string) $payload['conflict_of_interest_rule']),
            'deadlock_rule' => trim((string) $payload['deadlock_rule']),
            'remote_voting_allowed' => (bool) (
                $payload['remote_voting_allowed'] ?? false
            ),
            'written_resolution_allowed' => (bool) (
                $payload['written_resolution_allowed'] ?? false
            ),
            'rules' => $rules,
        ];
    }

    /**
     * @param  array<string,mixed>  $normalized
     */
    private function insertSnapshot(
        Business $business,
        FormalRecordVersion $version,
        array $normalized,
    ): void {
        if ((int) $normalized['default_quorum_count'] < 1) {
            throw new InvalidArgumentException(
                'Governance Charter default quorum must be at least one.',
            );
        }

        $businessId = (string) $business->getKey();
        $versionId = (string) $version->getKey();

        DB::table('governance_charter_versions')->insert([
            'id' => (string) Str::uuid7(),
            'business_id' => $businessId,
            'formal_record_version_id' => $versionId,
            'governance_owner_membership_id' => $normalized['governance_owner_membership_id'],
            'voting_basis' => $normalized['voting_basis'],
            'default_approval_rule' => $normalized['default_approval_rule'],
            'meeting_frequency' => $normalized['meeting_frequency'],
            'default_quorum_count' => $normalized['default_quorum_count'],
            'minutes_owner_membership_id' => $normalized['minutes_owner_membership_id'],
            'conflict_of_interest_rule' => $normalized['conflict_of_interest_rule'],
            'deadlock_rule' => $normalized['deadlock_rule'],
            'remote_voting_allowed' => $normalized['remote_voting_allowed'],
            'written_resolution_allowed' => $normalized['written_resolution_allowed'],
            'created_at' => now(),
        ]);

        foreach ($normalized['rules'] as $rule) {
            $ruleId = (string) Str::uuid7();

            DB::table('governance_charter_rules')->insert([
                'id' => $ruleId,
                'business_id' => $businessId,
                'formal_record_version_id' => $versionId,
                'sequence' => $rule['sequence'],
                'decision_type' => $rule['decision_type'],
                'category' => $rule['category'],
                'decision_method' => $rule['decision_method'],
                'required_approvals' => $rule['required_approvals'],
                'required_votes' => $rule['required_votes'],
                'quorum_count' => $rule['quorum_count'],
                'signature_required' => $rule['signature_required'],
                'reserved_matter' => $rule['reserved_matter'],
                'meeting_required' => $rule['meeting_required'],
                'record_required' => $rule['record_required'],
                'amount_min' => $rule['amount_min'],
                'amount_max' => $rule['amount_max'],
                'created_at' => now(),
            ]);

            foreach ($rule['actors'] as $actor) {
                DB::table(
                    'governance_charter_rule_actors',
                )->insert([
                    'id' => (string) Str::uuid7(),
                    'business_id' => $businessId,
                    'governance_charter_rule_id' => $ruleId,
                    'membership_id' => $actor['membership_id'],
                    'capacity' => $actor['capacity'],
                    'is_decision_owner' => $actor['is_decision_owner'],
                    'is_consulted' => $actor['is_consulted'],
                    'can_approve' => $actor['can_approve'],
                    'can_vote' => $actor['can_vote'],
                    'can_sign' => $actor['can_sign'],
                    'created_at' => now(),
                ]);
            }
        }
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

    private function normalizeMoney(mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        $value = trim((string) $value);

        if (! preg_match(
            '/^(?:0|[1-9][0-9]*)(?:\.[0-9]{1,2})?$/',
            $value,
        )) {
            throw new InvalidArgumentException(
                'Governance authority amount must use non-negative two-decimal precision.',
            );
        }

        [$whole, $fraction] = array_pad(
            explode('.', $value, 2),
            2,
            '',
        );

        return $whole.'.'.str_pad($fraction, 2, '0');
    }

    private function nullableText(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $text = trim((string) $value);

        return $text === '' ? null : $text;
    }
}
