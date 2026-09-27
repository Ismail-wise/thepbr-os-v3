<?php

declare(strict_types=1);

namespace App\Infrastructure\Persistence\Eloquent\Risk;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

final class RiskItem extends Model
{
    use HasUuids;

    public $timestamps = false;

    protected $table = 'risk_items';

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'likelihood' => 'integer',
            'impact' => 'integer',
            'risk_score' => 'integer',
            'review_date' => 'immutable_date',
        ];
    }
}
