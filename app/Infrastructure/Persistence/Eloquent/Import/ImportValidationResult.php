<?php

declare(strict_types=1);

namespace App\Infrastructure\Persistence\Eloquent\Import;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

final class ImportValidationResult extends Model
{
    use HasUuids;

    public const UPDATED_AT = null;

    protected $table = 'import_validation_results';

    protected $guarded = [];
}
