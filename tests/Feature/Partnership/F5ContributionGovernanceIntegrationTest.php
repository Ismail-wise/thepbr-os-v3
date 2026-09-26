<?php

declare(strict_types=1);

namespace Tests\Feature\Partnership;

use App\Application\Evidence\LinkEvidence;
use App\Application\Governance\OpenGovernanceDecision;
use App\Application\Governance\RecordGovernanceApproval;
use App\Application\Governance\ResolveGovernanceDecision;
use App\Application\Partnership\ContributionWorkflow;
use App\Domain\Access\CapabilityCatalog;
use App\Domain\Documents\Enums\DocumentAccessRight;
use App\Domain\Documents\Enums\DocumentCategory;
use App\Domain\Evidence\Enums\EvidenceConfidentiality;
use App\Domain\Governance\Enums\ApprovalOutcome;
use App\Domain\Governance\Enums\DecisionMethod;
use App\Domain\Governance\ValueObjects\DecisionType;
use App\Domain\Partnership\Enums\ContributionType;
use App\Domain\Partnership\ValueObjects\ContributionValue;
use App\Infrastructure\Persistence\Eloquent\Access\AccessPolicy;
use App\Infrastructure\Persistence\Eloquent\Access\Permission;
use App\Infrastructure\Persistence\Eloquent\Access\PermissionGrant;
use App\Infrastructure\Persistence\Eloquent\Businesses\Business;
use App\Infrastructure\Persistence\Eloquent\Documents\Document;
use App\Infrastructure\Persistence\Eloquent\Documents\DocumentAccessGrant;
use App\Infrastructure\Persistence\Eloquent\Documents\DocumentVersion;
use App\Infrastructure\Persistence\Eloquent\Evidence\Evidence;
use App\Infrastructure\Persistence\Eloquent\Governance\Decision;
use App\Infrastructure\Persistence\Eloquent\Governance\FormationAuthorityEstablishment;
use App\Infrastructure\Persistence\Eloquent\Governance\FormationAuthorityPolicyActor;
use App\Infrastructure\Persistence\Eloquent\Governance\FormationAuthorityPolicyRule;
use App\Infrastructure\Persistence\Eloquent\Governance\ProposalReview;
use App\Infrastructure\Persistence\Eloquent\Identity\User;
use App\Infrastructure\Persistence\Eloquent\Members\Membership;
use App\Infrastructure\Persistence\Eloquent\Records\FormalRecordFamily;
use App\Infrastructure\Persistence\Eloquent\Records\FormalRecordVersion;
use App\Infrastructure\Persistence\Eloquent\Records\Proposal;
use App\Infrastructure\Persistence\Eloquent\Records\ProposalVersion;
use App\Infrastructure\Persistence\Eloquent\Records\RecordVersionStateTransition;
use DateTimeImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;
use Tests\TestCase;

final class F5ContributionGovernanceIntegrationTest extends TestCase
{
    use RefreshDatabase;

    public function test_contribution_evidence_requires_records_document_and_contribution_authority(): void
    {
        [$creator, $business, $creatorMembership] =
            $this->businessContext('evidence-creator');

        $this->grant(
            $business,
            $creatorMembership,
            CapabilityCatalog::CONTRIBUTIONS_MANAGE,
        );

        $partnerId = $this->partner($business);

        $workflow = $this->app->make(
            ContributionWorkflow::class,
        );

        $contribution = $workflow->create(
            $creator,
            $business,
            $partnerId,
            ContributionType::Cash,
            'USD',
            'Permission-boundary Contribution',
            new ContributionValue('1000.00'),
            null,
            null,
            null,
            [
                'amount_committed' => '1000.00',
                'amount_received' => '0.00',
                'payment_date' => null,
            ],
        );

        self::assertNotNull($contribution);

        [$document, $evidence] = $this->evidenceFixture(
            $business,
            $creatorMembership,
            'permission-boundary',
        );

        [$restrictedUser, $restrictedMembership] =
            $this->userMembership(
                $business,
                'evidence-restricted',
            );

        $this->grant(
            $business,
            $restrictedMembership,
            CapabilityCatalog::RECORDS_MANAGE,
        );

        $this->allowDocumentManage(
            $business,
            $restrictedMembership,
            $document,
        );

        $linker = $this->app->make(LinkEvidence::class);

        $denied = $linker->execute(
            $restrictedUser,
            $business,
            (string) $evidence->getKey(),
            'contribution',
            $contribution['id'],
        );

        self::assertNull($denied);
        $this->assertDatabaseCount('evidence_links', 0);
        self::assertSame(
            1,
            $this->contributionRevision($contribution['id']),
        );

        $this->grant(
            $business,
            $restrictedMembership,
            CapabilityCatalog::CONTRIBUTIONS_MANAGE,
        );

        $allowed = $linker->execute(
            $restrictedUser,
            $business,
            (string) $evidence->getKey(),
            'contribution',
            $contribution['id'],
        );

        self::assertNotNull($allowed);
        $this->assertDatabaseCount('evidence_links', 1);
        self::assertSame(
            2,
            $this->contributionRevision($contribution['id']),
        );
    }

