<?php

declare(strict_types=1);

namespace App\Application\Documents;

use App\Domain\Documents\Enums\DocumentAccessRight;
use App\Infrastructure\Persistence\Eloquent\Businesses\Business;
use App\Infrastructure\Persistence\Eloquent\Documents\Document;
use App\Infrastructure\Persistence\Eloquent\Documents\DocumentAccessGrant;
use App\Infrastructure\Persistence\Eloquent\Identity\User;
use Illuminate\Support\Collection;

final class ListAuthorizedDocuments
{
    public function __construct(
        private readonly AuthorizeDocumentAccess $authorization,
    ) {}

    /**
     * @return Collection<int, Document>|null
     */
    public function execute(
        User $user,
        Business $currentBusiness,
    ): ?Collection {
        $membership = $this->authorization
            ->activeMembershipWithCapability(
                $user,
                $currentBusiness,
                'records.view',
            );

        if ($membership === null) {
            return null;
        }

        $businessId = (string) $currentBusiness->getKey();
        $membershipId = (string) $membership->getKey();

        return Document::query()
            ->where('documents.business_id', $businessId)
            ->whereExists(
                function ($query) use (
                    $businessId,
                    $membershipId,
                ): void {
                    $query
                        ->selectRaw('1')
                        ->from(
                            (new DocumentAccessGrant)->getTable()
                            .' as allowed_document_grants',
                        )
                        ->whereColumn(
                            'allowed_document_grants.document_id',
                            'documents.id',
                        )
                        ->where(
                            'allowed_document_grants.business_id',
                            $businessId,
                        )
                        ->where(
                            'allowed_document_grants.membership_id',
                            $membershipId,
                        )
                        ->where(
                            'allowed_document_grants.right',
                            DocumentAccessRight::View->value,
                        )
                        ->where(
                            'allowed_document_grants.effect',
                            'allow',
                        );
                },
            )
            ->whereNotExists(
                function ($query) use (
                    $businessId,
                    $membershipId,
                ): void {
                    $query
                        ->selectRaw('1')
                        ->from(
                            (new DocumentAccessGrant)->getTable()
                            .' as denied_document_grants',
                        )
                        ->whereColumn(
                            'denied_document_grants.document_id',
                            'documents.id',
                        )
                        ->where(
                            'denied_document_grants.business_id',
                            $businessId,
                        )
                        ->where(
                            'denied_document_grants.membership_id',
                            $membershipId,
                        )
                        ->where(
                            'denied_document_grants.right',
                            DocumentAccessRight::View->value,
                        )
                        ->where(
                            'denied_document_grants.effect',
                            'deny',
                        );
                },
            )
            ->orderByDesc('documents.created_at')
            ->get();
    }
}
