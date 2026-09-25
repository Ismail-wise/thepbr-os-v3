<?php

declare(strict_types=1);

namespace App\Application\Governance;

use App\Domain\Access\CapabilityCatalog;
use App\Infrastructure\Persistence\Eloquent\Businesses\Business;
use App\Infrastructure\Persistence\Eloquent\Governance\AmendmentRequest;
use App\Infrastructure\Persistence\Eloquent\Governance\Review;
use App\Infrastructure\Persistence\Eloquent\Identity\User;
use App\Infrastructure\Persistence\Eloquent\Records\FormalRecordVersion;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

final class CreateAmendmentRequest
{
    public function __construct(
        private readonly GovernanceActorContext $actorContext,
        private readonly RecordGovernanceOccurrence $occurrence,
    ) {}

    public function execute(
        User $user,
        Business $business,
        string $formalRecordVersionId,
        string $reason,
        ?string $reviewId = null,
    ): ?AmendmentRequest {
        $membership = $this->actorContext->membership(
            $user,
            $business,
            CapabilityCatalog::GOVERNANCE_RECORDS_MANAGE,
        );

        if ($membership === null) {
            return null;
        }

        $reason = trim($reason);

        if ($reason === '') {
            throw new InvalidArgumentException('Amendment reason is required.');
        }

        return DB::transaction(function () use (
            $user,
            $business,
            $formalRecordVersionId,
            $reason,
            $reviewId,
            $membership,
        ): ?AmendmentRequest {
            $version = FormalRecordVersion::query()
                ->where('business_id', $business->getKey())
                ->whereKey($formalRecordVersionId)
                ->first();

            if (
                $version === null
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

            if ($reviewId !== null && ! Review::query()
                ->where('business_id', $business->getKey())
                ->whereKey($reviewId)
                ->exists()) {
                return null;
            }

            $request = AmendmentRequest::query()->create([
                'business_id' => $business->getKey(),
                'formal_record_version_id' => $version->getKey(),
                'review_id' => $reviewId,
                'requested_by_membership_id' => $membership->getKey(),
                'reason' => $reason,
                'status' => 'open',
                'resolved_by_membership_id' => null,
                'resolved_at' => null,
            ]);

            $this->occurrence->record(
                $user,
                $business,
                'governance.amendment.requested',
                'amendment_request',
                (string) $request->getKey(),
                [],
                (string) $version->getKey(),
            );

            return $request;
        });
    }
}
