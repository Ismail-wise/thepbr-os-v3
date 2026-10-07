<?php

declare(strict_types=1);

namespace App\Application\Partnership;

use App\Infrastructure\Persistence\Eloquent\Businesses\Business;
use Illuminate\Support\Facades\DB;

final class GetOwnershipActionPlanSource
{
    public function __construct(
        private readonly GetOwnershipDecisionRecordSource $source,
    ) {}

    /** @return array{source:array<string,mixed>,record:object}|null */
    public function execute(Business $business): ?array
    {
        $source = $this->source->execute($business);
        if ($source === null) {
            return null;
        }

        $record = DB::table('ownership_decision_records')
            ->where('business_id', $business->getKey())
            ->where('ownership_register_version_id', $source['register']->id)
            ->first();

        if ($record === null) {
            return null;
        }

        return [
            'source' => $source,
            'record' => $record,
        ];
    }
}
