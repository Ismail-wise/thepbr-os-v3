<?php

declare(strict_types=1);

namespace App\Infrastructure\Persistence\Eloquent\Finance;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

final class FinanceException extends Model
{
    use HasUuids;

    protected $table = 'finance_exceptions';

    protected $guarded = [];
}
