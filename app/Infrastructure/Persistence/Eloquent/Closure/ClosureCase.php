<?php

declare(strict_types=1);

namespace App\Infrastructure\Persistence\Eloquent\Closure;

use App\Domain\Closure\Enums\ClosureCaseStatus;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

final class ClosureCase extends Model
{
    use HasUuids;

    protected $table = 'closure_cases';

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'status' => ClosureCaseStatus::class,
            'intended_legal_closure_at' => 'immutable_datetime',
            'legal_closed_at' => 'immutable_datetime',
            'workspace_closed_at' => 'immutable_datetime',
            'residual_distribution_minor_units' => 'integer',
            'revision' => 'integer',
        ];
    }
}
