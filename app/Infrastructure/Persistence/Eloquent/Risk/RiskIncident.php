<?php

declare(strict_types=1);

namespace App\Infrastructure\Persistence\Eloquent\Risk;

use App\Domain\Risk\Enums\IncidentStatus;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

final class RiskIncident extends Model
{
    use HasUuids;

    protected $table = 'risk_incidents';

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'status' => IncidentStatus::class,
            'incident_at' => 'immutable_datetime',
            'revision' => 'integer',
        ];
    }
}
