<?php

namespace Tests\Feature\Businesses;

use App\Domain\Businesses\Enums\BusinessOriginType;
use App\Domain\Businesses\Enums\BusinessStage;
use App\Domain\Businesses\Enums\SetupPhase;
use App\Domain\Businesses\Enums\WorkspaceStatus;
use App\Infrastructure\Persistence\Eloquent\Businesses\Business;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

final class BusinessPersistenceTest extends TestCase
{
    use RefreshDatabase;

    public function test_business_uses_uuid_v7_and_casts_independent_dimensions(): void
    {
        $business = Business::query()->create([
            'name' => 'PBR Example',
            'origin_type' => BusinessOriginType::StartedThroughPbr,
            'business_stage' => BusinessStage::Idea,
            'setup_phase' => SetupPhase::Formation,
            'workspace_status' => WorkspaceStatus::Active,
            'base_currency' => 'USD',
        ])->refresh();

        $this->assertTrue(Str::isUuid((string) $business->id, 7));
        $this->assertSame('PBR Example', $business->name);
        $this->assertSame(BusinessOriginType::StartedThroughPbr, $business->origin_type);
        $this->assertSame(BusinessStage::Idea, $business->business_stage);
        $this->assertSame(SetupPhase::Formation, $business->setup_phase);
        $this->assertSame(WorkspaceStatus::Active, $business->workspace_status);
        $this->assertSame('USD', $business->base_currency);
    }

    public function test_existing_business_can_use_independent_stage_and_nullable_setup_phase(): void
    {
        $business = Business::query()->create([
            'name' => 'Existing Company',
            'origin_type' => BusinessOriginType::ExistingBusinessImportedIntoPbr,
            'business_stage' => BusinessStage::Operating,
            'setup_phase' => null,
            'workspace_status' => WorkspaceStatus::Active,
            'base_currency' => 'MMK',
        ])->refresh();

        $this->assertSame(
            BusinessOriginType::ExistingBusinessImportedIntoPbr,
            $business->origin_type,
        );
        $this->assertSame(BusinessStage::Operating, $business->business_stage);
        $this->assertNull($business->setup_phase);
        $this->assertSame(WorkspaceStatus::Active, $business->workspace_status);
        $this->assertSame('MMK', $business->base_currency);
    }

    public function test_workspace_status_defaults_to_active_without_collapsing_other_dimensions(): void
    {
        $business = Business::query()->create([
            'name' => 'Default Status Business',
            'origin_type' => BusinessOriginType::StartedThroughPbr,
            'business_stage' => BusinessStage::Planning,
            'setup_phase' => null,
            'base_currency' => 'THB',
        ])->refresh();

        $this->assertSame(BusinessStage::Planning, $business->business_stage);
        $this->assertNull($business->setup_phase);
        $this->assertSame(WorkspaceStatus::Active, $business->workspace_status);
    }
}
