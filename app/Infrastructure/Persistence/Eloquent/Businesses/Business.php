<?php

namespace App\Infrastructure\Persistence\Eloquent\Businesses;

use App\Domain\Businesses\Enums\BusinessOriginType;
use App\Domain\Businesses\Enums\BusinessStage;
use App\Domain\Businesses\Enums\SetupPhase;
use App\Domain\Businesses\Enums\WorkspaceStatus;
use App\Infrastructure\Persistence\Eloquent\Members\Membership;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

final class Business extends Model
{
    use HasUuids;

    protected $table = 'businesses';

    protected $fillable = [
        'name',
        'origin_type',
        'business_stage',
        'setup_phase',
        'workspace_status',
        'base_currency',
    ];

    protected function casts(): array
    {
        return [
            'origin_type' => BusinessOriginType::class,
            'business_stage' => BusinessStage::class,
            'setup_phase' => SetupPhase::class,
            'workspace_status' => WorkspaceStatus::class,
        ];
    }

    public function memberships(): HasMany
    {
        return $this->hasMany(Membership::class);
    }
}
