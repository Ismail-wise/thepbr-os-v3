<?php

declare(strict_types=1);

namespace Tests\Feature\Records;

use App\Application\Records\CreateDraftRecordVersion;
use App\Application\Records\CreateFormalRecordFamily;
use App\Application\Records\ListRecordVersionHistory;
use App\Domain\Access\Enums\PermissionEffect;
use App\Domain\Access\ValueObjects\Capability;
use App\Domain\Businesses\Enums\BusinessOriginType;
use App\Domain\Businesses\Enums\BusinessStage;
use App\Domain\Businesses\Enums\SetupPhase;
use App\Domain\Businesses\Enums\WorkspaceStatus;
use App\Domain\Identity\Enums\AccountStatus;
use App\Domain\Members\Enums\MembershipAccessStatus;
use App\Domain\Records\ValueObjects\RecordScope;
use App\Infrastructure\Persistence\Eloquent\Access\AccessPolicy;
use App\Infrastructure\Persistence\Eloquent\Access\Permission;
use App\Infrastructure\Persistence\Eloquent\Access\PermissionGrant;
use App\Infrastructure\Persistence\Eloquent\Businesses\Business;
use App\Infrastructure\Persistence\Eloquent\Identity\User;
use App\Infrastructure\Persistence\Eloquent\Members\Membership;
use App\Infrastructure\Persistence\Eloquent\Records\FormalRecordFamily;
use App\Infrastructure\Persistence\Eloquent\Records\FormalRecordVersion;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Tests\TestCase;

final class RecordVersionHistoryTest extends TestCase
{
    use RefreshDatabase;

    private const CAPABILITY = 'records.manage';

    public function test_history_is_tenant_scoped_and_ordered_by_version_number(): void
    {
        $user = User::query()->create([
            'email' => 'version-history-'.Str::uuid7().'@example.test',
            'password' => Hash::make('test-password'),
            'status' => AccountStatus::Active->value,
            'password_changed_at' => now(),
        ]);

        $business = Business::query()->create([
            'name' => 'History Listing '.Str::uuid7(),
            'origin_type' => BusinessOriginType::StartedThroughPbr->value,
            'business_stage' => BusinessStage::Idea->value,
            'setup_phase' => SetupPhase::Formation->value,
            'workspace_status' => WorkspaceStatus::Active->value,
            'base_currency' => 'USD',
        ]);

        $membership = Membership::query()->create([
            'user_id' => $user->getKey(),
            'business_id' => $business->getKey(),
            'access_status' => MembershipAccessStatus::Active->value,
        ]);

        $permission = Permission::query()->create([
            'key' => self::CAPABILITY,
        ]);

        PermissionGrant::query()->create([
            'business_id' => $business->getKey(),
            'membership_id' => $membership->getKey(),
            'permission_id' => $permission->getKey(),
            'effect' => PermissionEffect::Allow->value,
        ]);

        $family = $this->app->make(CreateFormalRecordFamily::class)->execute(
            $user,
            $business,
            new Capability(self::CAPABILITY),
            new RecordScope(
                'policy',
                'business',
                (string) $business->getKey(),
            ),
        );

        $this->assertNotNull($family);

        foreach ([FormalRecordFamily::class, FormalRecordVersion::class] as $type) {
            AccessPolicy::query()->create([
                'business_id' => $business->getKey(),
                'membership_id' => $membership->getKey(),
                'permission_profile_id' => null,
                'permission_id' => $permission->getKey(),
                'resource_type' => $type,
                'effect' => PermissionEffect::Allow->value,
            ]);
        }

        $v1 = $this->app->make(CreateDraftRecordVersion::class)->execute(
            $user,
            $business,
            new Capability(self::CAPABILITY),
            (string) $family->getKey(),
            str_repeat('1', 64),
            'Version 1',
        );

        $this->assertNotNull($v1);

        $v2 = $this->app->make(CreateDraftRecordVersion::class)->execute(
            $user,
            $business,
            new Capability(self::CAPABILITY),
            (string) $family->getKey(),
            str_repeat('2', 64),
            'Version 2',
            null,
            null,
            null,
            (string) $v1->getKey(),
        );

        $this->assertNotNull($v2);

        $history = $this->app->make(ListRecordVersionHistory::class)->execute(
            $user,
            $business,
            new Capability(self::CAPABILITY),
            (string) $family->getKey(),
        );

        $this->assertSame(
            [1, 2],
            array_map(
                static fn (FormalRecordVersion $version): int => $version->version_number,
                $history,
            ),
        );
    }
}
