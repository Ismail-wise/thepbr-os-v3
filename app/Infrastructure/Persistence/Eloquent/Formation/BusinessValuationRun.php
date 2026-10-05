<?php

declare(strict_types=1);

namespace App\Infrastructure\Persistence\Eloquent\Formation;

use Illuminate\Database\Eloquent\Model;

final class BusinessValuationRun extends Model
{
    protected $table = 'business_valuation_runs';

    protected $guarded = [];

    public $incrementing = false;

    public $timestamps = false;

    protected $keyType = 'string';

    protected function casts(): array
    {
        return [
            'as_of_date' => 'date',
            'historical_inputs' => 'array',
            'assumptions' => 'array',
            'source_provenance' => 'array',
            'method_results' => 'array',
            'warnings' => 'array',
            'semantics' => 'array',
            'created_at' => 'immutable_datetime',
        ];
    }
}
