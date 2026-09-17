<?php

declare(strict_types=1);

namespace Tests\Feature\Records;

use App\Application\Records\CreateDraftRecordVersion;
use App\Application\Records\CreateFormalRecordFamily;
use App\Application\Records\SubmitRecordVersionForReview;
use App\Application\Records\UpdateDraftRecordVersion;
use App\Domain\Access\Enums\PermissionEffect;
use App\Domain\Access\ValueObjects\Capability;
use App\Domain\Businesses\Enums\BusinessOriginType;
use App\Domain\Businesses\Enums\BusinessStage;
use App\Domain\Businesses\Enums\SetupPhase;
use App\Domain\Businesses\Enums\WorkspaceStatus;
use App\Domain\Identity\Enums\AccountStatus;
use App\Domain\Members\Enums\MembershipAccessStatus;
use App\Domain\Records\Exceptions\InvalidWorkflowTransition;
use App\Domain\Records\Exceptions\StaleRevision;
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

final class OptimisticConcurrencyTest extends TestCase
{
    use RefreshDatabase;

    private const CAPABILITY = 'records.manage';

    public function test_revision_increments_and_stale_revision_is_denied(): void
    {
        [$user, $business, $membership, $permission, $family, $version] =
            $this->draft();

        $updated = $this->app->make(UpdateDraftRecordVersion::class)->execute(
            $user,
            $business,
            new Capability(self::CAPABILITY),
            (string) $version->getKey(),
            1,
            str_repeat('2', 64),
            'Updated draft',
        );

        $this->assertNotNull($updated);
        $this->assertSame(2, $updated->revision);

        $this->expectException(StaleRevision::class);

        $this->app->make(UpdateDraftRecordVersion::class)->execute(
            $user,
            $business,
            new Capability(self::CAPABILITY),
            (string) $version->getKey(),
            1,
            str_repeat('3', 64),
            'Stale attempt',
        );
    }

    public function test_frozen_review_version_cannot_return_to_editable_draft(): void
    {
        [$user, $business, $membership, $permission, $family, $version] =
            $this->draft();

        $this->app->make(SubmitRecordVersionForReview::class)->execute(
            $user,
            $business,
            new Capability(self::CAPABILITY),
            (string) $version->getKey(),
            1,
        );

        $this->expectException(InvalidWorkflowTransition::class);

        $this->app->make(UpdateDraftRecordVersion::class)->execute(
            $user,
            $business,
            new Capability(self::CAPABILITY),
            (string) $version->getKey(),
            1,
            str_repeat('4', 64),
            'Illegal frozen edit',
        );
    }

    /**
     * @return array{
     *   User,
     *   Business,
     *   Membership,
     *   Permission,
     *   FormalRecordFamily,
     *   FormalRecordVersion
     * }
     */
    private function draft(): array
    {
        $user = User::query()->create([
            'email' => 'concurrency-'.Str::uuid7().'@example.test',
            'password' => Hash::make('test-password'),
            'status' => AccountStatus::Active->value,
            'password_changed_at' => now(),
        ]);

        $business = Business::query()->create([
            'name' => 'Concurrency Business '.Str::uuid7(),
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

        $version = $this->app->make(CreateDraftRecordVersion::class)->execute(
            $user,
            $business,
            new Capability(self::CAPABILITY),
            (string) $family->getKey(),
            str_repeat('1', 64),
            'Initial draft',
        );

        $this->assertNotNull($version);

        return [
            $user,
            $business,
            $membership,
            $permission,
            $family,
            $version,
        ];
    }
}
