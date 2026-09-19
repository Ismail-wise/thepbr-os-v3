<?php

declare(strict_types=1);

namespace App\Application\Evidence;

use App\Application\Documents\AuthorizeDocumentAccess;
use App\Application\Events\RecordBusinessOccurrence;
use App\Domain\Audit\ValueObjects\AuditActor;
use App\Domain\Audit\ValueObjects\SafeAuditMetadata;
use App\Domain\Documents\Enums\DocumentAccessRight;
use App\Domain\Events\ValueObjects\OccurrenceTarget;
use App\Infrastructure\Persistence\Eloquent\Businesses\Business;
use App\Infrastructure\Persistence\Eloquent\Documents\Document;
use App\Infrastructure\Persistence\Eloquent\Documents\DocumentVersion;
use App\Infrastructure\Persistence\Eloquent\Evidence\Evidence;
use App\Infrastructure\Persistence\Eloquent\Evidence\EvidenceLink;
use App\Infrastructure\Persistence\Eloquent\Identity\User;
use Illuminate\Support\Facades\DB;

final class LinkEvidence
{
    public function __construct(
        private readonly AuthorizeDocumentAccess $authorization,
        private readonly EvidenceTargetRegistry $targets,
        private readonly RecordBusinessOccurrence $recordBusinessOccurrence,
    ) {}

    public function execute(
        User $user,
        Business $currentBusiness,
        string $evidenceId,
        string $targetType,
        string $targetId,
    ): ?EvidenceLink {
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

        $evidence = Evidence::query()
            ->where('business_id', $businessId)
            ->whereKey($evidenceId)
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

        if (
            $this->targets->resolve(
                $targetType,
                $targetId,
                $businessId,
            ) === null
        ) {
            return null;
        }

        return DB::transaction(
            function () use (
                $user,
                $currentBusiness,
                $businessId,
                $membership,
                $evidence,
                $document,
                $targetType,
                $targetId,
            ): EvidenceLink {
                $link = EvidenceLink::query()->create([
                    'business_id' => $businessId,
                    'evidence_id' => $evidence->getKey(),
                    'target_type' => $targetType,
                    'target_id' => $targetId,
                    'created_by_membership_id' => $membership->getKey(),
                ]);

                $this->appendAudit(
                    $user,
                    $currentBusiness,
                    'evidence.linked',
                    'evidence',
                    (string) $evidence->getKey(),
                    null,
                    [
                        'document_id' => (string) $document->getKey(),
                        'target_type' => $targetType,
                        'target_id' => $targetId,
                    ],
                );

                return $link;
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
}
