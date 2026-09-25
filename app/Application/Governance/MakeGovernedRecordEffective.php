<?php

declare(strict_types=1);

namespace App\Application\Governance;

use App\Application\Records\TransitionFormalRecordVersion;
use App\Domain\Access\CapabilityCatalog;
use App\Domain\Access\ValueObjects\Capability;
use App\Domain\Governance\Enums\DecisionOutcome;
use App\Domain\Governance\Enums\DecisionStatus;
use App\Domain\Governance\Enums\SignatureRequestStatus;
use App\Domain\Records\Enums\FormalRecordState;
use App\Domain\Records\ValueObjects\RequirementResult;
use App\Infrastructure\Persistence\Eloquent\Businesses\Business;
use App\Infrastructure\Persistence\Eloquent\Governance\AuthoritySnapshot;
use App\Infrastructure\Persistence\Eloquent\Governance\Decision;
use App\Infrastructure\Persistence\Eloquent\Governance\SignatureRequest;
use App\Infrastructure\Persistence\Eloquent\Identity\User;
use App\Infrastructure\Persistence\Eloquent\Records\ProposalVersionRecord;

final class MakeGovernedRecordEffective
{
    public function __construct(
        private readonly GovernanceActorContext $actorContext,
        private readonly TransitionFormalRecordVersion $transition,
    ) {}

    public function execute(
        User $user,
        Business $business,
        string $decisionId,
        string $formalRecordVersionId,
    ): bool {
        if ($this->actorContext->membership(
            $user,
            $business,
            CapabilityCatalog::GOVERNANCE_RECORDS_MANAGE,
        ) === null) {
            return false;
        }

        $decision = Decision::query()
            ->where('business_id', $business->getKey())
            ->whereKey($decisionId)
            ->first();

        if (
            $decision === null
            || $decision->status !== DecisionStatus::Decided
            || $decision->outcome !== DecisionOutcome::Approved
        ) {
            return false;
        }

        if (! ProposalVersionRecord::query()
            ->where('business_id', $business->getKey())
            ->where('proposal_version_id', $decision->proposal_version_id)
            ->where('formal_record_version_id', $formalRecordVersionId)
            ->exists()) {
            return false;
        }

        $snapshot = AuthoritySnapshot::query()
            ->where('business_id', $business->getKey())
            ->whereKey($decision->authority_snapshot_id)
            ->first();

        if ($snapshot === null) {
            return false;
        }

        $requirements = [
            RequirementResult::met('governance_decision_approved'),
        ];

        if ($snapshot->signature_required) {
            $signed = SignatureRequest::query()
                ->where('business_id', $business->getKey())
                ->where('decision_id', $decision->getKey())
                ->where('status', SignatureRequestStatus::Completed->value)
                ->exists();

            $requirements[] = $signed
                ? RequirementResult::met('required_signature_completed')
                : RequirementResult::blocked('required_signature_completed');
        }

        return $this->transition->execute(
            $user,
            $business,
            new Capability(CapabilityCatalog::GOVERNANCE_RECORDS_MANAGE),
            $formalRecordVersionId,
            FormalRecordState::Effective,
            $requirements,
        ) !== null;
    }
}
