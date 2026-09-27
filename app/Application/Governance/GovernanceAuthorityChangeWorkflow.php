<?php

declare(strict_types=1);

namespace App\Application\Governance;

use App\Application\Records\CreateProposal;
use App\Application\Records\FreezeProposalVersion;
use App\Domain\Access\CapabilityCatalog;
use App\Domain\Access\ValueObjects\Capability;
use App\Domain\Governance\Enums\DecisionOutcome;
use App\Domain\Governance\Enums\DecisionStatus;
use App\Domain\Governance\Enums\DelegationStatus;
use App\Domain\Governance\Enums\EmergencyAuthorityStatus;
use App\Infrastructure\Persistence\Eloquent\Businesses\Business;
use App\Infrastructure\Persistence\Eloquent\Governance\Decision;
use App\Infrastructure\Persistence\Eloquent\Governance\EmergencyAuthorityGrant;
use App\Infrastructure\Persistence\Eloquent\Governance\GovernanceDelegation;
use App\Infrastructure\Persistence\Eloquent\Identity\User;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Governed activation layer for F3 Delegation/Emergency foundation records.
 *
 * Foundation records stay inert until this workflow binds an exact Frozen
 * Proposal Version to a decided/approved governance Decision.
 */
final class GovernanceAuthorityChangeWorkflow
{
    public function __construct(
        private readonly GovernanceActorContext $actorContext,
        private readonly CreateGovernanceDelegation $createDelegation,
        private readonly RevokeGovernanceDelegation $revokeDelegation,
        private readonly CreateEmergencyAuthorityGrant $createEmergency,
        private readonly RevokeEmergencyAuthorityGrant $revokeEmergency,
        private readonly CreateProposal $createProposal,
        private readonly FreezeProposalVersion $freezeProposal,
        private readonly RecordGovernanceOccurrence $occurrence,
    ) {}

    /**
     * @return array{subject_id:string,submission_id:string,proposal_version_id:string}|null
     */
    public function proposeDelegation(
        User $user,
        Business $business,
        string $delegatorMembershipId,
        string $delegateMembershipId,
        string $decisionType,
        string $scope,
        ?CarbonInterface $effectiveFrom = null,
        ?CarbonInterface $expiresAt = null,
    ): ?array {
        $decisionType = trim($decisionType);

        if ($decisionType === '') {
            throw new RuntimeException(
                'F6 Delegation requires one exact Decision Type scope.',
            );
        }

        return DB::transaction(function () use (
            $user,
            $business,
            $delegatorMembershipId,
            $delegateMembershipId,
            $decisionType,
            $scope,
            $effectiveFrom,
            $expiresAt,
        ): ?array {
            $delegation = $this->createDelegation->execute(
                $user,
                $business,
                $delegatorMembershipId,
                $delegateMembershipId,
                $scope,
                $decisionType,
                $effectiveFrom,
                $expiresAt,
            );

            if ($delegation === null) {
                return null;
            }

            return $this->createSubmission(
                $user,
                $business,
                'delegation',
                (string) $delegation->getKey(),
                'grant',
                $this->delegationHash($delegation, 'grant'),
            );
        });
    }

    /**
     * @return array{subject_id:string,submission_id:string,proposal_version_id:string}|null
     */
    public function proposeEmergencyAuthority(
        User $user,
        Business $business,
        string $granteeMembershipId,
        string $decisionType,
        string $scope,
        string $capacity,
        bool $canApprove,
        bool $canVote,
        bool $canSign,
        string $reason,
        CarbonInterface $expiresAt,
        ?CarbonInterface $effectiveFrom = null,
    ): ?array {
        $decisionType = trim($decisionType);

        if ($decisionType === '') {
            throw new RuntimeException(
                'F6 Emergency Authority requires one exact Decision Type scope.',
            );
        }

        return DB::transaction(function () use (
            $user,
            $business,
            $granteeMembershipId,
            $decisionType,
            $scope,
            $capacity,
            $canApprove,
            $canVote,
            $canSign,
            $reason,
            $expiresAt,
            $effectiveFrom,
        ): ?array {
            $grant = $this->createEmergency->execute(
                $user,
                $business,
                $granteeMembershipId,
                $scope,
                $capacity,
                $canApprove,
                $canVote,
                $canSign,
                $reason,
                $expiresAt,
                $decisionType,
                $effectiveFrom,
            );

            if ($grant === null) {
                return null;
            }

            return $this->createSubmission(
                $user,
                $business,
                'emergency_authority',
                (string) $grant->getKey(),
                'grant',
                $this->emergencyHash($grant, 'grant'),
            );
        });
    }

