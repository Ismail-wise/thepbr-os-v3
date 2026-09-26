<?php

declare(strict_types=1);

namespace Tests\Feature\Governance;

use App\Application\Businesses\CreateBusiness;
use App\Application\Governance\CompleteProposalReview;
use App\Application\Governance\CreateProposalReview;
use App\Application\Governance\OpenGovernanceDecision;
use App\Domain\Businesses\Enums\BusinessOriginType;
use App\Domain\Businesses\Enums\BusinessStage;
use App\Domain\Governance\Enums\ProposalReviewOutcome;
use App\Domain\Governance\ValueObjects\DecisionType;
use App\Domain\Identity\Enums\AccountStatus;
use App\Infrastructure\Persistence\Eloquent\Businesses\Business;
use App\Infrastructure\Persistence\Eloquent\Governance\ProposalReview;
use App\Infrastructure\Persistence\Eloquent\Identity\User;
use App\Infrastructure\Persistence\Eloquent\Members\Membership;
use App\Infrastructure\Persistence\Eloquent\Records\Proposal;
use App\Infrastructure\Persistence\Eloquent\Records\ProposalVersion;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Tests\TestCase;

final class F3ProposalReviewBehaviorTest extends TestCase
{
    use RefreshDatabase;

    public function test_decision_cannot_open_without_approved_review_of_exact_frozen_proposal_version(): void
    {
        $context = $this->proposalContext();

        $this->expectException(RuntimeException::class);

        $this->app->make(OpenGovernanceDecision::class)->execute(
            $context['user'],
            $context['business'],
            (string) $context['version']->getKey(),
            new DecisionType('f3_proposal_review_test'),
        );
    }

    public function test_changes_requested_review_blocks_decision_and_completed_review_is_immutable(): void
    {
        $context = $this->proposalContext();

        $review = $this->app->make(CreateProposalReview::class)->execute(
            $context['user'],
            $context['business'],
            (string) $context['version']->getKey(),
            (string) $context['membership']->getKey(),
        );

        $this->assertInstanceOf(ProposalReview::class, $review);

        $completed = $this->app->make(CompleteProposalReview::class)->execute(
            $context['user'],
            $context['business'],
            (string) $review->getKey(),
            ProposalReviewOutcome::ChangesRequested,
            'Revise the frozen proposal and create a new version.',
        );

        $this->assertNotNull($completed);
        $this->assertSame('completed', $completed->status);
        $this->assertSame(
            ProposalReviewOutcome::ChangesRequested,
            $completed->outcome,
        );

        try {
            $this->app->make(OpenGovernanceDecision::class)->execute(
                $context['user'],
                $context['business'],
                (string) $context['version']->getKey(),
                new DecisionType('f3_proposal_review_test'),
            );

            $this->fail(
                'Changes Requested must not satisfy the Proposal Review gate.',
            );
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString(
                'Approved Review',
                $exception->getMessage(),
            );
        }

        $this->assertDatabaseRejects(function () use ($review): void {
            DB::table('proposal_reviews')
                ->where('id', $review->getKey())
                ->update([
                    'outcome' => 'approved',
                ]);
        });
    }

    public function test_approval_of_version_one_does_not_approve_version_two(): void
    {
        $context = $this->proposalContext();

        $review = $this->app->make(CreateProposalReview::class)->execute(
            $context['user'],
            $context['business'],
            (string) $context['version']->getKey(),
            (string) $context['membership']->getKey(),
        );

        $this->assertNotNull($review);

        $approved = $this->app->make(CompleteProposalReview::class)->execute(
            $context['user'],
            $context['business'],
            (string) $review->getKey(),
            ProposalReviewOutcome::Approved,
            'Version 1 approved.',
        );

        $this->assertNotNull($approved);

        $proposal = $context['proposal'];

        $proposal->fill([
            'revision' => 2,
            'content_hash' => str_repeat('3', 64),
            'last_changed_by_user_id' => $context['user']->getKey(),
        ])->save();

        $versionTwo = ProposalVersion::query()->create([
            'business_id' => $context['business']->getKey(),
            'proposal_id' => $proposal->getKey(),
            'version_number' => 2,
            'proposal_revision' => 2,
            'proposal_content_hash' => $proposal->content_hash,
            'snapshot_hash' => str_repeat('4', 64),
            'frozen_by_user_id' => $context['user']->getKey(),
            'frozen_at' => now(),
        ]);

        try {
            $this->app->make(OpenGovernanceDecision::class)->execute(
                $context['user'],
                $context['business'],
                (string) $versionTwo->getKey(),
                new DecisionType('f3_proposal_review_test'),
            );

            $this->fail(
                'Review of Proposal Version 1 must not authorize Version 2.',
            );
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString(
                'exact Frozen Proposal Version',
                $exception->getMessage(),
            );
        }
    }

    /**
     * @return array{
     *   user: User,
     *   business: Business,
     *   membership: Membership,
     *   proposal: Proposal,
     *   version: ProposalVersion
     * }
     */
    private function proposalContext(): array
    {
        $user = User::query()->create([
            'email' => 'proposal-review-'.str()->uuid().'@example.test',
            'password' => 'not-a-real-hash',
            'status' => AccountStatus::Active,
            'password_changed_at' => now(),
        ]);

        $business = $this->app->make(CreateBusiness::class)->handle(
            $user,
            'F3 Proposal Review',
            BusinessOriginType::StartedThroughPbr,
            BusinessStage::Planning,
            'USD',
        );

        $membership = Membership::query()
            ->where('business_id', $business->getKey())
            ->where('user_id', $user->getKey())
            ->firstOrFail();

        $proposal = Proposal::query()->create([
            'business_id' => $business->getKey(),
            'revision' => 1,
            'content_hash' => str_repeat('1', 64),
            'created_by_user_id' => $user->getKey(),
            'last_changed_by_user_id' => $user->getKey(),
        ]);

        $version = ProposalVersion::query()->create([
            'business_id' => $business->getKey(),
            'proposal_id' => $proposal->getKey(),
            'version_number' => 1,
            'proposal_revision' => 1,
            'proposal_content_hash' => $proposal->content_hash,
            'snapshot_hash' => str_repeat('2', 64),
            'frozen_by_user_id' => $user->getKey(),
            'frozen_at' => now(),
        ]);

        return [
            'user' => $user,
            'business' => $business,
            'membership' => $membership,
            'proposal' => $proposal,
            'version' => $version,
        ];
    }

    private function assertDatabaseRejects(callable $callback): void
    {
        DB::beginTransaction();

        try {
            $callback();
            DB::rollBack();

            $this->fail(
                'Expected PostgreSQL to reject mutation of completed Proposal Review history.',
            );
        } catch (QueryException) {
            if (DB::transactionLevel() > 0) {
                DB::rollBack();
            }

            $this->addToAssertionCount(1);
        }
    }
}
