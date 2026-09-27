<?php

declare(strict_types=1);

namespace App\Infrastructure\Persistence\Eloquent\Conflict;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

final class ConflictMediation extends Model
{
    use HasUuids;

    protected $table = 'conflict_mediations';

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'mediation_at' => 'immutable_datetime',
            'response_deadline' => 'immutable_datetime',
            'completed_at' => 'immutable_datetime',
            'revision' => 'integer',
        ];
    }
}
