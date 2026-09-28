<?php

declare(strict_types=1);

namespace App\Infrastructure\Persistence\Eloquent\Exit;

use App\Domain\Exit\Enums\ExitCaseStatus;
use App\Domain\Exit\Enums\ExitTrigger;
use App\Domain\Exit\Enums\LeaverClassification;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

final class ExitCase extends Model
{
    use HasUuids;

    protected $table = 'exit_cases';

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'trigger' => ExitTrigger::class,
            'leaver_classification' => LeaverClassification::class,
            'status' => ExitCaseStatus::class,
            'notice_date' => 'date',
            'intended_exit_date' => 'date',
            'first_payment_date' => 'date',
            'final_payment_date' => 'date',
            'effective_from' => 'immutable_datetime',
            'settled_at' => 'immutable_datetime',
            'completed_at' => 'immutable_datetime',
            'required_notice_days' => 'integer',
            'approved_business_value_minor_units' => 'integer',
            'leaver_adjustment_minor_units' => 'integer',
            'final_buyout_value_minor_units' => 'integer',
            'payment_total_minor_units' => 'integer',
            'deposit_minor_units' => 'integer',
            'installment_minor_units' => 'integer',
            'installment_count' => 'integer',
            'revision' => 'integer',
        ];
    }
}