    /**
     * @return array{subject_id:string,submission_id:string,proposal_version_id:string}|null
     */
    public function proposeRevocation(
        User $user,
        Business $business,
        string $subjectType,
        string $subjectId,
    ): ?array {
        if (! in_array(
            $subjectType,
            ['delegation', 'emergency_authority'],
            true,
        )) {
            throw new RuntimeException(
                'Governance authority revocation subject is invalid.',
            );
        }

        if ($this->actorContext->membership(
            $user,
            $business,
            CapabilityCatalog::GOVERNANCE_RECORDS_MANAGE,
        ) === null) {
            return null;
        }

        return DB::transaction(function () use (
            $user,
            $business,
            $subjectType,
            $subjectId,
        ): ?array {
            $approvedGrantExists = DB::table(
                'governance_authority_change_submissions',
            )
                ->where('business_id', $business->getKey())
                ->where('subject_type', $subjectType)
                ->where('subject_id', $subjectId)
                ->where('action', 'grant')
                ->whereNotNull('authorized_at')
                ->exists();

            if (! $approvedGrantExists) {
                return null;
            }

            if ($subjectType === 'delegation') {
                $subject = GovernanceDelegation::query()
                    ->where('business_id', $business->getKey())
                    ->whereKey($subjectId)
                    ->where('status', DelegationStatus::Active->value)
                    ->lockForUpdate()
                    ->first();

                if ($subject === null) {
                    return null;
                }

                $hash = $this->delegationHash($subject, 'revoke');
            } else {
                $subject = EmergencyAuthorityGrant::query()
                    ->where('business_id', $business->getKey())
                    ->whereKey($subjectId)
                    ->where(
                        'status',
                        EmergencyAuthorityStatus::Active->value,
                    )
                    ->lockForUpdate()
                    ->first();

                if ($subject === null) {
                    return null;
                }

                $hash = $this->emergencyHash($subject, 'revoke');
            }

            return $this->createSubmission(
                $user,
                $business,
                $subjectType,
                $subjectId,
                'revoke',
                $hash,
            );
        });
    }

    public function authorize(
        User $user,
        Business $business,
        string $submissionId,
    ): ?bool {
        if ($this->actorContext->membership(
            $user,
            $business,
            CapabilityCatalog::GOVERNANCE_RECORDS_MANAGE,
        ) === null) {
            return null;
        }

        return DB::transaction(function () use (
            $user,
            $business,
            $submissionId,
        ): ?bool {
            $submission = DB::table(
                'governance_authority_change_submissions',
            )
                ->where('business_id', $business->getKey())
                ->where('id', $submissionId)
                ->lockForUpdate()
                ->first();

            if ($submission === null) {
                return null;
            }

            if ($submission->authorized_at !== null) {
                return true;
            }

            $expectedDecisionType = match ([
                $submission->subject_type,
                $submission->action,
            ]) {
                ['delegation', 'grant'] => 'governance_delegation',
                ['delegation', 'revoke'] => 'governance_delegation_revoke',
                ['emergency_authority', 'grant'] => 'emergency_authority',
                ['emergency_authority', 'revoke'] => 'emergency_authority_revoke',
                default => throw new RuntimeException(
                    'Unsupported governance authority change.',
                ),
            };

            $decision = Decision::query()
                ->where('business_id', $business->getKey())
                ->where(
                    'proposal_version_id',
                    $submission->proposal_version_id,
                )
                ->where('decision_type', $expectedDecisionType)
                ->where('status', DecisionStatus::Decided->value)
                ->where('outcome', DecisionOutcome::Approved->value)
                ->first();

            if ($decision === null) {
                return false;
            }

            if ($submission->subject_type === 'delegation') {
                $subject = GovernanceDelegation::query()
                    ->where('business_id', $business->getKey())
                    ->whereKey($submission->subject_id)
                    ->lockForUpdate()
                    ->first();

                if (
                    $subject === null
                    || ! hash_equals(
                        (string) $submission->content_hash,
                        $this->delegationHash(
                            $subject,
                            (string) $submission->action,
                        ),
                    )
                ) {
                    throw new RuntimeException(
                        'Delegation changed after its Frozen Proposal Version.',
                    );
                }
            } else {
                $subject = EmergencyAuthorityGrant::query()
                    ->where('business_id', $business->getKey())
                    ->whereKey($submission->subject_id)
                    ->lockForUpdate()
                    ->first();

                if (
                    $subject === null
                    || ! hash_equals(
                        (string) $submission->content_hash,
                        $this->emergencyHash(
                            $subject,
                            (string) $submission->action,
                        ),
                    )
                ) {
                    throw new RuntimeException(
                        'Emergency Authority changed after its Frozen Proposal Version.',
                    );
                }
            }

            DB::table('governance_authority_change_submissions')
                ->where('business_id', $business->getKey())
                ->where('id', $submission->id)
                ->update([
                    'authorizing_decision_id' => $decision->getKey(),
                    'authorized_at' => now(),
                ]);

            if ($submission->action === 'revoke') {
                $revoked = $submission->subject_type === 'delegation'
                    ? $this->revokeDelegation->execute(
                        $user,
                        $business,
                        (string) $submission->subject_id,
                    )
                    : $this->revokeEmergency->execute(
                        $user,
                        $business,
                        (string) $submission->subject_id,
                    );

                if ($revoked === null) {
                    throw new RuntimeException(
                        'Approved governance authority revocation could not be applied.',
                    );
                }
            }

            $this->occurrence->record(
                $user,
                $business,
                'governance.authority_change.authorized',
                'governance_authority_change',
                (string) $submission->id,
                [
                    'subject_type' => (string) $submission->subject_type,
                    'action' => (string) $submission->action,
                    'decision_id' => (string) $decision->getKey(),
                ],
            );

            return true;
        });
    }

