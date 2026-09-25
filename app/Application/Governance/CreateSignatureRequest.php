<?php

declare(strict_types=1);

namespace App\Application\Governance;

use App\Application\Documents\AuthorizeDocumentAccess;
use App\Domain\Access\CapabilityCatalog;
use App\Domain\Documents\Enums\DocumentAccessRight;
use App\Domain\Governance\Enums\DecisionOutcome;
use App\Domain\Governance\Enums\DecisionStatus;
use App\Domain\Governance\Enums\ParticipantStatus;
use App\Domain\Governance\Enums\SignatureRequestStatus;
use App\Infrastructure\Persistence\Eloquent\Businesses\Business;
use App\Infrastructure\Persistence\Eloquent\Documents\Document;
use App\Infrastructure\Persistence\Eloquent\Documents\DocumentVersion;
use App\Infrastructure\Persistence\Eloquent\Governance\AuthoritySnapshot;
use App\Infrastructure\Persistence\Eloquent\Governance\Decision;
use App\Infrastructure\Persistence\Eloquent\Governance\DecisionParticipant;
use App\Infrastructure\Persistence\Eloquent\Governance\SignatureParticipant;
use App\Infrastructure\Persistence\Eloquent\Governance\SignatureRequest;
use App\Infrastructure\Persistence\Eloquent\Identity\User;
use Illuminate\Support\Facades\DB;

final class CreateSignatureRequest
{
    public function __construct(
        private readonly GovernanceActorContext $actorContext,
        private readonly AuthorizeDocumentAccess $documentAccess,
        private readonly RecordGovernanceOccurrence $occurrence,
    ) {}

    public function execute(
        User $user,
        Business $business,
        string $decisionId,
        string $documentVersionId,
    ): ?SignatureRequest {
        $requester = $this->actorContext->membership(
            $user,
            $business,
            CapabilityCatalog::GOVERNANCE_RECORDS_MANAGE,
        );

        if ($requester === null) {
            return null;
        }

        return DB::transaction(function () use (
            $user,
            $business,
            $decisionId,
            $documentVersionId,
            $requester,
        ): ?SignatureRequest {
            $decision = Decision::query()
                ->where('business_id', $business->getKey())
                ->whereKey($decisionId)
                ->lockForUpdate()
                ->first();

            if (
                $decision === null
                || $decision->status !== DecisionStatus::Decided
                || $decision->outcome !== DecisionOutcome::Approved
            ) {
                return null;
            }

            if (! $this->actorContext->canAccessResource(
                $user,
                $business,
                CapabilityCatalog::GOVERNANCE_RECORDS_MANAGE,
                Decision::class,
                (string) $decision->getKey(),
            )) {
                return null;
            }

            $snapshot = AuthoritySnapshot::query()
                ->where('business_id', $business->getKey())
                ->whereKey($decision->authority_snapshot_id)
                ->first();

            if ($snapshot === null || ! $snapshot->signature_required) {
                return null;
            }

            $document = DocumentVersion::query()
                ->where('business_id', $business->getKey())
                ->whereKey($documentVersionId)
                ->first();

            if ($document === null) {
                return null;
            }

            $documentRecord = Document::query()
                ->where('business_id', $business->getKey())
                ->whereKey($document->document_id)
                ->first();

            if (
                $documentRecord === null
                || $this->documentAccess->allows(
                    $user,
                    $business,
                    $documentRecord,
                    DocumentAccessRight::Manage,
                    'records.manage',
                ) === null
            ) {
                return null;
            }

            $signers = DecisionParticipant::query()
                ->where('business_id', $business->getKey())
                ->where('decision_id', $decision->getKey())
                ->where('status', ParticipantStatus::Eligible->value)
                ->where('can_sign', true)
                ->orderBy('membership_id')
                ->get();

            if ($signers->isEmpty()) {
                return null;
            }

            $request = SignatureRequest::query()->create([
                'business_id' => $business->getKey(),
                'decision_id' => $decision->getKey(),
                'proposal_version_id' => $decision->proposal_version_id,
                'authority_snapshot_id' => $decision->authority_snapshot_id,
                'document_version_id' => $document->getKey(),
                'document_content_sha256' => $document->content_sha256,
                'status' => SignatureRequestStatus::Draft->value,
                'requested_by_membership_id' => $requester->getKey(),
                'requested_at' => now(),
                'sent_at' => null,
                'completed_at' => null,
                'cancelled_at' => null,
                'cancelled_by_membership_id' => null,
                'declined_at' => null,
                'declined_by_membership_id' => null,
                'expired_at' => null,
            ]);

            $sequence = 1;

            foreach ($signers as $signer) {
                SignatureParticipant::query()->create([
                    'business_id' => $business->getKey(),
                    'signature_request_id' => $request->getKey(),
                    'decision_participant_id' => $signer->getKey(),
                    'membership_id' => $signer->membership_id,
                    'sequence' => $sequence++,
                ]);
            }

            $this->occurrence->record(
                $user,
                $business,
                'governance.signature_request.created',
                'signature_request',
                (string) $request->getKey(),
                [
                    'decision_id' => (string) $decision->getKey(),
                    'signer_count' => $signers->count(),
                ],
                (string) $document->getKey(),
            );

            return $request->fresh();
        });
    }
}
