<?php

declare(strict_types=1);

namespace App\Application\Partnership;

use App\Infrastructure\Persistence\Eloquent\Businesses\Business;
use App\Infrastructure\Persistence\Eloquent\Identity\User;
use Illuminate\Support\Facades\DB;

final class GetContributionActionPlanSource
{
    public function __construct(
        private readonly GetAcceptedContributionRegister $register,
    ) {}

    /**
     * @return array{
     *   register:array<string,mixed>,
     *   record:object,
     *   anchor:object
     * }|null
     */
    public function execute(
        User $user,
        Business $business,
    ): ?array {
        $register = $this->register->execute(
            $user,
            $business,
        );

        if (
            $register === null
            || ($register['decisionReady'] ?? false)
                !== true
            || ! is_string(
                $register['registerHash']
                ?? null,
            )
        ) {
            return null;
        }

        $record = DB::table(
            'contribution_decision_records',
        )
            ->where(
                'business_id',
                $business->getKey(),
            )
            ->where(
                'accepted_register_hash',
                $register['registerHash'],
            )
            ->first();

        if ($record === null) {
            return null;
        }

        $anchor = DB::table(
            'contribution_decision_record_sources',
        )
            ->where(
                'business_id',
                $business->getKey(),
            )
            ->where(
                'contribution_decision_record_id',
                $record->id,
            )
            ->orderBy('accepted_at')
            ->orderBy('contribution_id')
            ->first();

        if ($anchor === null) {
            return null;
        }

        return [
            'register' => $register,
            'record' => $record,
            'anchor' => $anchor,
        ];
    }
}
