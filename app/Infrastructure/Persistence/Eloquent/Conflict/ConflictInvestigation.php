<?php

declare(strict_types=1);

namespace App\Infrastructure\Persistence\Eloquent\Conflict;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

final class ConflictInvestigation extends Model
{
    use HasUuids;

    public $timestamps = false;

    protected $table = 'conflict_investigations';

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'opened_at' => 'immutable_datetime',
            'completed_at' => 'immutable_datetime',
            'revision' => 'integer',
        ];
    }
}
