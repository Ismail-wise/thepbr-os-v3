<?php

declare(strict_types=1);

namespace App\Infrastructure\Persistence\Eloquent\Records;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

final class RecordFamilyEffectiveHead extends Model
{
    use HasUuids;

    protected $table = 'record_family_effective_heads';

    protected $fillable = [
        'business_id',
        'formal_record_family_id',
        'formal_record_version_id',
        'activated_at',
    ];

    protected function casts(): array
    {
        return [
            'activated_at' => 'immutable_datetime',
        ];
    }
}
