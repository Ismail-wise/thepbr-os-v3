<?php

declare(strict_types=1);

namespace App\Application\Governance;

use App\Domain\Access\CapabilityCatalog;
use App\Domain\Governance\Enums\SignatureRequestStatus;
use App\Infrastructure\Persistence\Eloquent\Businesses\Business;
use App\Infrastructure\Persistence\Eloquent\Governance\Signature;
use App\Infrastructure\Persistence\Eloquent\Governance\SignatureParticipant;
use App\Infrastructure\Persistence\Eloquent\Governance\SignatureRequest;
use App\Infrastructure\Persistence\Eloquent\Identity\User;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

final class SignGovernanceDocument
{
    public function __construct(
        private readonly GovernanceActorContext $actorContext,
        private readonly RecordGovernanceOccurrence $occurrence,
    ) {}

    public function execute(
        User $user,
        Business $business,
        string $requestId,
        string $signatureMethod,
        string $consentStatement,
        string $signingSessionHash,
    ): ?Signature {
        $membership = $this->actorContext->membership(
            $user,
            $business,
            CapabilityCatalog::GOVERNANCE_SIGNATURE_ACT,
        );

        if ($membership === null) {
            return null;
        }

        $signatureMethod = trim($signatureMethod);
        $consentStatement = trim($consentStatement);

        if (
            $signatureMethod === ''
            || $consentStatement === ''
            || preg_match('/\A[0-9a-f]{64}\z/', $signingSessionHash) !== 1
        ) {
            throw new InvalidArgumentException(
                'Signature method, consent and signing session hash are required.',
            );
        }

        return DB::transaction(function () use (
            $user,
            $business,
            $requestId,
            $signatureMethod,
            $consentStatement,
            $signingSessionHash,
            $membership,
        ): ?Signature {
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
                ->lockForUpdate()
                ->first();

            if ($participant === null) {
                return null;
            }

            if (Signature::query()
                ->where('business_id', $business->getKey())
                ->where('signature_request_id', $request->getKey())
                ->where('signature_participant_id', $participant->getKey())
                ->exists()) {
                return null;
            }

            $signature = Signature::query()->create([
                'business_id' => $business->getKey(),
                'signature_request_id' => $request->getKey(),
                'signature_participant_id' => $participant->getKey(),
                'membership_id' => $membership->getKey(),
                'document_version_id' => $request->document_version_id,
                'document_content_sha256' => $request->document_content_sha256,
                'signature_method' => $signatureMethod,
                'consent_statement' => $consentStatement,
                'signing_session_hash' => $signingSessionHash,
                'signed_at' => now(),
            ]);

            $participantCount = SignatureParticipant::query()
                ->where('business_id', $business->getKey())
                ->where('signature_request_id', $request->getKey())
                ->count();

            $signatureCount = Signature::query()
                ->where('business_id', $business->getKey())
                ->where('signature_request_id', $request->getKey())
                ->count();

            $request->fill([
                'status' => $signatureCount === $participantCount
                    ? SignatureRequestStatus::FullySigned->value
                    : SignatureRequestStatus::PartiallySigned->value,
            ])->save();

            $this->occurrence->record(
                $user,
                $business,
                'governance.signature.recorded',
                'signature',
                (string) $signature->getKey(),
                [
                    'signature_request_id' => (string) $request->getKey(),
                    'signed_count' => $signatureCount,
                    'participant_count' => $participantCount,
                ],
                (string) $request->document_version_id,
            );

            return $signature;
        });
    }
}
