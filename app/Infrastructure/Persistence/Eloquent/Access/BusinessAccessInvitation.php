<?php

declare(strict_types=1);

namespace App\Infrastructure\Persistence\Eloquent\Access;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

final class BusinessAccessInvitation extends Model
{
    use HasUuids;

    public const UPDATED_AT = null;

    protected $table = 'business_access_invitations';

    protected $fillable = [
        'business_id',
        'invited_email',
        'token_fingerprint',
        'token_last4',
        'permission_profile_id',
        'invited_by_membership_id',
        'status',
        'expires_at',
        'redeemed_by_user_id',
        'redeemed_membership_id',
        'redeemed_at',
        'revoked_at',
    ];

    protected $hidden = [
        'token_fingerprint',
    ];

    protected function casts(): array
    {
        return [
            'expires_at' => 'immutable_datetime',
            'redeemed_at' => 'immutable_datetime',
            'revoked_at' => 'immutable_datetime',
            'created_at' => 'immutable_datetime',
        ];
    }
}
