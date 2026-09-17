<?php

namespace App\Infrastructure\Persistence\Eloquent\Access;

use App\Domain\Access\Enums\PermissionEffect;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

final class AccessPolicy extends Model
{
    use HasUuids;

    protected $table = 'access_policies';

    protected $fillable = [
        'business_id',
        'membership_id',
        'permission_profile_id',
        'permission_id',
        'resource_type',
        'effect',
    ];

    protected function casts(): array
    {
        return [
            'effect' => PermissionEffect::class,
        ];
    }
}
