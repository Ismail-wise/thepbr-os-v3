<?php

declare(strict_types=1);

namespace App\Infrastructure\Persistence\Eloquent\Audit;

use App\Domain\Audit\Enums\AuditActorType;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

final class AuditEvent extends Model
{
    use HasUuids;

    public $timestamps = false;

    protected $table = 'audit_events';

    protected $fillable = [
        'business_id',
        'actor_type',
        'actor_identifier',
        'action',
        'target_type',
        'target_id',
        'target_version_id',
        'occurred_at',
        'correlation_id',
        'metadata',
    ];

    protected function casts(): array
    {
        return [
            'actor_type' => AuditActorType::class,
            'occurred_at' => 'immutable_datetime',
            'metadata' => 'array',
            'created_at' => 'immutable_datetime',
        ];
    }
}