    /**
     * @return array{subject_id:string,submission_id:string,proposal_version_id:string}
     */
    private function createSubmission(
        User $user,
        Business $business,
        string $subjectType,
        string $subjectId,
        string $action,
        string $contentHash,
    ): array {
        $membership = $this->actorContext->membership(
            $user,
            $business,
            CapabilityCatalog::GOVERNANCE_RECORDS_MANAGE,
        );

        if ($membership === null) {
            throw new RuntimeException(
                'Governance system capability is required to submit authority change.',
            );
        }

        $proposal = $this->createProposal->execute(
            $user,
            $business,
            new Capability(CapabilityCatalog::GOVERNANCE_RECORDS_MANAGE),
            $contentHash,
        );

        if ($proposal === null) {
            throw new RuntimeException(
                'Governance authority Proposal could not be created.',
            );
        }

        $proposalVersion = $this->freezeProposal->execute(
            $user,
            $business,
            new Capability(CapabilityCatalog::GOVERNANCE_RECORDS_MANAGE),
            (string) $proposal->getKey(),
            1,
            [],
        );

        if ($proposalVersion === null) {
            throw new RuntimeException(
                'Governance authority Proposal Version could not be frozen.',
            );
        }

        $submissionId = (string) Str::uuid7();

        DB::table(
            'governance_authority_change_submissions',
        )->insert([
            'id' => $submissionId,
            'business_id' => $business->getKey(),
            'subject_type' => $subjectType,
            'subject_id' => $subjectId,
            'action' => $action,
            'content_hash' => $contentHash,
            'proposal_id' => $proposal->getKey(),
            'proposal_version_id' => $proposalVersion->getKey(),
            'authorizing_decision_id' => null,
            'created_by_membership_id' => $membership->getKey(),
            'authorized_at' => null,
            'created_at' => now(),
        ]);

        $this->occurrence->record(
            $user,
            $business,
            'governance.authority_change.proposed',
            'governance_authority_change',
            $submissionId,
            [
                'subject_type' => $subjectType,
                'action' => $action,
                'proposal_version_id' => (string) $proposalVersion->getKey(),
            ],
        );

        return [
            'subject_id' => $subjectId,
            'submission_id' => $submissionId,
            'proposal_version_id' => (string) $proposalVersion->getKey(),
        ];
    }

    private function delegationHash(
        GovernanceDelegation $delegation,
        string $action,
    ): string {
        return $this->hashPayload([
            'action' => $action,
            'subject_type' => 'delegation',
            'subject_id' => (string) $delegation->getKey(),
            'business_id' => (string) $delegation->business_id,
            'delegator_membership_id' => (string) $delegation->delegator_membership_id,
            'delegate_membership_id' => (string) $delegation->delegate_membership_id,
            'decision_type' => (string) $delegation->decision_type,
            'scope' => (string) $delegation->scope,
            'effective_from' => $delegation->effective_from?->format(DATE_ATOM),
            'expires_at' => $delegation->expires_at?->format(DATE_ATOM),
        ]);
    }

    private function emergencyHash(
        EmergencyAuthorityGrant $grant,
        string $action,
    ): string {
        return $this->hashPayload([
            'action' => $action,
            'subject_type' => 'emergency_authority',
            'subject_id' => (string) $grant->getKey(),
            'business_id' => (string) $grant->business_id,
            'grantee_membership_id' => (string) $grant->grantee_membership_id,
            'decision_type' => (string) $grant->decision_type,
            'scope' => (string) $grant->scope,
            'capacity' => (string) $grant->capacity,
            'can_approve' => (bool) $grant->can_approve,
            'can_vote' => (bool) $grant->can_vote,
            'can_sign' => (bool) $grant->can_sign,
            'reason' => (string) $grant->reason,
            'effective_from' => $grant->effective_from?->format(DATE_ATOM),
            'expires_at' => $grant->expires_at?->format(DATE_ATOM),
        ]);
    }

    /** @param array<string,mixed> $payload */
    private function hashPayload(array $payload): string
    {
        ksort($payload);

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