    public function test_frozen_governance_submission_rejects_later_contribution_detail_change(): void
    {
        [$user, $business, $membership] =
            $this->governanceContext('stale');

        $this->establishFormationAuthority(
            $business,
            $user,
            $membership,
            ['contribution_approval'],
        );

        $contributionId = $this->reviewedContributionWithEvidence(
            $user,
            $business,
            $membership,
            'Frozen stale Contribution',
        );

        $workflow = $this->app->make(
            ContributionWorkflow::class,
        );

        $submission = $workflow->submitGovernance(
            $user,
            $business,
            $contributionId,
            'approval',
        );

        self::assertNotNull($submission);

        $this->approveProposalVersion(
            $business,
            $membership,
            $submission['proposal_version_id'],
        );

        $this->approveGovernanceDecision(
            $user,
            $business,
            $submission['proposal_version_id'],
            'contribution_approval',
        );

        $submissionHash = DB::table(
            'contribution_governance_submissions',
        )
            ->where('id', $submission['id'])
            ->value('content_hash');

        $revisionBeforeMutation =
            $this->contributionRevision($contributionId);

        DB::table('cash_contribution_details')
            ->where('contribution_id', $contributionId)
            ->update([
                'amount_received' => '100.00',
            ]);

        self::assertSame(
            $revisionBeforeMutation + 1,
            $this->contributionRevision($contributionId),
        );

        try {
            $workflow->syncGovernanceDecision(
                $user,
                $business,
                $submission['id'],
            );

            self::fail(
                'A frozen Contribution submission must not apply after its source changes.',
            );
        } catch (RuntimeException $exception) {
            self::assertStringContainsString(
                'changed after its frozen governance submission',
                $exception->getMessage(),
            );
        }

        $this->assertDatabaseHas('contributions', [
            'id' => $contributionId,
            'status' => 'reviewed',
            'approval_decision_id' => null,
            'approved_value' => null,
        ]);

        self::assertSame(
            $submissionHash,
            DB::table('contribution_governance_submissions')
                ->where('id', $submission['id'])
                ->value('content_hash'),
        );
    }

    public function test_real_f3_governance_drives_contribution_from_review_to_accepted_truth(): void
    {
        [$user, $business, $membership] =
            $this->governanceContext('lifecycle');

        $this->establishFormationAuthority(
            $business,
            $user,
            $membership,
            [
                'contribution_approval',
                'contribution_acceptance',
            ],
        );

        $contributionId = $this->reviewedContributionWithEvidence(
            $user,
            $business,
            $membership,
            'Governed Contribution',
        );

        $workflow = $this->app->make(
            ContributionWorkflow::class,
        );

        self::assertSame(
            [],
            $workflow->acceptedValues(
                $user,
                $business,
            ),
        );

        $approvalSubmission = $workflow->submitGovernance(
            $user,
            $business,
            $contributionId,
            'approval',
        );

        self::assertNotNull($approvalSubmission);

        $this->approveProposalVersion(
            $business,
            $membership,
            $approvalSubmission['proposal_version_id'],
        );

        $approvalDecision = $this->approveGovernanceDecision(
            $user,
            $business,
            $approvalSubmission['proposal_version_id'],
            'contribution_approval',
        );

        self::assertTrue(
            $workflow->syncGovernanceDecision(
                $user,
                $business,
                $approvalSubmission['id'],
            ),
        );

        $this->assertDatabaseHas('contributions', [
            'id' => $contributionId,
            'status' => 'approved',
            'reviewed_value' => '900.00',
            'approved_value' => '900.00',
            'approval_decision_id' => $approvalDecision->getKey(),
            'accepted_value' => null,
        ]);

        self::assertSame(
            [],
            $workflow->acceptedValues(
                $user,
                $business,
            ),
        );

        $deliveryId = $workflow->recordDelivery(
            $user,
            $business,
            $contributionId,
            $this->contributionRevision($contributionId),
            new ContributionValue('900.00'),
            new DateTimeImmutable(
                '2026-09-26T09:30:00+00:00',
            ),
            'Full approved value delivered.',
            true,
        );

        self::assertNotNull($deliveryId);

        $this->assertDatabaseHas('contributions', [
            'id' => $contributionId,
            'status' => 'delivered',
            'accepted_value' => null,
        ]);

        $acceptanceSubmission = $workflow->submitGovernance(
            $user,
            $business,
            $contributionId,
            'acceptance',
            new ContributionValue('850.00'),
        );

        self::assertNotNull($acceptanceSubmission);

        $this->approveProposalVersion(
            $business,
            $membership,
            $acceptanceSubmission['proposal_version_id'],
        );

        $acceptanceDecision = $this->approveGovernanceDecision(
            $user,
            $business,
            $acceptanceSubmission['proposal_version_id'],
            'contribution_acceptance',
        );

        self::assertTrue(
            $workflow->syncGovernanceDecision(
                $user,
                $business,
                $acceptanceSubmission['id'],
            ),
        );

        $this->assertDatabaseHas('contributions', [
            'id' => $contributionId,
            'status' => 'accepted',
            'approved_value' => '900.00',
            'accepted_value' => '850.00',
            'acceptance_decision_id' => $acceptanceDecision->getKey(),
        ]);

        $accepted = $workflow->acceptedValues(
            $user,
            $business,
        );

        self::assertCount(1, $accepted);
        self::assertSame(
            $contributionId,
            $accepted[0]['contribution_id'],
        );
        self::assertSame(
            'USD',
            $accepted[0]['currency'],
        );
        self::assertSame(
            '850.00',
            $accepted[0]['accepted_value'],
        );
    }

