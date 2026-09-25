<?php

declare(strict_types=1);

namespace App\Infrastructure\Persistence\Eloquent\Governance;

use App\Domain\Governance\Enums\ActionStatus;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

final class Action extends Model
{
    use HasUuids;

    protected $table = 'actions';

    protected $fillable = [
        'business_id',
        'decision_id',
        'formal_record_version_id',
        'assigned_membership_id',
        'created_by_membership_id',
        'title',
        'description',
        'status',
        'blocked_reason',
        'due_at',
        'completed_at',
    ];

    protected function casts(): array
    {
        return [
            'status' => ActionStatus::class,
            'due_at' => 'immutable_datetime',
            'completed_at' => 'immutable_datetime',
        ];
    }
}
