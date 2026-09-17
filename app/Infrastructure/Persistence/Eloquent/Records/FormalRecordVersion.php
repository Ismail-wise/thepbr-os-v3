<?php

declare(strict_types=1);

namespace App\Infrastructure\Persistence\Eloquent\Records;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

final class FormalRecordVersion extends Model
{
    use HasUuids;

    protected $table = 'formal_record_versions';

    protected $fillable = [
        'business_id',
        'formal_record_family_id',
        'version_number',
        'predecessor_version_id',
        'revision',
        'change_summary',
        'created_by_user_id',
        'last_changed_by_user_id',
        'effective_from',
        'effective_until',
        'review_due_at',
        'content_hash',
        'frozen_at',
    ];

    protected function casts(): array
    {
        return [
            'version_number' => 'integer',
            'revision' => 'integer',
            'effective_from' => 'immutable_datetime',
            'effective_until' => 'immutable_datetime',
            'review_due_at' => 'immutable_datetime',
            'frozen_at' => 'immutable_datetime',
        ];
    }
}
