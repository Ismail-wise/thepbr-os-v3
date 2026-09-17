<?php

declare(strict_types=1);

namespace App\Infrastructure\Persistence\Eloquent\Records;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

final class Proposal extends Model
{
    use HasUuids;

    protected $table = 'proposals';

    protected $fillable = [
        'business_id',
        'revision',
        'content_hash',
        'created_by_user_id',
        'last_changed_by_user_id',
    ];

    protected function casts(): array
    {
        return [
            'revision' => 'integer',
        ];
    }
}
