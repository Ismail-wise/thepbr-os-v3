<?php

declare(strict_types=1);

namespace App\Application\Rewards;

use App\Infrastructure\Persistence\Eloquent\Businesses\Business;
use Illuminate\Support\Facades\DB;

final class ResolveRewardPolicy
{
    /** @return array{formal_record_version_id:string,header:object,distribution_rule:object|null}|null */
    public function currentPolicy(Business $business): ?array
    {
        $head = DB::table('record_family_effective_heads as h')
            ->join('formal_record_versions as v', function ($join): void {
                $join->on('v.id', '=', 'h.formal_record_version_id')
                    ->on('v.business_id', '=', 'h.business_id');
            })
            ->join('formal_record_families as f', function ($join): void {
                $join->on('f.id', '=', 'v.formal_record_family_id')
                    ->on('f.business_id', '=', 'v.business_id');
            })
            ->where('h.business_id', $business->getKey())
            ->where('f.record_type', 'reward_policy')
            ->first(['v.id', 'v.version_number', 'v.effective_from', 'v.content_hash']);

        if ($head === null) {
            return null;
        }

        $header = DB::table('reward_policy_versions')
            ->where('business_id', $business->getKey())
            ->where('formal_record_version_id', $head->id)
            ->first();

        if ($header === null) {
            return null;
        }

        return [
            'formal_record_version_id' => (string) $head->id,
            'header' => $header,
            'distribution_rule' => DB::table('reward_distribution_rules')
                ->where('business_id', $business->getKey())
                ->where('formal_record_version_id', $head->id)
                ->first(),
        ];
    }
}
