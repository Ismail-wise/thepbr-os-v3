<?php

declare(strict_types=1);

namespace App\Infrastructure\Persistence\Eloquent\Records;

use App\Domain\Records\Enums\FormalRecordState;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

final class RecordVersionStateTransition extends Model
{
    use HasUuids;

    public $timestamps = false;

    protected $table = 'record_version_state_transitions';

    protected $fillable = [
        'business_id',
        'formal_record_version_id',
        'sequence',
        'from_state',
        'to_state',
        'transitioned_by_user_id',
        'occurred_at',
    ];

    protected function casts(): array
    {
        return [
            'sequence' => 'integer',
            'from_state' => FormalRecordState::class,
            'to_state' => FormalRecordState::class,
            'occurred_at' => 'immutable_datetime',
            'created_at' => 'immutable_datetime',
        ];
    }
}