    /**
     * @return array{User, Business, Membership}
     */
    private function businessContext(
        string $prefix,
    ): array {
        $business = Business::query()->create([
            'name' => 'F5 Integration '.Str::uuid7(),
            'origin_type' => 'started_through_pbr',
            'business_stage' => 'planning',
            'setup_phase' => 'formation',
            'workspace_status' => 'active',
            'base_currency' => 'USD',
        ]);

        [$user, $membership] = $this->userMembership(
            $business,
            $prefix,
        );

        return [
            $user,
            $business,
            $membership,
        ];
    }

    /**
     * @return array{User, Business, Membership}
     */
    private function governanceContext(
        string $prefix,
    ): array {
        [$user, $business, $membership] =
            $this->businessContext($prefix);

        $this->grant(
            $business,
            $membership,
            CapabilityCatalog::RECORDS_MANAGE,
            [
                FormalRecordFamily::class,
                FormalRecordVersion::class,
                Proposal::class,
                ProposalVersion::class,
            ],
        );

        $this->grant(
            $business,
            $membership,
            CapabilityCatalog::GOVERNANCE_RECORDS_MANAGE,
            [
                ProposalVersion::class,
                Decision::class,
            ],
        );

        $this->grant(
            $business,
            $membership,
            CapabilityCatalog::CONTRIBUTIONS_MANAGE,
        );

        $this->grant(
            $business,
            $membership,
            CapabilityCatalog::CONTRIBUTIONS_VIEW,
        );

        return [
            $user,
            $business,
            $membership,
        ];
    }

    /**
     * @return array{User, Membership}
     */
    private function userMembership(
        Business $business,
        string $prefix,
    ): array {
        $user = User::query()->create([
            'email' => $prefix.'-'.Str::uuid7().'@example.test',
            'password' => 'not-a-real-hash',
            'status' => 'active',
            'password_changed_at' => now(),
        ]);

        $membership = Membership::query()->create([
            'user_id' => $user->getKey(),
            'business_id' => $business->getKey(),
            'access_status' => 'active',
        ]);

        return [
            $user,
            $membership,
        ];
    }

