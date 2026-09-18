<?php

declare(strict_types=1);

namespace Tests\Feature\Activity;

use App\Application\Activity\ListAuthorizedBusinessActivity;
use App\Application\Events\AppendBusinessEvent;
use App\Domain\Access\Enums\PermissionEffect;
use App\Domain\Access\ValueObjects\Capability;
use App\Domain\Businesses\Enums\BusinessOriginType;
use App\Domain\Businesses\Enums\BusinessStage;
use App\Domain\Businesses\Enums\SetupPhase;
use App\Domain\Businesses\Enums\WorkspaceStatus;
use App\Domain\Events\ValueObjects\OccurrenceTarget;
use App\Domain\Events\ValueObjects\SafeBusinessEventPayload;
use App\Domain\Identity\Enums\AccountStatus;
use App\Domain\Members\Enums\MembershipAccessStatus;
use App\Infrastructure\Persistence\Eloquent\Access\AccessPolicy;
use App\Infrastructure\Persistence\Eloquent\Access\Permission;
use App\Infrastructure\Persistence\Eloquent\Access\PermissionGrant;
use App\Infrastructure\Persistence\Eloquent\Access\RecordAccessRule;
use App\Infrastructure\Persistence\Eloquent\Businesses\Business;
use App\Infrastructure\Persistence\Eloquent\Identity\User;
use App\Infrastructure\Persistence\Eloquent\Members\Membership;
use App\Infrastructure\Persistence\Eloquent\Records\Proposal;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Tests\TestCase;

final class ActivityPrivacyTest extends TestCase
{
    use RefreshDatabase;

    private const string CAPABILITY = 'records.activity.view';

    public function test_activity_filters_denied_missing_and_foreign_events_before_projection(): void
    {
        [$user, $business, $membership, $permission] =
            $this->authorizedContext();

        $visible = $this->proposal($business, $user, '1');
        $denied = $this->proposal($business, $user, '2');

        RecordAccessRule::query()->create([
            'business_id' => $business->getKey(),
            'membership_id' => $membership->getKey(),
            'permission_profile_id' => null,
            'permission_id' => $permission->getKey(),
            'resource_type' => Proposal::class,
            'resource_id' => $denied->getKey(),
            'effect' => PermissionEffect::Deny->value,
        ]);

        $this->event($business, $visible, 1);
        $this->event($business, $denied, 2);

        $this->app->make(AppendBusinessEvent::class)->append(
            $business,
            'records.proposal_version.frozen',
            new OccurrenceTarget(
                (string) $business->getKey(),
                'proposal',
                (string) Str::uuid7(),
            ),
            new OccurrenceTarget(
                (string) $business->getKey(),
                'proposal',
                (string) Str::uuid7(),
            ),
            SafeBusinessEventPayload::from(['version_number' => 99]),
            now()->subSecond(),
        );

        $foreignBusiness = $this->business();
        $foreignProposal = $this->proposal($foreignBusiness, $user, '3');
        $this->event($foreignBusiness, $foreignProposal, 3);

        $activity = $this->app->make(
            ListAuthorizedBusinessActivity::class,
        )->execute(
            $user,
            $business,
            new Capability(self::CAPABILITY),
        );

        $this->assertNotNull($activity);
        $this->assertCount(1, $activity['items']);
        $this->assertSame(
            1,
            $activity['items'][0]['versionNumber'] ?? null,
        );
        $this->assertArrayNotHasKey('id', $activity['items'][0]);
        $this->assertArrayNotHasKey('payload', $activity['items'][0]);
        $this->assertArrayNotHasKey('metadata', $activity['items'][0]);
        $this->assertArrayNotHasKey('total', $activity);
    }

    public function test_cursor_pagination_skips_restricted_rows_without_exposing_counts(): void
    {
        [$user, $business, $membership, $permission] =
            $this->authorizedContext();

        $newest = $this->proposal($business, $user, '4');
        $denied = $this->proposal($business, $user, '5');
        $oldest = $this->proposal($business, $user, '6');

        RecordAccessRule::query()->create([
            'business_id' => $business->getKey(),
            'membership_id' => $membership->getKey(),
            'permission_profile_id' => null,
            'permission_id' => $permission->getKey(),
            'resource_type' => Proposal::class,
            'resource_id' => $denied->getKey(),
            'effect' => PermissionEffect::Deny->value,
        ]);

        $this->event($business, $oldest, 3, now()->subSeconds(3));
        $this->event($business, $denied, 2, now()->subSeconds(2));
        $this->event($business, $newest, 1, now()->subSecond());

        $service = $this->app->make(
            ListAuthorizedBusinessActivity::class,
        );

        $first = $service->execute(
            $user,
            $business,
            new Capability(self::CAPABILITY),
            null,
            1,
        );

        $this->assertNotNull($first);
        $this->assertSame(1, $first['items'][0]['versionNumber'] ?? null);
        $this->assertNotNull($first['nextCursor']);

        $second = $service->execute(
            $user,
            $business,
            new Capability(self::CAPABILITY),
            $first['nextCursor'],
            1,
        );

        $this->assertNotNull($second);
        $this->assertSame(3, $second['items'][0]['versionNumber'] ?? null);
    }

    /**
     * @return array{User, Business, Membership, Permission}
     */
    private function authorizedContext(): array
    {
        $user = User::query()->create([
            'email' => 'activity-'.Str::uuid7().'@example.test',
            'password' => Hash::make('test-password'),
            'status' => AccountStatus::Active->value,
            'password_changed_at' => now(),
        ]);
        $business = $this->business();
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
        AccessPolicy::query()->create([
            'business_id' => $business->getKey(),
            'membership_id' => $membership->getKey(),
            'permission_profile_id' => null,
            'permission_id' => $permission->getKey(),
            'resource_type' => Proposal::class,
            'effect' => PermissionEffect::Allow->value,
        ]);

        return [$user, $business, $membership, $permission];
    }

    private function business(): Business
    {
        return Business::query()->create([
            'name' => 'Activity Business '.Str::uuid7(),
            'origin_type' => BusinessOriginType::StartedThroughPbr->value,
            'business_stage' => BusinessStage::Idea->value,
            'setup_phase' => SetupPhase::Formation->value,
            'workspace_status' => WorkspaceStatus::Active->value,
            'base_currency' => 'USD',
        ]);
    }

    private function proposal(
        Business $business,
        User $user,
        string $seed,
    ): Proposal {
        return Proposal::query()->create([
            'business_id' => $business->getKey(),
            'revision' => 1,
            'content_hash' => str_repeat($seed, 64),
            'created_by_user_id' => $user->getKey(),
            'last_changed_by_user_id' => $user->getKey(),
        ]);
    }

    private function event(
        Business $business,
        Proposal $proposal,
        int $versionNumber,
        ?\DateTimeInterface $occurredAt = null,
    ): void {
        $this->app->make(AppendBusinessEvent::class)->append(
            $business,
            'records.proposal_version.frozen',
            new OccurrenceTarget(
                (string) $business->getKey(),
                'proposal',
                (string) $proposal->getKey(),
                (string) Str::uuid7(),
            ),
            new OccurrenceTarget(
                (string) $business->getKey(),
                'proposal',
                (string) $proposal->getKey(),
            ),
            SafeBusinessEventPayload::from([
                'version_number' => $versionNumber,
            ]),
            $occurredAt ?? now(),
        );
    }
}
