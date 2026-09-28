<?php

declare(strict_types=1);

namespace App\Infrastructure\Persistence\Eloquent\Portability;

use App\Domain\Businesses\Enums\WorkspaceStatus;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

final class BusinessArchiveTransition extends Model
{
    use HasUuids;

    public const UPDATED_AT = null;

    protected $table = 'business_archive_transitions';

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'from_status' => WorkspaceStatus::class,
            'to_status' => WorkspaceStatus::class,
            'occurred_at' => 'immutable_datetime',
        ];
    }
}
