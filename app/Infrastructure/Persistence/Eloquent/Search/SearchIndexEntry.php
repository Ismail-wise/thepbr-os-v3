<?php

declare(strict_types=1);

namespace App\Infrastructure\Persistence\Eloquent\Search;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

final class SearchIndexEntry extends Model
{
    use HasUuids;

    public const UPDATED_AT = null;

    protected $table = 'search_index_entries';

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'indexed_at' => 'immutable_datetime',
            'created_at' => 'immutable_datetime',
        ];
    }
}
