<?php

declare(strict_types=1);

namespace Tests\Feature\Records;

use App\Application\Records\CreateDraftRecordVersion;
use App\Application\Records\CreateFormalRecordFamily;
use App\Application\Records\SubmitRecordVersionForReview;
use App\Application\Records\TransitionFormalRecordVersion;
use App\Domain\Access\Enums\PermissionEffect;
use App\Domain\Access\ValueObjects\Capability;
use App\Domain\Businesses\Enums\BusinessOriginType;
use App\Domain\Businesses\Enums\BusinessStage;
use App\Domain\Businesses\Enums\SetupPhase;
use App\Domain\Businesses\Enums\WorkspaceStatus;
use App\Domain\Identity\Enums\AccountStatus;
use App\Domain\Members\Enums\MembershipAccessStatus;
use App\Domain\Records\Enums\FormalRecordState;
use App\Domain\Records\ValueObjects\RecordScope;
use App\Domain\Records\ValueObjects\RequirementResult;
use App\Infrastructure\Persistence\Eloquent\Access\AccessPolicy;
use App\Infrastructure\Persistence\Eloquent\Access\Permission;
use App\Infrastructure\Persistence\Eloquent\Access\PermissionGrant;
use App\Infrastructure\Persistence\Eloquent\Businesses\Business;
use App\Infrastructure\Persistence\Eloquent\Identity\User;
use App\Infrastructure\Persistence\Eloquent\Members\Membership;
use App\Infrastructure\Persistence\Eloquent\Records\FormalRecordFamily;
use App\Infrastructure\Persistence\Eloquent\Records\FormalRecordVersion;
use App\Infrastructure\Persistence\Eloquent\Records\RecordFamilyEffectiveHead;
use App\Infrastructure\Persistence\Eloquent\Records\RecordVersionStateTransition;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Tests\TestCase;

final class FormalRecordLifecycleTest extends TestCase
{
    use RefreshDatabase;

    private const CAPABILITY = 'records.manage';

    public function test_full_mechanical_lifecycle_reaches_current_effective_truth(): void
    {
        [$user, $business, $membership, $permission] = $this->context();

        $family = $this->app->make(CreateFormalRecordFamily::class)->execute(
            $user,
            $business,
            new Capability(self::CAPABILITY),
            new RecordScope(
                'operating_policy',
                'business',
                (string) $business->getKey(),
            ),
        );

        $this->assertNotNull($family);

        $this->allowClass(
            $business,
            $membership,
            $permission,
            FormalRecordFamily::class,
        );
        $this->allowClass(
            $business,
            $membership,
            $permission,
            FormalRecordVersion::class,
        );

        $version = $this->app->make(CreateDraftRecordVersion::class)->execute(
            $user,
            $business,
            new Capability(self::CAPABILITY),
            (string) $family->getKey(),
            str_repeat('1', 64),
            'Initial controlled version',
            now()->subMinute(),
        );

        $this->assertNotNull($version);
        $this->assertSame(1, $version->revision);

        $submitted = $this->app
            ->make(SubmitRecordVersionForReview::class)
            ->execute(
                $user,
                $business,
                new Capability(self::CAPABILITY),
                (string) $version->getKey(),
                1,
            );

        $this->assertNotNull($submitted);
        $this->assertNotNull($submitted->frozen_at);

        $transition = $this->app->make(TransitionFormalRecordVersion::class);

        foreach ([
            FormalRecordState::UnderReview,
            FormalRecordState::Approved,
            FormalRecordState::ReadyForEffect,
            FormalRecordState::Effective,
        ] as $state) {
            $result = $transition->execute(
                $user,
                $business,
                new Capability(self::CAPABILITY),
                (string) $version->getKey(),
                $state,
                $state === FormalRecordState::Approved
                    ? [RequirementResult::warning('non-blocking-warning')]
                    : [RequirementResult::met('mechanical-check')],
            );

            $this->assertNotNull($result);
        }

        $head = RecordFamilyEffectiveHead::query()
            ->where('formal_record_family_id', $family->getKey())
            ->firstOrFail();

        $this->assertSame(
            (string) $version->getKey(),
            (string) $head->formal_record_version_id,
        );

        $this->assertSame(
            FormalRecordState::Effective,
            RecordVersionStateTransition::query()
                ->where(
                    'formal_record_version_id',
                    $version->getKey(),
                )
                ->orderByDesc('sequence')
                ->firstOrFail()
                ->to_state,
        );
    }

    /**
     * @return array{User, Business, Membership, Permission}
     */
    private function context(): array
    {
        $user = User::query()->create([
            'email' => 'lifecycle-'.Str::uuid7().'@example.test',
            'password' => Hash::make('test-password'),
            'status' => AccountStatus::Active->value,
            'password_changed_at' => now(),
        ]);

        $business = Business::query()->create([
            'name' => 'Lifecycle Business '.Str::uuid7(),
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

        return [$user, $business, $membership, $permission];
    }

    private function allowClass(
        Business $business,
        Membership $membership,
        Permission $permission,
        string $resourceType,
    ): void {
        AccessPolicy::query()->create([
            'business_id' => $business->getKey(),
            'membership_id' => $membership->getKey(),
            'permission_profile_id' => null,
            'permission_id' => $permission->getKey(),
            'resource_type' => $resourceType,
            'effect' => PermissionEffect::Allow->value,
        ]);
    }
}
