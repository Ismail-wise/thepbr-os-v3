<?php

declare(strict_types=1);

namespace App\Infrastructure\Persistence\Eloquent\Partnership;

use Illuminate\Database\Eloquent\Model;

final class OwnershipRegisterVersion extends Model
{
    public $incrementing = false;

    protected $keyType = 'string';

    protected $guarded = [];
}
