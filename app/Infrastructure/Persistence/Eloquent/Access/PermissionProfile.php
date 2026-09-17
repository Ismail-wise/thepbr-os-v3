<?php

namespace App\Infrastructure\Persistence\Eloquent\Access;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

final class PermissionProfile extends Model
{
    use HasUuids;

    protected $table = 'permission_profiles';

    protected $fillable = [
        'business_id',
        'name',
    ];
}
