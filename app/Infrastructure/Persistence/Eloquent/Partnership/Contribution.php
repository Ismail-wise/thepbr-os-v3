<?php

declare(strict_types=1);

namespace App\Infrastructure\Persistence\Eloquent\Partnership;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

final class Contribution extends Model
{
    use HasUuids;

    protected $table = 'contributions';

    protected $guarded = [];
}
