<?php

declare(strict_types=1);

namespace App\Infrastructure\Persistence\Eloquent\Continuity;

use App\Domain\Continuity\Enums\EmergencyAccessActivationStatus;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

final class ContinuityEmergencyAccessActivation extends Model
{
    use HasUuids;

    protected $table = 'continuity_emergency_access_activations';

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'status' => EmergencyAccessActivationStatus::class,
            'starts_at' => 'immutable_datetime',
            'expires_at' => 'immutable_datetime',
            'ended_at' => 'immutable_datetime',
            'revision' => 'integer',
        ];
    }
}
