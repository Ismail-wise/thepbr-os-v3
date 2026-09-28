<?php

declare(strict_types=1);

namespace App\Infrastructure\Persistence\Eloquent\Portability;

use App\Domain\Portability\Enums\BusinessExportStatus;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

final class BusinessPortabilityExport extends Model
{
    use HasUuids;

    protected $table = 'business_portability_exports';

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'requested_categories' => 'array',
            'excluded_categories' => 'array',
            'frozen_manifest' => 'array',
            'status' => BusinessExportStatus::class,
            'requested_at' => 'immutable_datetime',
            'generated_at' => 'immutable_datetime',
            'verified_at' => 'immutable_datetime',
            'available_at' => 'immutable_datetime',
            'size_bytes' => 'integer',
        ];
    }
}
