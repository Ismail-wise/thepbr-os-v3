<?php

declare(strict_types=1);

namespace App\Infrastructure\Persistence\Eloquent\Identity;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

final class AccountEntitlement extends Model
{
    use HasUuids;

    public const CREATED_AT = null;

    public const UPDATED_AT = null;

    protected $table = 'account_entitlements';

    protected $fillable = [
        'user_id',
        'entitlement_key',
        'pbr_access_code_id',
        'granted_at',
    ];

    protected function casts(): array
    {
        return [
            'granted_at' => 'immutable_datetime',
        ];
    }
}
