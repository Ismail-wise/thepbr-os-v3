<?php

declare(strict_types=1);

namespace App\Infrastructure\Persistence\Eloquent\Conflict;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

final class ConflictPolicyVersion extends Model
{
    use HasUuids;

    public $timestamps = false;

    protected $table = 'conflict_policy_versions';

    protected $guarded = [];
}
