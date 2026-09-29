<?php

declare(strict_types=1);

namespace App\Application\Governance;

use App\Application\Access\AuthorizeBusinessCapability;
use App\Application\Records\CreateDraftRecordVersion;
use App\Application\Records\CreateFormalRecordFamily;
use App\Application\Records\SubmitRecordVersionForReview;
use App\Domain\Access\CapabilityCatalog;
use App\Domain\Access\ValueObjects\Capability;
use App\Domain\Governance\Enums\DecisionMethod;
use App\Domain\Governance\ValueObjects\AuthorityThreshold;
use App\Domain\Records\ValueObjects\RecordScope;
use App\Infrastructure\Persistence\Eloquent\Businesses\Business;
use App\Infrastructure\Persistence\Eloquent\Identity\User;
use App\Infrastructure\Persistence\Eloquent\Members\Membership;
use App\Infrastructure\Persistence\Eloquent\Records\FormalRecordFamily;
use App\Infrastructure\Persistence\Eloquent\Records\FormalRecordVersion;
use DateTimeInterface;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;
use RuntimeException;
use ValueError;

final class FormationAuthorityPolicyWorkflow
{
    public function __construct(
        private readonly AuthorizeBusinessCapability $authorize,
        private readonly CreateFormalRecordFamily $createFamily,
        private readonly CreateDraftRecordVersion $createDraftVersion,
        private readonly SubmitRecordVersionForReview $submitForReview,
        private readonly ResolveGovernanceAuthority $authority,
        private readonly RecordGovernanceOccurrence $occurrence,
    ) {}

    /**
     * @param  list<array<string,mixed>>  $rules
     * @return array{formal_record_version_id:string,version_number:int,revision:int}|null
     */
    public function createDraft(
        User $user,
        Business $business,
        array $rules,
        DateTimeInterface $effectiveFrom,
    ): ?array {
        if (! $this->canBootstrap($user, $business)) {
            return null;
        }

        $this->assertBootstrapWindowOpen($business);

        $normalizedRules = $this->normalizeRules(
            $business,
            $rules,
        );

        return DB::transaction(function () use (
            $user,
            $business,
            $normalizedRules,
            $effectiveFrom,
        ): ?array {
            $family = FormalRecordFamily::query()
                ->where('business_id', $business->getKey())
                ->where('record_type', 'formation_authority_policy')
                ->where('subject_type', 'business')
                ->where('subject_id', (string) $business->getKey())
                ->lockForUpdate()
                ->first();

            if ($family !== null) {
                throw new RuntimeException(
                    'The Formation Authority Policy has already been prepared for this Business.',
                );
            }

            $capability = new Capability(
                CapabilityCatalog::RECORDS_MANAGE,
            );

            $family = $this->createFamily->execute(
                $user,
                $business,
                $capability,
                new RecordScope(
                    'formation_authority_policy',
                    'business',
                    (string) $business->getKey(),
                ),
            );

            if ($family === null) {
                return null;
            }

            $contentHash = $this->contentHash([
                'effective_from' => $effectiveFrom->format(DATE_ATOM),
                'rules' => $normalizedRules,
            ]);

            $version = $this->createDraftVersion->execute(
                $user,
                $business,
                $capability,
                (string) $family->getKey(),
                $contentHash,
                'Initial Temporary Formation Authority Policy.',
                $effectiveFrom,
            );

            if ($version === null) {
                return null;
            }

            $this->insertRules(
                $business,
                $version,
                $normalizedRules,
            );

            $this->occurrence->record(
                $user,
                $business,
                'governance.formation_authority_policy.draft_created',
                'formation_authority_policy',
                (string) $family->getKey(),
                [
                    'formal_record_version_id' => (string) $version->getKey(),
                    'version_number' => (int) $version->version_number,
                ],
                (string) $version->getKey(),
            );

            return [
                'formal_record_version_id' => (string) $version->getKey(),
                'version_number' => (int) $version->version_number,
                'revision' => (int) $version->revision,
            ];
        });
    }

