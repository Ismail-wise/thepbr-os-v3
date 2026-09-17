<?php

declare(strict_types=1);

namespace Tests\Feature\Records;

use App\Application\Records\CreateAmendedDraftVersion;
use App\Application\Records\CreateDraftRecordVersion;
use App\Application\Records\CreateFormalRecordFamily;
use App\Application\Records\ResolveCurrentEffectiveRecordVersion;
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
use App\Domain\Records\Exceptions\InvalidWorkflowTransition;
use App\Domain\Records\ValueObjects\RecordScope;
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
use App\Infrastructure\Persistence\Eloquent\Records\RecordVersionSupersession;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Tests\TestCase;

final class HistoricalIntegrityTest extends TestCase
{
    use RefreshDatabase;

    private const CAPABILITY = 'records.manage';

    public function test_new_effective_version_supersedes_without_rewriting_old_history(): void
    {
        [$user, $business, $membership, $permission, $family] =
            $this->context();

        $v1 = $this->createAndEffectInitial(
            $user,
            $business,
            $family,
            str_repeat('1', 64),
            now()->subHours(2),
        );

        $oldHash = $v1->content_hash;
        $oldTransitionSnapshot = RecordVersionStateTransition::query()
            ->where('formal_record_version_id', $v1->getKey())
            ->orderBy('sequence')
            ->get(['id', 'sequence', 'from_state', 'to_state'])
            ->map(fn ($row): array => [
                (string) $row->id,
                (int) $row->sequence,
                $row->from_state?->value,
                $row->to_state->value,
            ])
            ->all();

        $v2 = $this->app->make(CreateAmendedDraftVersion::class)->execute(
            $user,
            $business,
            new Capability(self::CAPABILITY),
            (string) $v1->getKey(),
            str_repeat('2', 64),
            'Amended effective version',
            now()->subMinute(),
        );

        $this->assertNotNull($v2);

        $this->submitAndEffect($user, $business, $v2);

        $this->assertSame($oldHash, $v1->fresh()->content_hash);

        $preservedRows = RecordVersionStateTransition::query()
            ->where('formal_record_version_id', $v1->getKey())
            ->whereIn(
                'id',
                array_column($oldTransitionSnapshot, 0),
            )
            ->orderBy('sequence')
            ->get(['id', 'sequence', 'from_state', 'to_state'])
            ->map(fn ($row): array => [
                (string) $row->id,
                (int) $row->sequence,
                $row->from_state?->value,
                $row->to_state->value,
            ])
            ->all();

        $this->assertSame(
            $oldTransitionSnapshot,
            $preservedRows,
        );

        $this->assertSame(
            FormalRecordState::Superseded,
            RecordVersionStateTransition::query()
                ->where(
                    'formal_record_version_id',
                    $v1->getKey(),
                )
                ->orderByDesc('sequence')
                ->firstOrFail()
                ->to_state,
        );

        $supersession = RecordVersionSupersession::query()
            ->where('superseded_version_id', $v1->getKey())
            ->firstOrFail();

        $this->assertSame(
            (string) $v2->getKey(),
            (string) $supersession->superseding_version_id,
        );
        $this->assertTrue(
            $supersession->superseded_at->equalTo($v2->effective_from),
        );

        $this->assertSame(
            (string) $v2->getKey(),
            (string) RecordFamilyEffectiveHead::query()
                ->where(
                    'formal_record_family_id',
                    $family->getKey(),
                )
                ->firstOrFail()
                ->formal_record_version_id,
        );
    }

