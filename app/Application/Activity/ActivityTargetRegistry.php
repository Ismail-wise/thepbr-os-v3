<?php

declare(strict_types=1);

namespace App\Application\Activity;

use App\Infrastructure\Persistence\Eloquent\Businesses\Business;
use App\Infrastructure\Persistence\Eloquent\Records\FormalRecordVersion;
use App\Infrastructure\Persistence\Eloquent\Records\Proposal;
use Illuminate\Database\Eloquent\Model;

final class ActivityTargetRegistry
{
    /**
     * @return class-string<Model>|null
     */
    public function resourceClass(string $resourceType): ?string
    {
        return match ($resourceType) {
            'formal_record_version' => FormalRecordVersion::class,
            'proposal' => Proposal::class,
            default => null,
        };
    }

    public function find(
        Business $currentBusiness,
        string $resourceType,
        string $resourceId,
    ): ?Model {
        $class = $this->resourceClass($resourceType);

        if ($class === null) {
            return null;
        }

        return $class::query()
            ->where('business_id', $currentBusiness->getKey())
            ->whereKey($resourceId)
            ->first();
    }
}
