<?php

declare(strict_types=1);

namespace App\Infrastructure\Persistence\Eloquent\Conflict;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

final class ConflictUrgentRisk extends Model
{
    use HasUuids;

    public $timestamps = false;

    protected $table = 'conflict_urgent_risk_records';

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'review_deadline' => 'immutable_datetime',
            'authority_starts_at' => 'immutable_datetime',
            'authority_expires_at' => 'immutable_datetime',
            'completed_at' => 'immutable_datetime',
            'revision' => 'integer',
        ];
    }
}
