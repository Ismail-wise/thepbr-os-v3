<?php

declare(strict_types=1);

namespace App\Application\Governance;

use App\Domain\Access\CapabilityCatalog;
use App\Infrastructure\Persistence\Eloquent\Businesses\Business;
use App\Infrastructure\Persistence\Eloquent\Governance\ProposalReview;
use App\Infrastructure\Persistence\Eloquent\Identity\User;
use App\Infrastructure\Persistence\Eloquent\Members\Membership;
use App\Infrastructure\Persistence\Eloquent\Records\ProposalVersion;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;

final class CreateProposalReview
{
    public function __construct(
        private readonly GovernanceActorContext $actorContext,
        private readonly CreateGovernanceNotification $notifications,
        private readonly RecordGovernanceOccurrence $occurrence,
    ) {}

    public function execute(
        User $user,
        Business $business,
        string $proposalVersionId,
        string $reviewerMembershipId,
        ?CarbonInterface $dueAt = null,
    ): ?ProposalReview {
        $creator = $this->actorContext->membership(
            $user,
            $business,
            CapabilityCatalog::GOVERNANCE_RECORDS_MANAGE,
        );

        if ($creator === null) {
            return null;
        }

        return DB::transaction(function () use (
            $user,
            $business,
            $proposalVersionId,
            $reviewerMembershipId,
            $dueAt,
            $creator,
        ): ?ProposalReview {
            $proposalVersion = ProposalVersion::query()
                ->where('business_id', $business->getKey())
                ->whereKey($proposalVersionId)
                ->lockForUpdate()
                ->first();

            $reviewer = Membership::query()
                ->where('business_id', $business->getKey())
                ->whereKey($reviewerMembershipId)
                ->where('access_status', 'active')
                ->first();

            if (
                $proposalVersion === null
                || $reviewer === null
                || ! $this->actorContext->canAccessResource(
                    $user,
                    $business,
                    CapabilityCatalog::GOVERNANCE_RECORDS_MANAGE,
                    ProposalVersion::class,
                    (string) $proposalVersion->getKey(),
                )
            ) {
                return null;
            }

            $existing = ProposalReview::query()
                ->where('business_id', $business->getKey())
                ->where(
                    'proposal_version_id',
                    $proposalVersion->getKey(),
                )
                ->lockForUpdate()
                ->first();

            if ($existing !== null) {
                return null;
            }

            $review = ProposalReview::query()->create([
                'business_id' => $business->getKey(),
                'proposal_version_id' => $proposalVersion->getKey(),
                'reviewer_membership_id' => $reviewer->getKey(),
                'created_by_membership_id' => $creator->getKey(),
                'status' => 'open',
                'outcome' => null,
                'notes' => null,
                'due_at' => $dueAt,
                'resolved_at' => null,
            ]);

            $this->notifications->execute(
                $business,
                (string) $reviewer->getKey(),
                'governance.proposal_review.assigned',
                'proposal_review',
                (string) $review->getKey(),
            );

            $this->occurrence->record(
                $user,
                $business,
                'governance.proposal_review.created',
                'proposal_review',
                (string) $review->getKey(),
                [
                    'proposal_version_id' => (string) $proposalVersion->getKey(),
                ],
                (string) $proposalVersion->getKey(),
            );

            return $review->fresh();
        });
    }
}
