<?php

declare(strict_types=1);

namespace Tests\Feature\Portability;

use App\Application\Access\ProvisionStandardAccessProfiles;
use App\Application\Portability\ChangeWorkspaceArchiveState;
use App\Domain\Businesses\Enums\WorkspaceStatus;
use App\Infrastructure\Persistence\Eloquent\Businesses\Business;
use App\Infrastructure\Persistence\Eloquent\Identity\User;
use App\Infrastructure\Persistence\Eloquent\Members\Membership;
use App\Infrastructure\Persistence\Eloquent\Portability\BusinessArchiveTransition;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use RuntimeException;
use Tests\TestCase;

final class F7ArchiveWorkflowTest extends TestCase
{
    use RefreshDatabase;

    public function test_active_business_archives_and_explicitly_unarchives_without_becoming_closed(): void
    {
        [$user, $business] = $this->workspace('archive-active');

        $workflow = $this->app->make(ChangeWorkspaceArchiveState::class);

        $archived = $workflow->archive(
            $user,
            $business,
            'Pause workspace operations while preserving all history.',
        );

        self::assertNotNull($archived);
        self::assertSame(
            WorkspaceStatus::Archived,
            $archived->workspace_status,
        );
        self::assertNotSame(
            WorkspaceStatus::Closed,
            $archived->workspace_status,
        );

        $restored = $workflow->unarchive(
            $user,
            $archived,
            'Resume workspace access after archive review.',
        );

        self::assertNotNull($restored);
        self::assertSame(
            WorkspaceStatus::Active,
            $restored->workspace_status,
        );

        $history = BusinessArchiveTransition::query()
            ->where('business_id', $business->getKey())
            ->orderBy('occurred_at')
            ->get();

        self::assertCount(2, $history);
        self::assertSame(
            WorkspaceStatus::Active,
            $history[0]->from_status,
        );
        self::assertSame(
            WorkspaceStatus::Archived,
            $history[0]->to_status,
        );
        self::assertSame(
            WorkspaceStatus::Archived,
            $history[1]->from_status,
        );
        self::assertSame(
            WorkspaceStatus::Active,
            $history[1]->to_status,
        );
    }

    public function test_restricted_business_restores_to_restricted_after_archive(): void
    {
        [$user, $business] = $this->workspace('archive-restricted');

        $business->workspace_status = WorkspaceStatus::Restricted;
        $business->save();

        $workflow = $this->app->make(ChangeWorkspaceArchiveState::class);

        $archived = $workflow->archive(
            $user,
            $business->fresh(),
            'Preserve restricted workspace while archived.',
        );

        self::assertNotNull($archived);
        self::assertSame(
            WorkspaceStatus::Archived,
            $archived->workspace_status,
        );

        $restored = $workflow->unarchive(
            $user,
            $archived,
            'Restore the pre-archive restricted state.',
        );

        self::assertNotNull($restored);
        self::assertSame(
            WorkspaceStatus::Restricted,
            $restored->workspace_status,
        );
    }

    public function test_closed_business_cannot_be_archived_or_unclosed_by_archive_workflow(): void
    {
        [$user, $business] = $this->workspace('archive-closed');

        $business->workspace_status = WorkspaceStatus::Closed;
        $business->save();

        $workflow = $this->app->make(ChangeWorkspaceArchiveState::class);

        try {
            $workflow->archive(
                $user,
                $business->fresh(),
                'Attempt to archive a Closed Business.',
            );

            self::fail('Closed Business must not be archived.');
        } catch (RuntimeException $exception) {
            self::assertStringContainsString(
                'Closed Business',
                $exception->getMessage(),
            );
        }

        self::assertSame(
            WorkspaceStatus::Closed,
            $business->fresh()->workspace_status,
        );

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Closed Business');

        $workflow->unarchive(
            $user,
            $business->fresh(),
            'Attempt to unclose through Archive.',
        );
    }

    /** @return array{User,Business,Membership} */
    private function workspace(string $prefix): array
    {
        $business = Business::query()->create([
            'name' => 'F7 Archive '.Str::uuid7(),
            'origin_type' => 'started_through_pbr',
            'business_stage' => 'operating',
            'setup_phase' => 'formation',
            'workspace_status' => WorkspaceStatus::Active,
            'base_currency' => 'USD',
        ]);

        $user = User::query()->create([
            'email' => $prefix.'-'.Str::uuid7().'@example.test',
            'password' => Hash::make('test-password'),
            'status' => 'active',
            'password_changed_at' => now(),
        ]);

        $membership = Membership::query()->create([
            'user_id' => $user->getKey(),
            'business_id' => $business->getKey(),
            'access_status' => 'active',
        ]);

        $this->app
            ->make(ProvisionStandardAccessProfiles::class)
            ->execute($business, $membership);

        return [$user, $business, $membership];
    }
}
