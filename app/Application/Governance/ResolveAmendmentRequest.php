<?php

declare(strict_types=1);

namespace App\Application\Governance;

use App\Domain\Access\CapabilityCatalog;
use App\Infrastructure\Persistence\Eloquent\Businesses\Business;
use App\Infrastructure\Persistence\Eloquent\Governance\AmendmentRequest;
use App\Infrastructure\Persistence\Eloquent\Identity\User;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

final class ResolveAmendmentRequest
{
    public function __construct(
        private readonly GovernanceActorContext $actorContext,
        private readonly RecordGovernanceOccurrence $occurrence,
    ) {}

    public function execute(
        User $user,
        Business $business,
        string $requestId,
        string $outcome,
    ): ?AmendmentRequest {
        $membership = $this->actorContext->membership(
            $user,
            $business,
            CapabilityCatalog::GOVERNANCE_RECORDS_MANAGE,
        );

        if ($membership === null) {
            return null;
        }

        if (! in_array($outcome, ['accepted', 'rejected'], true)) {
            throw new InvalidArgumentException(
                'Amendment resolution must be accepted or rejected.',
            );
        }

        return DB::transaction(function () use (
            $user,
            $business,
            $requestId,
            $outcome,
            $membership,
        ): ?AmendmentRequest {
            $request = AmendmentRequest::query()
                ->where('business_id', $business->getKey())
                ->whereKey($requestId)
                ->lockForUpdate()
                ->first();

            if ($request === null || $request->status !== 'open') {
                return null;
            }

            if (! $this->actorContext->canAccessResource(
                $user,
                $business,
                CapabilityCatalog::GOVERNANCE_RECORDS_MANAGE,
                AmendmentRequest::class,
                (string) $request->getKey(),
            )) {
                return null;
            }

            $request->fill([
                'status' => $outcome,
                'resolved_by_membership_id' => $membership->getKey(),
                'resolved_at' => now(),
            ])->save();

            $this->occurrence->record(
                $user,
                $business,
                'governance.amendment.resolved',
                'amendment_request',
                (string) $request->getKey(),
                ['outcome' => $outcome],
                (string) $request->formal_record_version_id,
            );

            return $request->fresh();
        });
    }
}