    /**
     * @return array{formal_record_version_id:string,revision:int,state:string}|null
     */
    public function freezeForBootstrap(
        User $user,
        Business $business,
        string $formalRecordVersionId,
        int $expectedRevision,
    ): ?array {
        if (! $this->canBootstrap($user, $business)) {
            return null;
        }

        $this->assertBootstrapWindowOpen($business);

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
            ->where('record_type', 'formation_authority_policy')
            ->first();

        if ($family === null) {
            return null;
        }

        $frozen = $this->submitForReview->execute(
            $user,
            $business,
            new Capability(CapabilityCatalog::RECORDS_MANAGE),
            (string) $version->getKey(),
            $expectedRevision,
        );

        if ($frozen === null) {
            return null;
        }

        $this->occurrence->record(
            $user,
            $business,
            'governance.formation_authority_policy.frozen',
            'formation_authority_policy',
            (string) $family->getKey(),
            [
                'formal_record_version_id' => (string) $frozen->getKey(),
                'revision' => (int) $frozen->revision,
            ],
            (string) $frozen->getKey(),
        );

        return [
            'formal_record_version_id' => (string) $frozen->getKey(),
            'revision' => (int) $frozen->revision,
            'state' => 'ready_for_review',
        ];
    }

    private function canBootstrap(
        User $user,
        Business $business,
    ): bool {
        foreach ([
            CapabilityCatalog::FORMATION_AUTHORITY_BOOTSTRAP,
            CapabilityCatalog::RECORDS_MANAGE,
        ] as $capabilityKey) {
            $decision = $this->authorize->decide(
                $user,
                $business,
                $business,
                new Capability($capabilityKey),
            );

            if (! $decision->allowed) {
                return false;
            }
        }

        return true;
    }

    private function assertBootstrapWindowOpen(
        Business $business,
    ): void {
        if ($this->authority->currentSource($business) !== null) {
            throw new RuntimeException(
                'Temporary Formation Authority bootstrap is unavailable after a Governance authority source is established.',
            );
        }
    }

    /**
     * @param  list<array<string,mixed>>  $rules
     * @return list<array<string,mixed>>
     */
    private function normalizeRules(
        Business $business,
        array $rules,
    ): array {
        if ($rules === []) {
            throw new InvalidArgumentException(
                'Temporary Formation Authority requires at least one explicit authority rule.',
            );
        }

        $normalized = [];
        $membershipIds = [];
        $seenRanges = [];

        foreach (array_values($rules) as $index => $rawRule) {
            if (! is_array($rawRule)) {
                throw new InvalidArgumentException(
                    'Formation Authority rule must be structured.',
                );
            }

            $decisionType = trim(
                (string) ($rawRule['decision_type'] ?? ''),
            );

            if ($decisionType === '') {
                throw new InvalidArgumentException(
                    'Formation Authority decision type is required.',
                );
            }

            try {
                $method = DecisionMethod::from(
                    trim((string) ($rawRule['decision_method'] ?? '')),
                );
            } catch (ValueError) {
                throw new InvalidArgumentException(
                    'Formation Authority decision method is invalid.',
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
                    'Formation Authority amount maximum must not be below minimum.',
                );
            }

            $rangeKey = implode('|', [
                $decisionType,
                $amountMin ?? 'null',
                $amountMax ?? 'null',
            ]);

            if (isset($seenRanges[$rangeKey])) {
                throw new InvalidArgumentException(
                    'Formation Authority cannot contain duplicate Decision/amount ranges.',
                );
            }

            $seenRanges[$rangeKey] = true;
            $actorsInput = $rawRule['actors'] ?? null;

            if (! is_array($actorsInput) || $actorsInput === []) {
                throw new InvalidArgumentException(
                    'Every Formation Authority rule requires explicit eligible actors.',
                );
            }

            $actors = [];
            $actorIds = [];
            $approverCount = 0;
            $voterCount = 0;

            foreach (array_values($actorsInput) as $rawActor) {
                if (! is_array($rawActor)) {
                    throw new InvalidArgumentException(
                        'Formation Authority actor must be structured.',
                    );
                }

                $membershipId = trim(
                    (string) ($rawActor['membership_id'] ?? ''),
                );
                $capacity = trim(
                    (string) ($rawActor['capacity'] ?? ''),
                );
                $canApprove = (bool) ($rawActor['can_approve'] ?? false);
                $canVote = (bool) ($rawActor['can_vote'] ?? false);
                $canSign = (bool) ($rawActor['can_sign'] ?? false);

                if (
                    $membershipId === ''
                    || $capacity === ''
                    || isset($actorIds[$membershipId])
                    || (! $canApprove && ! $canVote && ! $canSign)
                ) {
                    throw new InvalidArgumentException(
                        'Formation Authority actor Membership/capacity/authority is invalid or duplicated.',
                    );
                }

                $actorIds[$membershipId] = true;
                $membershipIds[] = $membershipId;
                $approverCount += $canApprove ? 1 : 0;
                $voterCount += $canVote ? 1 : 0;

                $actors[] = [
                    'membership_id' => $membershipId,
                    'capacity' => $capacity,
                    'can_approve' => $canApprove,
                    'can_vote' => $canVote,
                    'can_sign' => $canSign,
                ];
            }

            if (
                $approverCount < $threshold->requiredApprovals
                || $voterCount < $threshold->requiredVotes
                || count($actors) < $threshold->quorumCount
            ) {
                throw new InvalidArgumentException(
                    'Formation Authority thresholds exceed the explicitly eligible actors.',
                );
            }

            usort(
                $actors,
                static fn (array $left, array $right): int => strcmp(
                    $left['membership_id'],
                    $right['membership_id'],
                ),
            );

            $normalized[] = [
                'sequence' => $index + 1,
                'decision_type' => $decisionType,
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
                'amount_min' => $amountMin,
                'amount_max' => $amountMax,
                'actors' => $actors,
            ];
        }

        $membershipIds = array_values(
            array_unique($membershipIds),
        );

        $activeMembershipCount = Membership::query()
            ->where('business_id', $business->getKey())
            ->whereIn('id', $membershipIds)
            ->where('access_status', 'active')
            ->count();

        if ($activeMembershipCount !== count($membershipIds)) {
            throw new InvalidArgumentException(
                'Formation Authority actors must be active Memberships in the current Business.',
            );
        }

        return $normalized;
    }

