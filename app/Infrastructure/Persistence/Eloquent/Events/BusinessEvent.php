<?php

declare(strict_types=1);

namespace App\Infrastructure\Persistence\Eloquent\Events;

use App\Domain\Audit\Enums\AuditActorType;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

final class BusinessEvent extends Model
{
    use HasUuids;

    public $timestamps = false;

    protected $table = 'business_events';

    protected $fillable = [
        'business_id',
        'event_type',
        'aggregate_type',
        'aggregate_id',
        'aggregate_version_id',
        'actor_type',
        'actor_identifier',
        'visibility_resource_type',
        'visibility_resource_id',
        'occurred_at',
        'correlation_id',
        'payload',
    ];

    protected function casts(): array
    {
        return [
            'actor_type' => AuditActorType::class,
            'occurred_at' => 'immutable_datetime',
            'payload' => 'array',
            'created_at' => 'immutable_datetime',
        ];
    }
}
