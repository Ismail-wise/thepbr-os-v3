<?php

declare(strict_types=1);

namespace App\Infrastructure\Persistence\Eloquent\Records;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

final class FormalRecordFamily extends Model
{
    use HasUuids;

    protected $table = 'formal_record_families';

    protected $fillable = [
        'business_id',
        'record_type',
        'subject_type',
        'subject_id',
    ];
}
