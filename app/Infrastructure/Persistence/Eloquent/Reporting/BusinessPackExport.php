<?php

declare(strict_types=1);

namespace App\Infrastructure\Persistence\Eloquent\Reporting;

use App\Domain\Reporting\Enums\BusinessPackStatus;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

final class BusinessPackExport extends Model
{
    use HasUuids;

    protected $table = 'business_pack_exports';

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'status' => BusinessPackStatus::class,
            'requested_scope' => 'array',
            'frozen_manifest' => 'array',
            'explicit_exclusions' => 'array',
            'as_of_at' => 'immutable_datetime',
            'generated_at' => 'immutable_datetime',
            'verified_at' => 'immutable_datetime',
            'available_at' => 'immutable_datetime',
            'size_bytes' => 'integer',
        ];
    }
}
