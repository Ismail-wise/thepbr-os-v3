<?php

declare(strict_types=1);

namespace Tests\Feature\Events;

use App\Application\Records\CreateDraftRecordVersion;
use App\Application\Records\CreateProposal;
use App\Application\Records\FreezeProposalVersion;
use App\Application\Records\SubmitRecordVersionForReview;
use App\Application\Records\TransitionFormalRecordVersion;
use App\Application\Records\UpdateProposal;
use App\Domain\Access\Enums\PermissionEffect;
use App\Domain\Access\ValueObjects\Capability;
use App\Domain\Businesses\Enums\BusinessOriginType;
use App\Domain\Businesses\Enums\BusinessStage;
use App\Domain\Businesses\Enums\SetupPhase;
use App\Domain\Businesses\Enums\WorkspaceStatus;
use App\Domain\Identity\Enums\AccountStatus;
use App\Domain\Members\Enums\MembershipAccessStatus;
use App\Domain\Records\Enums\FormalRecordState;
use App\Infrastructure\Persistence\Eloquent\Access\AccessPolicy;
use App\Infrastructure\Persistence\Eloquent\Access\Permission;
use App\Infrastructure\Persistence\Eloquent\Access\PermissionGrant;
use App\Infrastructure\Persistence\Eloquent\Businesses\Business;
use App\Infrastructure\Persistence\Eloquent\Identity\User;
use App\Infrastructure\Persistence\Eloquent\Members\Membership;
use App\Infrastructure\Persistence\Eloquent\Records\FormalRecordFamily;
use App\Infrastructure\Persistence\Eloquent\Records\FormalRecordVersion;
use App\Infrastructure\Persistence\Eloquent\Records\Proposal;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Tests\TestCase;

final class Round2OccurrenceEmissionTest extends TestCase
{
    use RefreshDatabase;

    private const string CAPABILITY = 'records.round3.test';

    public function test_proposal_mutations_emit_audit_and_only_freeze_emits_business_event(): void
    {
        [$user, $business] = $this->authorizedContext();

        $proposal = $this->app->make(CreateProposal::class)->execute(
            $user,
            $business,
            new Capability(self::CAPABILITY),
            str_repeat('1', 64),
        );

        $this->assertNotNull($proposal);

        $proposal = $this->app->make(UpdateProposal::class)->execute(
            $user,
            $business,
            new Capability(self::CAPABILITY),
            (string) $proposal->getKey(),
            1,
            str_repeat('2', 64),
        );

        $this->assertNotNull($proposal);

        $frozen = $this->app->make(FreezeProposalVersion::class)->execute(
            $user,
            $business,
            new Capability(self::CAPABILITY),
            (string) $proposal->getKey(),
            2,
            [],
        );

        $this->assertNotNull($frozen);

        $this->assertDatabaseHas('audit_events', [
            'business_id' => $business->getKey(),
            'action' => 'records.proposal.created',
        ]);
        $this->assertDatabaseHas('audit_events', [
            'business_id' => $business->getKey(),
            'action' => 'records.proposal.updated',
        ]);
        $this->assertDatabaseHas('audit_events', [
            'business_id' => $business->getKey(),
            'action' => 'records.proposal_version.frozen',
            'target_version_id' => $frozen->getKey(),
        ]);
        $this->assertDatabaseHas('business_events', [
            'business_id' => $business->getKey(),
            'event_type' => 'records.proposal_version.frozen',
            'aggregate_id' => $proposal->getKey(),
            'aggregate_version_id' => $frozen->getKey(),
        ]);
        $this->assertDatabaseCount('business_events', 1);
    }

    public function test_formal_record_review_and_transition_emit_frozen_and_state_events(): void
    {
        [$user, $business] = $this->authorizedContext();

        $family = FormalRecordFamily::query()->create([
            'business_id' => $business->getKey(),
            'record_type' => 'round3-test',
            'subject_type' => 'fixture',
            'subject_id' => (string) Str::uuid7(),
        ]);

        $version = $this->app->make(CreateDraftRecordVersion::class)->execute(
            $user,
            $business,
            new Capability(self::CAPABILITY),
            (string) $family->getKey(),
            str_repeat('3', 64),
            'Round 3 occurrence test',
        );

        $this->assertNotNull($version);

        $version = $this->app->make(
            SubmitRecordVersionForReview::class,
        )->execute(
            $user,
            $business,
            new Capability(self::CAPABILITY),
            (string) $version->getKey(),
            1,
        );

        $this->assertNotNull($version);

        $transitioned = $this->app->make(
            TransitionFormalRecordVersion::class,
        )->execute(
            $user,
            $business,
            new Capability(self::CAPABILITY),
            (string) $version->getKey(),
            FormalRecordState::UnderReview,
        );

        $this->assertNotNull($transitioned);

        $this->assertDatabaseHas('business_events', [
            'business_id' => $business->getKey(),
            'event_type' => 'records.formal_record_version.review_frozen',
            'aggregate_id' => $version->getKey(),
        ]);
        $this->assertDatabaseHas('business_events', [
            'business_id' => $business->getKey(),
            'event_type' => 'records.formal_record_version.state_changed',
            'aggregate_id' => $version->getKey(),
        ]);
    }

    public function test_effective_head_and_supersession_event_contracts_exist_only_in_transition_primitive(): void
    {
        $source = file_get_contents(
            (new \ReflectionClass(TransitionFormalRecordVersion::class))
                ->getFileName(),
        );

        $this->assertIsString($source);
        $this->assertStringContainsString(
            'records.formal_record_effective_head.changed',
            $source,
        );
        $this->assertStringContainsString(
            'records.formal_record_version.superseded',
            $source,
        );
    }

    /**
     * @return array{User, Business}
     */
    private function authorizedContext(): array
    {
        $user = User::query()->create([
            'email' => 'round3-'.Str::uuid7().'@example.test',
            'password' => Hash::make('test-password'),
            'status' => AccountStatus::Active->value,
            'password_changed_at' => now(),
        ]);

        $business = Business::query()->create([
            'name' => 'Round 3 Business '.Str::uuid7(),
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

        foreach ([
            FormalRecordFamily::class,
            FormalRecordVersion::class,
            Proposal::class,
        ] as $resourceType) {
            AccessPolicy::query()->create([
                'business_id' => $business->getKey(),
                'membership_id' => $membership->getKey(),
                'permission_profile_id' => null,
                'permission_id' => $permission->getKey(),
                'resource_type' => $resourceType,
                'effect' => PermissionEffect::Allow->value,
            ]);
        }

        return [$user, $business];
    }
}
