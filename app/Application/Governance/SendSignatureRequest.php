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

final class SendSignatureRequest
{
    public function __construct(
        private readonly GovernanceActorContext $actorContext,
        private readonly CreateGovernanceNotification $notifications,
        private readonly RecordGovernanceOccurrence $occurrence,
    ) {}

    public function execute(
        User $user,
        Business $business,
        string $requestId,
    ): ?SignatureRequest {
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

            if ($request === null || $request->status !== SignatureRequestStatus::Draft) {
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

            $participants = SignatureParticipant::query()
                ->where('business_id', $business->getKey())
                ->where('signature_request_id', $request->getKey())
                ->orderBy('sequence')
                ->get();

            if ($participants->isEmpty()) {
                return null;
            }

            $request->fill([
                'status' => SignatureRequestStatus::Sent->value,
                'sent_at' => now(),
            ])->save();

            foreach ($participants as $participant) {
                $this->notifications->execute(
                    $business,
                    (string) $participant->membership_id,
                    'governance.signature.requested',
                    'signature_request',
                    (string) $request->getKey(),
                );
            }

            $this->occurrence->record(
                $user,
                $business,
                'governance.signature_request.sent',
                'signature_request',
                (string) $request->getKey(),
                ['participant_count' => $participants->count()],
                (string) $request->document_version_id,
            );

            return $request->fresh();
        });
    }
}
