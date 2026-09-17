<?php

declare(strict_types=1);

namespace App\Application\Records;

use App\Application\Access\AuthorizeBusinessCapability;
use App\Domain\Access\ValueObjects\Capability;
use App\Domain\Records\ValueObjects\RecordScope;
use App\Infrastructure\Persistence\Eloquent\Businesses\Business;
use App\Infrastructure\Persistence\Eloquent\Identity\User;
use App\Infrastructure\Persistence\Eloquent\Records\FormalRecordFamily;

final class CreateFormalRecordFamily
{
    public function __construct(
        private readonly AuthorizeBusinessCapability $authorizeBusinessCapability,
    ) {}

    public function execute(
        User $user,
        Business $currentBusiness,
        Capability $capability,
        RecordScope $scope,
    ): ?FormalRecordFamily {
        $decision = $this->authorizeBusinessCapability->decide(
            $user,
            $currentBusiness,
            $currentBusiness,
            $capability,
        );

        if (! $decision->allowed) {
            return null;
        }

        return FormalRecordFamily::query()->create([
            'business_id' => $currentBusiness->getKey(),
            'record_type' => $scope->recordType,
            'subject_type' => $scope->subjectType,
            'subject_id' => $scope->subjectId,
        ]);
    }
}
