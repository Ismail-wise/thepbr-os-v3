<?php

declare(strict_types=1);

namespace App\Infrastructure\Persistence\Eloquent\Import;

use App\Domain\Import\Enums\ImportedRecordStatus;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

final class ImportedRecord extends Model
{
    use HasUuids;

    protected $table = 'imported_records';

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'observed_payload' => 'array',
            'normalized_payload' => 'array',
            'status' => ImportedRecordStatus::class,
            'confirmed_at' => 'immutable_datetime',
        ];
    }
}
