<?php

declare(strict_types=1);

namespace App\Infrastructure\Persistence\Eloquent\Finance;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

final class FinanceReconciliationReview extends Model
{
    use HasUuids;

    protected $table = 'finance_reconciliation_reviews';

    protected $guarded = [];
}
