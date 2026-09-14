<?php

namespace App\Infrastructure\Persistence\Eloquent\Identity;

use App\Domain\Identity\Enums\SecurityEventType;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

final class SecurityEvent extends Model
{
    use HasUuids;

    protected $table = 'security_events';

    public $timestamps = false;

    protected $fillable = [
        'event_type',
        'subject_user_id',
        'actor_label',
        'source',
        'reason',
        'metadata',
        'occurred_at',
    ];

    protected function casts(): array
    {
        return [
            'event_type' => SecurityEventType::class,
            'metadata' => 'array',
            'occurred_at' => 'immutable_datetime',
        ];
    }
}
