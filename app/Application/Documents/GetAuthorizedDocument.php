<?php

declare(strict_types=1);

namespace App\Application\Documents;

use App\Domain\Documents\Enums\DocumentAccessRight;
use App\Infrastructure\Persistence\Eloquent\Businesses\Business;
use App\Infrastructure\Persistence\Eloquent\Documents\Document;
use App\Infrastructure\Persistence\Eloquent\Identity\User;

final class GetAuthorizedDocument
{
    public function __construct(
        private readonly AuthorizeDocumentAccess $authorization,
    ) {}

    public function execute(
        User $user,
        Business $currentBusiness,
        string $documentId,
        DocumentAccessRight $right = DocumentAccessRight::View,
        string $capability = 'records.view',
    ): ?Document {
        if (
            $this->authorization->activeMembershipWithCapability(
                $user,
                $currentBusiness,
                $capability,
            ) === null
        ) {
            return null;
        }

        $document = Document::query()
            ->where('business_id', $currentBusiness->getKey())
            ->whereKey($documentId)
            ->first();

        if ($document === null) {
            return null;
        }

        if (
            $this->authorization->allows(
                $user,
                $currentBusiness,
                $document,
                $right,
                $capability,
            ) === null
        ) {
            return null;
        }

        return $document;
    }
}