    /**
     * @param  list<array<string,mixed>>  $rules
     */
    private function insertRules(
        Business $business,
        FormalRecordVersion $version,
        array $rules,
    ): void {
        $businessId = (string) $business->getKey();

        foreach ($rules as $rule) {
            $ruleId = (string) Str::uuid7();

            DB::table('formation_authority_policy_rules')
                ->insert([
                    'id' => $ruleId,
                    'business_id' => $businessId,
                    'formal_record_version_id' => $version->getKey(),
                    'sequence' => $rule['sequence'],
                    'decision_type' => $rule['decision_type'],
                    'decision_method' => $rule['decision_method'],
                    'required_approvals' => $rule['required_approvals'],
                    'required_votes' => $rule['required_votes'],
                    'quorum_count' => $rule['quorum_count'],
                    'signature_required' => $rule['signature_required'],
                    'reserved_matter' => $rule['reserved_matter'],
                    'amount_min' => $rule['amount_min'],
                    'amount_max' => $rule['amount_max'],
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);

            foreach ($rule['actors'] as $actor) {
                DB::table('formation_authority_policy_actors')
                    ->insert([
                        'id' => (string) Str::uuid7(),
                        'business_id' => $businessId,
                        'formation_authority_policy_rule_id' => $ruleId,
                        'membership_id' => $actor['membership_id'],
                        'capacity' => $actor['capacity'],
                        'can_approve' => $actor['can_approve'],
                        'can_vote' => $actor['can_vote'],
                        'can_sign' => $actor['can_sign'],
                        'created_at' => now(),
                        'updated_at' => now(),
                    ]);
            }
        }
    }

    /**
     * @param  array<string,mixed>  $payload
     */
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
                'Formation Authority amount must use non-negative two-decimal precision.',
            );
        }

        [$whole, $fraction] = array_pad(
            explode('.', $value, 2),
            2,
            '',
        );

        return $whole.'.'.str_pad($fraction, 2, '0');
    }
}
