<?php

declare(strict_types=1);

namespace App\Infrastructure\Persistence\Eloquent\Governance;

use App\Domain\Governance\Enums\EmergencyAuthorityStatus;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

final class EmergencyAuthorityGrant extends Model
{
    use HasUuids;

    protected $table = 'emergency_authority_grants';

    protected $fillable = [
        'business_id',
        'grantee_membership_id',
        'decision_type',
        'scope',
        'reason',
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
            'status' => EmergencyAuthorityStatus::class,
            'effective_from' => 'immutable_datetime',
            'expires_at' => 'immutable_datetime',
            'revoked_at' => 'immutable_datetime',
        ];
    }
}
