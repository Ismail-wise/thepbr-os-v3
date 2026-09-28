<?php

declare(strict_types=1);

namespace App\Infrastructure\Persistence\Eloquent\Import;

use App\Domain\Import\Enums\ImportBatchStatus;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

final class ImportBatch extends Model
{
    use HasUuids;

    protected $table = 'import_batches';

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'status' => ImportBatchStatus::class,
            'staged_at' => 'immutable_datetime',
            'parsed_at' => 'immutable_datetime',
            'validated_at' => 'immutable_datetime',
            'review_ready_at' => 'immutable_datetime',
            'completed_at' => 'immutable_datetime',
        ];
    }
}