    public function test_current_effective_is_not_latest_created_version(): void
    {
        [$user, $business, $membership, $permission, $family] =
            $this->context();

        $v1 = $this->createAndEffectInitial(
            $user,
            $business,
            $family,
            str_repeat('3', 64),
            now()->subHour(),
        );

        $draft = $this->app->make(CreateDraftRecordVersion::class)->execute(
            $user,
            $business,
            new Capability(self::CAPABILITY),
            (string) $family->getKey(),
            str_repeat('4', 64),
            'Later-created draft',
            now()->addDay(),
            null,
            null,
            (string) $v1->getKey(),
        );

        $this->assertNotNull($draft);
        $this->assertGreaterThan($v1->version_number, $draft->version_number);

        $current = $this->app
            ->make(ResolveCurrentEffectiveRecordVersion::class)
            ->execute(
                $user,
                $business,
                new Capability(self::CAPABILITY),
                (string) $family->getKey(),
            );

        $this->assertNotNull($current);
        $this->assertSame(
            (string) $v1->getKey(),
            (string) $current->getKey(),
        );
    }

    public function test_future_effective_version_cannot_become_current_early(): void
    {
        [$user, $business, $membership, $permission, $family] =
            $this->context();

        $v1 = $this->createAndEffectInitial(
            $user,
            $business,
            $family,
            str_repeat('5', 64),
            now()->subHour(),
        );

        $future = $this->app->make(CreateAmendedDraftVersion::class)->execute(
            $user,
            $business,
            new Capability(self::CAPABILITY),
            (string) $v1->getKey(),
            str_repeat('6', 64),
            'Future effective',
            now()->addDay(),
        );

        $this->assertNotNull($future);

        $this->app->make(SubmitRecordVersionForReview::class)->execute(
            $user,
            $business,
            new Capability(self::CAPABILITY),
            (string) $future->getKey(),
            1,
        );

        $transition = $this->app->make(TransitionFormalRecordVersion::class);

        foreach ([
            FormalRecordState::UnderReview,
            FormalRecordState::Approved,
            FormalRecordState::ReadyForEffect,
        ] as $state) {
            $transition->execute(
                $user,
                $business,
                new Capability(self::CAPABILITY),
                (string) $future->getKey(),
                $state,
            );
        }

        try {
            $transition->execute(
                $user,
                $business,
                new Capability(self::CAPABILITY),
                (string) $future->getKey(),
                FormalRecordState::Effective,
            );

            $this->fail(
                'Future-effective version must not become current early.',
            );
        } catch (InvalidWorkflowTransition) {
            $this->addToAssertionCount(1);
        }

        $current = $this->app
            ->make(ResolveCurrentEffectiveRecordVersion::class)
            ->execute(
                $user,
                $business,
                new Capability(self::CAPABILITY),
                (string) $family->getKey(),
            );

        $this->assertSame(
            (string) $v1->getKey(),
            (string) $current?->getKey(),
        );
    }

    /**
     * @return array{User, Business, Membership, Permission, FormalRecordFamily}
     */
    private function context(): array
    {
        $user = User::query()->create([
            'email' => 'history-'.Str::uuid7().'@example.test',
            'password' => Hash::make('test-password'),
            'status' => AccountStatus::Active->value,
            'password_changed_at' => now(),
        ]);

        $business = Business::query()->create([
            'name' => 'History Business '.Str::uuid7(),
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

        return [$user, $business, $membership, $permission, $family];
    }

    private function createAndEffectInitial(
        User $user,
        Business $business,
        FormalRecordFamily $family,
        string $hash,
        mixed $effectiveFrom,
    ): FormalRecordVersion {
        $version = $this->app->make(CreateDraftRecordVersion::class)->execute(
            $user,
            $business,
            new Capability(self::CAPABILITY),
            (string) $family->getKey(),
            $hash,
            'Initial effective version',
            $effectiveFrom,
        );

        $this->assertNotNull($version);

        $this->submitAndEffect($user, $business, $version);

        return $version->fresh();
    }

    private function submitAndEffect(
        User $user,
        Business $business,
        FormalRecordVersion $version,
    ): void {
        $this->app->make(SubmitRecordVersionForReview::class)->execute(
            $user,
            $business,
            new Capability(self::CAPABILITY),
            (string) $version->getKey(),
            (int) $version->revision,
        );

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
            );

            $this->assertNotNull($result);
        }
    }
}
