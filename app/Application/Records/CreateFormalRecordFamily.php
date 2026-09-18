<?php

declare(strict_types=1);

namespace App\Application\Records;

use App\Application\Access\AuthorizeBusinessCapability;
use App\Application\Events\RecordBusinessOccurrence;
use App\Domain\Access\ValueObjects\Capability;
use App\Domain\Audit\ValueObjects\AuditActor;
use App\Domain\Audit\ValueObjects\SafeAuditMetadata;
use App\Domain\Events\ValueObjects\OccurrenceTarget;
use App\Domain\Records\ValueObjects\RecordScope;
use App\Infrastructure\Persistence\Eloquent\Businesses\Business;
use App\Infrastructure\Persistence\Eloquent\Identity\User;
use App\Infrastructure\Persistence\Eloquent\Records\FormalRecordFamily;
use Illuminate\Support\Facades\DB;

final class CreateFormalRecordFamily
{
    public function __construct(
        private readonly AuthorizeBusinessCapability $authorizeBusinessCapability,
        private readonly RecordBusinessOccurrence $recordBusinessOccurrence,
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

        return DB::transaction(function () use (
            $user,
            $currentBusiness,
            $scope,
        ): FormalRecordFamily {
            $family = FormalRecordFamily::query()->create([
                'business_id' => $currentBusiness->getKey(),
                'record_type' => $scope->recordType,
                'subject_type' => $scope->subjectType,
                'subject_id' => $scope->subjectId,
            ]);

            $this->recordBusinessOccurrence->audit(
                $currentBusiness,
                AuditActor::user((string) $user->getKey()),
                'records.formal_record_family.created',
                new OccurrenceTarget(
                    (string) $currentBusiness->getKey(),
                    'formal_record_family',
                    (string) $family->getKey(),
                ),
                SafeAuditMetadata::from([
                    'record_type' => $scope->recordType,
                ]),
                now(),
            );

            return $family->fresh();
        });
    }
}
