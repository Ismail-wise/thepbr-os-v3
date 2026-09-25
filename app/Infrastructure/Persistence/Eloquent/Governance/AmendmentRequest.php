<?php

declare(strict_types=1);

namespace App\Infrastructure\Persistence\Eloquent\Governance;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

final class AmendmentRequest extends Model
{
    use HasUuids;

    protected $table = 'amendment_requests';

    protected $fillable = [
        'business_id',
        'formal_record_version_id',
        'review_id',
        'requested_by_membership_id',
        'reason',
        'status',
        'resolved_by_membership_id',
        'resolved_at',
    ];

    protected function casts(): array
    {
        return [
            'resolved_at' => 'immutable_datetime',
        ];
    }
}
