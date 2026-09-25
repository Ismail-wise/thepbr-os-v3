<?php

declare(strict_types=1);

namespace App\Infrastructure\Persistence\Eloquent\Governance;

use App\Domain\Governance\Enums\NotificationStatus;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

final class GovernanceNotification extends Model
{
    use HasUuids;

    public const UPDATED_AT = null;

    protected $table = 'governance_notifications';

    protected $fillable = [
        'business_id',
        'recipient_membership_id',
        'kind',
        'subject_type',
        'subject_id',
        'status',
        'read_at',
    ];

    protected function casts(): array
    {
        return [
            'status' => NotificationStatus::class,
            'read_at' => 'immutable_datetime',
            'created_at' => 'immutable_datetime',
        ];
    }
}
