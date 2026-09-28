<?php

declare(strict_types=1);

namespace App\Infrastructure\Persistence\Eloquent\PartnerChanges;

use App\Domain\PartnerChanges\Enums\PartnerChangeStatus;
use App\Domain\PartnerChanges\Enums\PartnerChangeTransactionType;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

final class PartnerChangeCase extends Model
{
    use HasUuids;

    protected $table = 'partner_change_cases';

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'transaction_type' => PartnerChangeTransactionType::class,
            'status' => PartnerChangeStatus::class,
            'rofr_required' => 'boolean',
            'effective_from' => 'immutable_datetime',
            'revision' => 'integer',
        ];
    }
}
