<?php

declare(strict_types=1);

namespace App\Infrastructure\Persistence\Eloquent\Governance;

use App\Domain\Governance\Enums\DelegationStatus;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

final class GovernanceDelegation extends Model
{
    use HasUuids;

    protected $table = 'governance_delegations';

    protected $fillable = [
        'business_id',
        'delegator_membership_id',
        'delegate_membership_id',
        'decision_type',
        'scope',
        'status',
        'effective_from',
        'expires_at',
        'created_by_membership_id',
        'revoked_by_membership_id',
        'revoked_at',
    ];

    protected function casts(): array
    {
        return [
            'status' => DelegationStatus::class,
            'effective_from' => 'immutable_datetime',
            'expires_at' => 'immutable_datetime',
            'revoked_at' => 'immutable_datetime',
        ];
    }
}
