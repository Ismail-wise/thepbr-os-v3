<?php

declare(strict_types=1);

namespace App\Application\Governance;

use App\Domain\Access\CapabilityCatalog;
use App\Infrastructure\Persistence\Eloquent\Businesses\Business;
use App\Infrastructure\Persistence\Eloquent\Governance\Review;
use App\Infrastructure\Persistence\Eloquent\Identity\User;
use App\Infrastructure\Persistence\Eloquent\Members\Membership;
use App\Infrastructure\Persistence\Eloquent\Records\FormalRecordVersion;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;

final class CreateGovernanceReview
{
    public function __construct(
        private readonly GovernanceActorContext $actorContext,
        private readonly CreateGovernanceNotification $notifications,
        private readonly RecordGovernanceOccurrence $occurrence,
    ) {}

    public function execute(
        User $user,
        Business $business,
        string $formalRecordVersionId,
        string $reviewerMembershipId,
        ?CarbonInterface $dueAt = null,
    ): ?Review {
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
            $formalRecordVersionId,
            $reviewerMembershipId,
            $dueAt,
            $creator,
        ): ?Review {
            $version = FormalRecordVersion::query()
                ->where('business_id', $business->getKey())
                ->whereKey($formalRecordVersionId)
                ->first();

            $reviewer = Membership::query()
                ->where('business_id', $business->getKey())
                ->whereKey($reviewerMembershipId)
                ->where('access_status', 'active')
                ->first();

            if (
                $version === null
                || $reviewer === null
                || ! $this->actorContext->canAccessResource(
                    $user,
                    $business,
                    CapabilityCatalog::GOVERNANCE_RECORDS_MANAGE,
                    FormalRecordVersion::class,
                    (string) $version->getKey(),
                )
            ) {
                return null;
            }

            $review = Review::query()->create([
                'business_id' => $business->getKey(),
                'formal_record_version_id' => $version->getKey(),
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
                'governance.review.assigned',
                'governance_review',
                (string) $review->getKey(),
            );

            $this->occurrence->record(
                $user,
                $business,
                'governance.review.created',
                'governance_review',
                (string) $review->getKey(),
                [],
                (string) $version->getKey(),
            );

            return $review;
        });
    }
}
