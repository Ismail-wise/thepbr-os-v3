<?php

declare(strict_types=1);

namespace App\Infrastructure\Persistence\Eloquent\Conflict;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

final class ConflictSettlementVersion extends Model
{
    use HasUuids;

    public $timestamps = false;

    protected $table = 'conflict_settlement_versions';

    protected $guarded = [];
}
