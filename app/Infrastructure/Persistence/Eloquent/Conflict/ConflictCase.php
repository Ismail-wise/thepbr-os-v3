<?php

declare(strict_types=1);

namespace App\Infrastructure\Persistence\Eloquent\Conflict;

use App\Domain\Conflict\Enums\ConflictCaseStage;
use App\Domain\Conflict\Enums\ConflictCaseStatus;
use App\Domain\Conflict\Enums\ConflictType;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

final class ConflictCase extends Model
{
    use HasUuids;

    protected $table = 'conflict_cases';

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'conflict_type' => ConflictType::class,
            'stage' => ConflictCaseStage::class,
            'status' => ConflictCaseStatus::class,
            'raised_at' => 'immutable_datetime',
            'review_due_at' => 'immutable_datetime',
            'resolved_at' => 'immutable_datetime',
            'revision' => 'integer',
        ];
    }
}
