<?php

declare(strict_types=1);

namespace App\Application\Governance;

use App\Domain\Access\CapabilityCatalog;
use App\Domain\Governance\Enums\ProposalReviewOutcome;
use App\Infrastructure\Persistence\Eloquent\Businesses\Business;
use App\Infrastructure\Persistence\Eloquent\Governance\ProposalReview;
use App\Infrastructure\Persistence\Eloquent\Identity\User;
use Illuminate\Support\Facades\DB;

final class CompleteProposalReview
{
    public function __construct(
        private readonly GovernanceActorContext $actorContext,
        private readonly RecordGovernanceOccurrence $occurrence,
    ) {}

    public function execute(
        User $user,
        Business $business,
        string $proposalReviewId,
        ProposalReviewOutcome $outcome,
        ?string $notes = null,
    ): ?ProposalReview {
        $membership = $this->actorContext->membership(
            $user,
            $business,
            CapabilityCatalog::GOVERNANCE_RECORDS_MANAGE,
        );

        if ($membership === null) {
            return null;
        }

        return DB::transaction(function () use (
            $user,
            $business,
            $proposalReviewId,
            $outcome,
            $notes,
            $membership,
        ): ?ProposalReview {
            $review = ProposalReview::query()
                ->where('business_id', $business->getKey())
                ->whereKey($proposalReviewId)
                ->lockForUpdate()
                ->first();

            if (
                $review === null
                || $review->status !== 'open'
                || (string) $review->reviewer_membership_id
                    !== (string) $membership->getKey()
            ) {
                return null;
            }

            if (! $this->actorContext->canAccessResource(
                $user,
                $business,
                CapabilityCatalog::GOVERNANCE_RECORDS_MANAGE,
                ProposalReview::class,
                (string) $review->getKey(),
            )) {
                return null;
            }

            $review->fill([
                'status' => 'completed',
                'outcome' => $outcome->value,
                'notes' => $notes,
                'resolved_at' => now(),
            ])->save();

            $this->occurrence->record(
                $user,
                $business,
                'governance.proposal_review.completed',
                'proposal_review',
                (string) $review->getKey(),
                [
                    'outcome' => $outcome->value,
                ],
                (string) $review->proposal_version_id,
            );

            return $review->fresh();
        });
    }
}
