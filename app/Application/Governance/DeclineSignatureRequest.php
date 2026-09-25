<?php

declare(strict_types=1);

namespace App\Application\Governance;

use App\Domain\Access\CapabilityCatalog;
use App\Domain\Governance\Enums\SignatureRequestStatus;
use App\Infrastructure\Persistence\Eloquent\Businesses\Business;
use App\Infrastructure\Persistence\Eloquent\Governance\SignatureParticipant;
use App\Infrastructure\Persistence\Eloquent\Governance\SignatureRequest;
use App\Infrastructure\Persistence\Eloquent\Identity\User;
use Illuminate\Support\Facades\DB;

final class DeclineSignatureRequest
{
    public function __construct(
        private readonly GovernanceActorContext $actorContext,
        private readonly RecordGovernanceOccurrence $occurrence,
    ) {}

    public function execute(User $user, Business $business, string $requestId): ?SignatureRequest
    {
        $membership = $this->actorContext->membership(
            $user,
            $business,
            CapabilityCatalog::GOVERNANCE_SIGNATURE_ACT,
        );

        if ($membership === null) {
            return null;
        }

        return DB::transaction(function () use ($user, $business, $requestId, $membership): ?SignatureRequest {
            $request = SignatureRequest::query()
                ->where('business_id', $business->getKey())
                ->whereKey($requestId)
                ->lockForUpdate()
                ->first();

            if (
                $request === null
                || ! in_array(
                    $request->status,
                    [SignatureRequestStatus::Sent, SignatureRequestStatus::PartiallySigned],
                    true,
                )
            ) {
                return null;
            }

            if (! $this->actorContext->canAccessResource(
                $user,
                $business,
                CapabilityCatalog::GOVERNANCE_SIGNATURE_ACT,
                SignatureRequest::class,
                (string) $request->getKey(),
            )) {
                return null;
            }

            $participant = SignatureParticipant::query()
                ->where('business_id', $business->getKey())
                ->where('signature_request_id', $request->getKey())
                ->where('membership_id', $membership->getKey())
                ->first();

            if ($participant === null) {
                return null;
            }

            $request->fill([
                'status' => SignatureRequestStatus::Declined->value,
                'declined_at' => now(),
                'declined_by_membership_id' => $membership->getKey(),
            ])->save();

            $this->occurrence->record(
                $user,
                $business,
                'governance.signature_request.declined',
                'signature_request',
                (string) $request->getKey(),
            );

            return $request->fresh();
        });
    }
}