    private function partner(
        Business $business,
    ): string {
        $id = (string) Str::uuid7();

        DB::table('partners')->insert([
            'id' => $id,
            'business_id' => $business->getKey(),
            'display_name' => 'Integration Partner',
            'legal_name' => null,
            'email' => null,
            'status' => 'prospective',
            'notes' => null,
            'revision' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $id;
    }

    private function reviewedContributionWithEvidence(
        User $user,
        Business $business,
        Membership $membership,
        string $description,
    ): string {
        $workflow = $this->app->make(
            ContributionWorkflow::class,
        );

        $contribution = $workflow->create(
            $user,
            $business,
            $this->partner($business),
            ContributionType::Cash,
            'USD',
            $description,
            new ContributionValue('1000.00'),
            'Evidence and governance required.',
            '2026-09-26',
            '2026-10-10',
            [
                'amount_committed' => '1000.00',
                'amount_received' => '0.00',
                'payment_date' => null,
            ],
        );

        self::assertNotNull($contribution);

        [$document, $evidence] = $this->evidenceFixture(
            $business,
            $membership,
            Str::slug($description),
        );

        $this->allowDocumentManage(
            $business,
            $membership,
            $document,
        );

        $link = $this->app
            ->make(LinkEvidence::class)
            ->execute(
                $user,
                $business,
                (string) $evidence->getKey(),
                'contribution',
                $contribution['id'],
            );

        self::assertNotNull($link);

        self::assertTrue(
            $workflow->review(
                $user,
                $business,
                $contribution['id'],
                $this->contributionRevision(
                    $contribution['id'],
                ),
                new ContributionValue('900.00'),
                'Verified Contribution evidence and valuation.',
            ),
        );

        return $contribution['id'];
    }

    /**
     * @return array{Document, Evidence}
     */
    private function evidenceFixture(
        Business $business,
        Membership $membership,
        string $label,
    ): array {
        $document = Document::query()->create([
            'business_id' => $business->getKey(),
            'title' => 'F5 Evidence '.$label,
            'category' => DocumentCategory::CorporateLegal->value,
            'created_by_membership_id' => $membership->getKey(),
        ]);

        $version = DocumentVersion::query()->create([
            'business_id' => $business->getKey(),
            'document_id' => $document->getKey(),
            'version_number' => 1,
            'original_filename' => $label.'.pdf',
            'storage_key' => 'documents/f5/'
                .Str::uuid7()
                .'/source-v1.pdf',
            'size_bytes' => 2048,
            'mime_type' => 'application/pdf',
            'content_sha256' => hash(
                'sha256',
                $label.'-'.Str::uuid7(),
            ),
            'uploaded_by_membership_id' => $membership->getKey(),
            'effective_from' => null,
            'supersedes_document_version_id' => null,
        ]);

        $evidence = Evidence::query()->create([
            'business_id' => $business->getKey(),
            'document_version_id' => $version->getKey(),
            'confidentiality' => EvidenceConfidentiality::Standard->value,
            'source_date' => '2026-09-26',
            'submitted_by_membership_id' => $membership->getKey(),
            'verified_at' => now(),
            'verified_by_membership_id' => $membership->getKey(),
            'verification_method' => 'manual_review',
            'verification_note' => 'Verified F5 integration evidence.',
        ]);

        return [
            $document,
            $evidence,
        ];
    }

    private function allowDocumentManage(
        Business $business,
        Membership $membership,
        Document $document,
    ): void {
        DocumentAccessGrant::query()->create([
            'business_id' => $business->getKey(),
            'membership_id' => $membership->getKey(),
            'document_id' => $document->getKey(),
            'right' => DocumentAccessRight::Manage->value,
            'effect' => 'allow',
        ]);
    }

    /**
     * @param  list<class-string>  $resourceTypes
     */
    private function grant(
        Business $business,
        Membership $membership,
        string $capability,
        array $resourceTypes = [],
    ): void {
        $permission = Permission::query()->firstOrCreate([
            'key' => $capability,
        ]);

        PermissionGrant::query()->firstOrCreate([
            'business_id' => $business->getKey(),
            'membership_id' => $membership->getKey(),
            'permission_id' => $permission->getKey(),
        ], [
            'effect' => 'allow',
        ]);

        foreach ($resourceTypes as $resourceType) {
            AccessPolicy::query()->firstOrCreate([
                'business_id' => $business->getKey(),
                'membership_id' => $membership->getKey(),
                'permission_profile_id' => null,
                'permission_id' => $permission->getKey(),
                'resource_type' => $resourceType,
            ], [
                'effect' => 'allow',
            ]);
        }
    }

    /**
     * @param  list<string>  $decisionTypes
     */
    private function establishFormationAuthority(
        Business $business,
        User $user,
        Membership $membership,
        array $decisionTypes,
    ): void {
        $family = FormalRecordFamily::query()->create([
            'business_id' => $business->getKey(),
            'record_type' => 'formation_authority_policy',
            'subject_type' => 'business',
            'subject_id' => (string) $business->getKey(),
        ]);

        $contentHash = hash(
            'sha256',
            'f5-formation-authority-'
            .$business->getKey(),
        );

        $version = FormalRecordVersion::query()->create([
            'business_id' => $business->getKey(),
            'formal_record_family_id' => $family->getKey(),
            'version_number' => 1,
            'predecessor_version_id' => null,
            'revision' => 1,
            'change_summary' => 'F5 temporary Formation Authority.',
            'created_by_user_id' => $user->getKey(),
            'last_changed_by_user_id' => $user->getKey(),
            'effective_from' => now()->subMinute(),
            'effective_until' => null,
            'review_due_at' => null,
            'content_hash' => $contentHash,
            'frozen_at' => null,
        ]);

        RecordVersionStateTransition::query()->create([
            'business_id' => $business->getKey(),
            'formal_record_version_id' => $version->getKey(),
            'sequence' => 1,
            'from_state' => null,
            'to_state' => 'draft',
            'transitioned_by_user_id' => $user->getKey(),
            'occurred_at' => now()->subSeconds(10),
        ]);

        foreach (
            array_values($decisionTypes) as $index => $decisionType
        ) {
            $rule = FormationAuthorityPolicyRule::query()
                ->create([
                    'business_id' => $business->getKey(),
                    'formal_record_version_id' => $version->getKey(),
                    'sequence' => $index + 1,
                    'decision_type' => $decisionType,
                    'decision_method' => DecisionMethod::Approval->value,
                    'required_approvals' => 1,
                    'required_votes' => 0,
                    'quorum_count' => 1,
                    'signature_required' => false,
                    'reserved_matter' => false,
                    'amount_min' => null,
                    'amount_max' => null,
                ]);

            FormationAuthorityPolicyActor::query()
                ->create([
                    'business_id' => $business->getKey(),
                    'formation_authority_policy_rule_id' => $rule->getKey(),
                    'membership_id' => $membership->getKey(),
                    'capacity' => 'Formation Approver',
                    'can_approve' => true,
                    'can_vote' => false,
                    'can_sign' => false,
                ]);
        }

        $version->frozen_at = now()->subSeconds(5);
        $version->save();

        RecordVersionStateTransition::query()->create([
            'business_id' => $business->getKey(),
            'formal_record_version_id' => $version->getKey(),
            'sequence' => 2,
            'from_state' => 'draft',
            'to_state' => 'ready_for_review',
            'transitioned_by_user_id' => $user->getKey(),
            'occurred_at' => now(),
        ]);

        FormationAuthorityEstablishment::query()->create([
            'business_id' => $business->getKey(),
            'formal_record_version_id' => $version->getKey(),
            'established_by_membership_id' => $membership->getKey(),
            'establishment_hash' => $contentHash,
            'established_at' => now(),
        ]);
    }

    private function approveProposalVersion(
        Business $business,
        Membership $membership,
        string $proposalVersionId,
    ): void {
        $review = ProposalReview::query()->create([
            'business_id' => $business->getKey(),
            'proposal_version_id' => $proposalVersionId,
            'reviewer_membership_id' => $membership->getKey(),
            'created_by_membership_id' => $membership->getKey(),
            'status' => 'open',
            'outcome' => null,
            'notes' => null,
            'due_at' => null,
            'resolved_at' => null,
        ]);

        $review->fill([
            'status' => 'completed',
            'outcome' => 'approved',
            'notes' => 'Approved exact frozen F5 Proposal Version.',
            'resolved_at' => now(),
        ])->save();
    }

    private function approveGovernanceDecision(
        User $user,
        Business $business,
        string $proposalVersionId,
        string $decisionType,
    ): Decision {
        $decision = $this->app
            ->make(OpenGovernanceDecision::class)
            ->execute(
                $user,
                $business,
                $proposalVersionId,
                new DecisionType($decisionType),
            );

        self::assertInstanceOf(
            Decision::class,
            $decision,
        );

        $approval = $this->app
            ->make(RecordGovernanceApproval::class)
            ->execute(
                $user,
                $business,
                (string) $decision->getKey(),
                ApprovalOutcome::Approved,
                'Exact frozen F5 proposal approved.',
            );

        self::assertNotNull($approval);

        $resolved = $this->app
            ->make(ResolveGovernanceDecision::class)
            ->approve(
                $user,
                $business,
                (string) $decision->getKey(),
            );

        self::assertNotNull($resolved);

        $this->assertDatabaseHas('decisions', [
            'id' => $decision->getKey(),
            'proposal_version_id' => $proposalVersionId,
            'decision_type' => $decisionType,
            'status' => 'decided',
            'outcome' => 'approved',
        ]);

        return $decision;
    }

    private function contributionRevision(
        string $contributionId,
    ): int {
        return (int) DB::table('contributions')
            ->where('id', $contributionId)
            ->value('revision');
    }
}
