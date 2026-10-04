<?php

declare(strict_types=1);

namespace App\Infrastructure\Persistence\Eloquent\Identity;

use App\Domain\Identity\Enums\PbrAccessCodeStatus;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

final class PbrAccessCode extends Model
{
    use HasUuids;

    public const UPDATED_AT = null;

    protected $table = 'pbr_access_codes';

    protected $fillable = [
        'token_fingerprint',
        'token_last4',
        'status',
        'bound_email',
        'client_reference',
        'batch_reference',
        'notes',
        'expires_at',
        'created_by_label',
        'creation_reason',
        'redeemed_by_user_id',
        'redeemed_at',
        'revoked_at',
        'revoked_by_label',
        'revocation_reason',
    ];

    protected $hidden = [
        'token_fingerprint',
    ];

    protected function casts(): array
    {
        return [
            'status' => PbrAccessCodeStatus::class,
            'expires_at' => 'immutable_datetime',
            'created_at' => 'immutable_datetime',
            'redeemed_at' => 'immutable_datetime',
            'revoked_at' => 'immutable_datetime',
        ];
    }
}
