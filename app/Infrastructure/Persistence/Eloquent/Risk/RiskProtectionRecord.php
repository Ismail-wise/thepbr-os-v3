<?php

declare(strict_types=1);

namespace App\Infrastructure\Persistence\Eloquent\Risk;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

final class RiskProtectionRecord extends Model
{
    use HasUuids;

    public $timestamps = false;

    protected $table = 'risk_protection_records';

    protected $guarded = [];
}
