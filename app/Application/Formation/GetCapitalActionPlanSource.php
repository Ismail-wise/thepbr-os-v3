<?php

declare(strict_types=1);

namespace App\Application\Formation;

use App\Infrastructure\Persistence\Eloquent\Businesses\Business;
use Illuminate\Support\Facades\DB;

final class GetCapitalActionPlanSource
{
    public function __construct(
        private readonly GetApprovedCapitalDecisionSource $approvedSource,
    ) {}

    /**
     * @return array{record:object,approved:array<string,mixed>}|null
     */
    public function execute(Business $business): ?array
    {
        $approved = $this->approvedSource->execute($business);

        if ($approved === null) {
            return null;
        }

        $record = DB::table('capital_decision_records')
            ->where('business_id', $business->getKey())
            ->where(
                'capital_approval_snapshot_id',
                $approved['snapshotId'],
            )
            ->where(
                'formal_record_version_id',
                $approved['formalRecordVersionId'],
            )
            ->where(
                'proposal_version_id',
                $approved['proposalVersionId'],
            )
            ->where(
                'governance_decision_id',
                $approved['governanceDecisionId'],
            )
            ->where(
                'approved_content_hash',
                $approved['contentHash'],
            )
            ->first();

        if ($record === null) {
            return null;
        }

        return [
            'record' => $record,
            'approved' => $approved,
        ];
    }
}
