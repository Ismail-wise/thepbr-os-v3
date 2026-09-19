<?php

declare(strict_types=1);

namespace App\Application\Evidence;

use App\Application\Documents\AuthorizeDocumentAccess;
use App\Application\Events\RecordBusinessOccurrence;
use App\Domain\Audit\ValueObjects\AuditActor;
use App\Domain\Audit\ValueObjects\SafeAuditMetadata;
use App\Domain\Documents\Enums\DocumentAccessRight;
use App\Domain\Events\ValueObjects\OccurrenceTarget;
use App\Domain\Events\ValueObjects\SafeBusinessEventPayload;
use App\Infrastructure\Persistence\Eloquent\Businesses\Business;
use App\Infrastructure\Persistence\Eloquent\Documents\Document;
use App\Infrastructure\Persistence\Eloquent\Documents\DocumentVersion;
use App\Infrastructure\Persistence\Eloquent\Evidence\Evidence;
use App\Infrastructure\Persistence\Eloquent\Identity\User;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

final class VerifyEvidence
{
    public function __construct(
        private readonly AuthorizeDocumentAccess $authorization,
        private readonly RecordBusinessOccurrence $recordBusinessOccurrence,
    ) {}

    public function execute(
        User $user,
        Business $currentBusiness,
        string $evidenceId,
        string $verificationMethod,
        ?string $verificationNote = null,
    ): ?Evidence {
        if (trim($verificationMethod) === '') {
            throw new InvalidArgumentException(
                'Verification method is required.',
            );
        }

        if (
            $this->authorization->activeMembershipWithCapability(
                $user,
                $currentBusiness,
                'records.manage',
            ) === null
        ) {
            return null;
        }

        $businessId = (string) $currentBusiness->getKey();

        return DB::transaction(
            function () use (
                $user,
                $currentBusiness,
                $businessId,
                $evidenceId,
                $verificationMethod,
                $verificationNote,
            ): ?Evidence {
                $evidence = Evidence::query()
                    ->where('business_id', $businessId)
                    ->whereKey($evidenceId)
                    ->lockForUpdate()
                    ->first();

                if ($evidence === null) {
                    return null;
                }

                $version = DocumentVersion::query()
                    ->where('business_id', $businessId)
                    ->whereKey($evidence->document_version_id)
                    ->first();

                if ($version === null) {
                    return null;
                }

                $document = Document::query()
                    ->where('business_id', $businessId)
                    ->whereKey($version->document_id)
                    ->first();

                if ($document === null) {
                    return null;
                }

                $membership = $this->authorization->allows(
                    $user,
                    $currentBusiness,
                    $document,
                    DocumentAccessRight::Manage,
                    'records.manage',
                );

                if ($membership === null) {
                    return null;
                }

                if ($evidence->verified_at !== null) {
                    return null;
                }

                $evidence->forceFill([
                    'verified_at' => now(),
                    'verified_by_membership_id' => $membership->getKey(),
                    'verification_method' => $verificationMethod,
                    'verification_note' => $verificationNote,
                ]);

                $evidence->save();

                $this->appendAudit(
                    $user,
                    $currentBusiness,
                    'evidence.verified',
                    'evidence',
                    (string) $evidence->getKey(),
                    null,
                    [
                        'document_id' => (string) $document->getKey(),
                        'document_version_id' => (string) $version->getKey(),
                        'verification_method' => $verificationMethod,
                    ],
                );

                $this->appendBusinessEvent(
                    $user,
                    $currentBusiness,
                    'evidence.verified',
                    'evidence',
                    (string) $evidence->getKey(),
                    null,
                    'document',
                    (string) $document->getKey(),
                    [
                        'document_version_id' => (string) $version->getKey(),
                        'verification_method' => $verificationMethod,
                    ],
                );

                return $evidence->refresh();
            },
        );
    }

    /**
     * @param  array<string, mixed>  $metadata
     */
    private function appendAudit(
        User $user,
        Business $business,
        string $action,
        string $targetType,
        string $targetId,
        ?string $targetVersionId,
        array $metadata,
    ): void {
        $this->recordBusinessOccurrence->audit(
            $business,
            AuditActor::user((string) $user->getKey()),
            $action,
            new OccurrenceTarget(
                (string) $business->getKey(),
                $targetType,
                $targetId,
                $targetVersionId,
            ),
            SafeAuditMetadata::from($metadata),
            now(),
        );
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function appendBusinessEvent(
        User $user,
        Business $business,
        string $eventType,
        string $aggregateType,
        string $aggregateId,
        ?string $aggregateVersionId,
        string $visibilityResourceType,
        string $visibilityResourceId,
        array $payload,
    ): void {
        $this->recordBusinessOccurrence->businessEvent(
            $business,
            $eventType,
            new OccurrenceTarget(
                (string) $business->getKey(),
                $aggregateType,
                $aggregateId,
                $aggregateVersionId,
            ),
            new OccurrenceTarget(
                (string) $business->getKey(),
                $visibilityResourceType,
                $visibilityResourceId,
            ),
            SafeBusinessEventPayload::from($payload),
            now(),
            AuditActor::user((string) $user->getKey()),
        );
    }
}
