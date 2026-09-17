<?php

namespace App\Infrastructure\Persistence\Eloquent\Access;

use App\Domain\Access\Enums\PermissionEffect;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

final class RecordAccessRule extends Model
{
    use HasUuids;

    protected $table = 'record_access_rules';

    protected $fillable = [
        'business_id',
        'membership_id',
        'permission_profile_id',
        'permission_id',
        'resource_type',
        'resource_id',
        'effect',
    ];

    protected function casts(): array
    {
        return [
            'effect' => PermissionEffect::class,
        ];
    }
}
