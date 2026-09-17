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
use App\Domain\Records\Enums\FormalRecordState;
use App\Domain\Records\ValueObjects\RecordScope;
use App\Infrastructure\Persistence\Eloquent\Access\AccessPolicy;
use App\Infrastructure\Persistence\Eloquent\Access\Permission;
use App\Infrastructure\Persistence\Eloquent\Access\PermissionGrant;
use App\Infrastructure\Persistence\Eloquent\Businesses\Business;
use App\Infrastructure\Persistence\Eloquent\Identity\User;
use App\Infrastructure\Persistence\Eloquent\Members\Membership;
use App\Infrastructure\Persistence\Eloquent\Records\FormalRecordFamily;
use App\Infrastructure\Persistence\Eloquent\Records\FormalRecordVersion;
use App\Infrastructure\Persistence\Eloquent\Records\RecordVersionStateTransition;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use ReflectionClass;
use Tests\TestCase;

final class RecordLifecycleTenantIsolationTest extends TestCase
{
    use RefreshDatabase;

    private const CAPABILITY = 'records.manage';

    public function test_cross_business_family_version_predecessor_and_history_ids_fail_closed(): void
    {
        [$user, $businessA, $membership, $permission] = $this->context();

        $businessB = $this->business('Business B');

        $familyA = $this->app->make(CreateFormalRecordFamily::class)->execute(
            $user,
            $businessA,
            new Capability(self::CAPABILITY),
            new RecordScope(
                'policy',
                'business',
                (string) $businessA->getKey(),
            ),
        );

        $familyB = FormalRecordFamily::query()->create([
            'business_id' => $businessB->getKey(),
            'record_type' => 'policy',
            'subject_type' => 'business',
            'subject_id' => (string) $businessB->getKey(),
        ]);

        foreach ([FormalRecordFamily::class, FormalRecordVersion::class] as $type) {
            AccessPolicy::query()->create([
                'business_id' => $businessA->getKey(),
                'membership_id' => $membership->getKey(),
                'permission_profile_id' => null,
                'permission_id' => $permission->getKey(),
                'resource_type' => $type,
                'effect' => PermissionEffect::Allow->value,
            ]);
        }

        $foreignVersion = FormalRecordVersion::query()->create([
            'business_id' => $businessB->getKey(),
            'formal_record_family_id' => $familyB->getKey(),
            'version_number' => 1,
            'revision' => 1,
            'change_summary' => 'Foreign version',
            'created_by_user_id' => $user->getKey(),
            'last_changed_by_user_id' => $user->getKey(),
            'content_hash' => str_repeat('b', 64),
        ]);

        RecordVersionStateTransition::query()->create([
            'business_id' => $businessB->getKey(),
            'formal_record_version_id' => $foreignVersion->getKey(),
            'sequence' => 1,
            'from_state' => null,
            'to_state' => FormalRecordState::Draft->value,
            'transitioned_by_user_id' => $user->getKey(),
            'occurred_at' => now(),
        ]);

        $this->assertNull(
            $this->app->make(CreateDraftRecordVersion::class)->execute(
                $user,
                $businessA,
                new Capability(self::CAPABILITY),
                (string) $familyB->getKey(),
                str_repeat('1', 64),
                'Cross-business family attempt',
            ),
        );

        $firstA = $this->app->make(CreateDraftRecordVersion::class)->execute(
            $user,
            $businessA,
            new Capability(self::CAPABILITY),
            (string) $familyA->getKey(),
            str_repeat('2', 64),
            'Business A version',
        );

        $this->assertNotNull($firstA);

        $this->assertNull(
            $this->app->make(CreateDraftRecordVersion::class)->execute(
                $user,
                $businessA,
                new Capability(self::CAPABILITY),
                (string) $familyA->getKey(),
                str_repeat('3', 64),
                'Cross-business predecessor attempt',
                null,
                null,
                null,
                (string) $foreignVersion->getKey(),
            ),
        );

        $this->assertSame(
            [],
            $this->app->make(ListRecordVersionHistory::class)->execute(
                $user,
                $businessA,
                new Capability(self::CAPABILITY),
                (string) $familyB->getKey(),
            ),
        );
    }

    public function test_record_lifecycle_source_has_no_creator_owner_or_governance_shortcut(): void
    {
        foreach ([
            CreateDraftRecordVersion::class,
            ListRecordVersionHistory::class,
        ] as $class) {
            $source = file_get_contents(
                (new ReflectionClass($class))->getFileName(),
            );

            $this->assertIsString($source);

            $normalized = strtolower($source);

            foreach ([
                'owner_user_id',
                'ownership',
                'governance',
                'creator shortcut',
            ] as $forbidden) {
                $this->assertStringNotContainsString(
                    $forbidden,
                    $normalized,
                );
            }
        }
    }

    /**
     * @return array{User, Business, Membership, Permission}
     */
    private function context(): array
    {
        $user = User::query()->create([
            'email' => 'tenant-record-'.Str::uuid7().'@example.test',
            'password' => Hash::make('test-password'),
            'status' => AccountStatus::Active->value,
            'password_changed_at' => now(),
        ]);

        $business = $this->business('Business A');

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

    private function business(string $name): Business
    {
        return Business::query()->create([
            'name' => $name.' '.Str::uuid7(),
            'origin_type' => BusinessOriginType::StartedThroughPbr->value,
            'business_stage' => BusinessStage::Idea->value,
            'setup_phase' => SetupPhase::Formation->value,
            'workspace_status' => WorkspaceStatus::Active->value,
            'base_currency' => 'USD',
        ]);
    }
}
