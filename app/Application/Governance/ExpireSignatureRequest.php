<?php

declare(strict_types=1);

namespace App\Application\Governance;

use App\Domain\Access\CapabilityCatalog;
use App\Domain\Governance\Enums\SignatureRequestStatus;
use App\Infrastructure\Persistence\Eloquent\Businesses\Business;
use App\Infrastructure\Persistence\Eloquent\Governance\SignatureRequest;
use App\Infrastructure\Persistence\Eloquent\Identity\User;
use Illuminate\Support\Facades\DB;

final class ExpireSignatureRequest
{
    public function __construct(
        private readonly GovernanceActorContext $actorContext,
        private readonly RecordGovernanceOccurrence $occurrence,
    ) {}

    public function execute(User $user, Business $business, string $requestId): ?SignatureRequest
    {
        if ($this->actorContext->membership(
            $user,
            $business,
            CapabilityCatalog::GOVERNANCE_RECORDS_MANAGE,
        ) === null) {
            return null;
        }

        return DB::transaction(function () use ($user, $business, $requestId): ?SignatureRequest {
            $request = SignatureRequest::query()
                ->where('business_id', $business->getKey())
                ->whereKey($requestId)
                ->lockForUpdate()
                ->first();

            if (
                $request === null
                || ! in_array(
                    $request->status,
                    [
                        SignatureRequestStatus::Draft,
                        SignatureRequestStatus::Sent,
                        SignatureRequestStatus::PartiallySigned,
                    ],
                    true,
                )
            ) {
                return null;
            }

            if (! $this->actorContext->canAccessResource(
                $user,
                $business,
                CapabilityCatalog::GOVERNANCE_RECORDS_MANAGE,
                SignatureRequest::class,
                (string) $request->getKey(),
            )) {
                return null;
            }

            $request->fill([
                'status' => SignatureRequestStatus::Expired->value,
                'expired_at' => now(),
            ])->save();

            $this->occurrence->record(
                $user,
                $business,
                'governance.signature_request.expired',
                'signature_request',
                (string) $request->getKey(),
            );

            return $request->fresh();
        });
    }
}
