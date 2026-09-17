<?php

declare(strict_types=1);

namespace App\Infrastructure\Persistence\Eloquent\Records;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

final class RecordVersionSupersession extends Model
{
    use HasUuids;

    public $timestamps = false;

    protected $table = 'record_version_supersessions';

    protected $fillable = [
        'business_id',
        'formal_record_family_id',
        'superseded_version_id',
        'superseding_version_id',
        'superseded_at',
    ];

    protected function casts(): array
    {
        return [
            'superseded_at' => 'immutable_datetime',
            'created_at' => 'immutable_datetime',
        ];
    }
}
