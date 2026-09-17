<?php

namespace App\Infrastructure\Persistence\Eloquent\Access;

use App\Domain\Access\Enums\PermissionEffect;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

final class PermissionGrant extends Model
{
    use HasUuids;

    protected $table = 'permission_grants';

    protected $fillable = [
        'business_id',
        'membership_id',
        'permission_id',
        'effect',
    ];

    protected function casts(): array
    {
        return [
            'effect' => PermissionEffect::class,
        ];
    }
}
