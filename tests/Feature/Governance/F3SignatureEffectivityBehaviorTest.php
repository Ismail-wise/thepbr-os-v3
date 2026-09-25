<?php

declare(strict_types=1);

namespace Tests\Feature\Governance;

use App\Application\Governance\CompleteSignatureRequest;
use App\Application\Governance\CreateSignatureRequest;
use App\Application\Governance\MakeGovernedRecordEffective;
use App\Application\Governance\OpenGovernanceDecision;
use App\Application\Governance\PrepareGovernedRecordForEffect;
use App\Application\Governance\RecordGovernanceApproval;
use App\Application\Governance\ResolveGovernanceDecision;
use App\Application\Governance\SendSignatureRequest;
use App\Application\Governance\SignGovernanceDocument;
use App\Domain\Access\CapabilityCatalog;
use App\Domain\Governance\Enums\ApprovalOutcome;
use App\Domain\Governance\Enums\DecisionMethod;
use App\Domain\Governance\Enums\SignatureRequestStatus;
use App\Domain\Governance\ValueObjects\DecisionType;
use App\Domain\Records\Exceptions\InvalidWorkflowTransition;
use App\Infrastructure\Persistence\Eloquent\Access\AccessPolicy;
use App\Infrastructure\Persistence\Eloquent\Access\Permission;
use App\Infrastructure\Persistence\Eloquent\Access\PermissionGrant;
use App\Infrastructure\Persistence\Eloquent\Businesses\Business;
use App\Infrastructure\Persistence\Eloquent\Documents\Document;
use App\Infrastructure\Persistence\Eloquent\Documents\DocumentAccessGrant;
use App\Infrastructure\Persistence\Eloquent\Documents\DocumentVersion;
use App\Infrastructure\Persistence\Eloquent\Governance\Decision;
use App\Infrastructure\Persistence\Eloquent\Governance\FormationAuthorityEstablishment;
use App\Infrastructure\Persistence\Eloquent\Governance\FormationAuthorityPolicyActor;
use App\Infrastructure\Persistence\Eloquent\Governance\FormationAuthorityPolicyRule;
use App\Infrastructure\Persistence\Eloquent\Governance\Signature;
use App\Infrastructure\Persistence\Eloquent\Governance\SignatureParticipant;
use App\Infrastructure\Persistence\Eloquent\Governance\SignatureRequest;
use App\Infrastructure\Persistence\Eloquent\Identity\User;
use App\Infrastructure\Persistence\Eloquent\Members\Membership;
use App\Infrastructure\Persistence\Eloquent\Records\FormalRecordFamily;
use App\Infrastructure\Persistence\Eloquent\Records\FormalRecordVersion;
use App\Infrastructure\Persistence\Eloquent\Records\Proposal;
use App\Infrastructure\Persistence\Eloquent\Records\ProposalVersion;
use App\Infrastructure\Persistence\Eloquent\Records\ProposalVersionRecord;
use App\Infrastructure\Persistence\Eloquent\Records\RecordVersionStateTransition;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Tests\TestCase;

final class F3SignatureEffectivityBehaviorTest extends TestCase
{
    use RefreshDatabase;

    public function test_signature_cannot_be_made_for_another_signer_even_with_system_permission(): void
    {
        $context = $this->signatureContext(false);

        $request = $this->createAndSendRequest($context, $context['documentV1']);

        $otherSignature = $this->app->make(SignGovernanceDocument::class)->execute(
            $context['otherUser'],
            $context['business'],
            (string) $request->getKey(),
            'in_app',
            'I consent to sign this exact document version.',
            str_repeat('a', 64),
        );

        $this->assertNull($otherSignature);
        $this->assertDatabaseCount('signatures', 0);
        $this->assertSame(
            SignatureRequestStatus::Sent,
            $request->fresh()->status,
        );

        $signature = $this->app->make(SignGovernanceDocument::class)->execute(
            $context['signerUser'],
            $context['business'],
            (string) $request->getKey(),
            'in_app',
            'I consent to sign this exact document version.',
            str_repeat('b', 64),
        );

        $this->assertInstanceOf(Signature::class, $signature);
        $this->assertSame(
            (string) $context['signerMembership']->getKey(),
            (string) $signature->membership_id,
        );
        $this->assertSame(
            (string) $context['documentV1']->getKey(),
            (string) $signature->document_version_id,
        );
    }

    public function test_document_v1_signature_cannot_be_reused_as_document_v2_signature(): void
    {
        $context = $this->signatureContext(false);

        $requestV1 = $this->createAndSendRequest($context, $context['documentV1']);

        $participant = SignatureParticipant::query()
            ->where('signature_request_id', $requestV1->getKey())
            ->where('membership_id', $context['signerMembership']->getKey())
            ->firstOrFail();

        $this->assertDatabaseRejects(function () use ($context, $requestV1, $participant): void {
            Signature::query()->create([
                'business_id' => $context['business']->getKey(),
                'signature_request_id' => $requestV1->getKey(),
                'signature_participant_id' => $participant->getKey(),
                'membership_id' => $context['signerMembership']->getKey(),
                'document_version_id' => $context['documentV2']->getKey(),
                'document_content_sha256' => $context['documentV2']->content_sha256,
                'signature_method' => 'in_app',
                'consent_statement' => 'Invalid attempt to reuse v1 request for v2.',
                'signing_session_hash' => str_repeat('c', 64),
                'signed_at' => now(),
            ]);
        });

        $this->assertDatabaseCount('signatures', 0);

        $signatureV1 = $this->app->make(SignGovernanceDocument::class)->execute(
            $context['signerUser'],
            $context['business'],
            (string) $requestV1->getKey(),
            'in_app',
            'I consent to sign Document v1.',
            str_repeat('d', 64),
        );

        $this->assertInstanceOf(Signature::class, $signatureV1);
        $this->assertSame(
            (string) $context['documentV1']->getKey(),
            (string) $signatureV1->document_version_id,
        );
        $this->assertSame(
            (string) $context['documentV1']->content_sha256,
            (string) $signatureV1->document_content_sha256,
        );

        $completedV1 = $this->app->make(CompleteSignatureRequest::class)->execute(
            $context['manager'],
            $context['business'],
            (string) $requestV1->getKey(),
        );

        $this->assertNotNull($completedV1);
        $this->assertSame(
            SignatureRequestStatus::Completed,
            $completedV1->status,
        );

        $requestV2 = $this->app->make(CreateSignatureRequest::class)->execute(
            $context['manager'],
            $context['business'],
            (string) $context['decision']->getKey(),
            (string) $context['documentV2']->getKey(),
        );

        $this->assertInstanceOf(SignatureRequest::class, $requestV2);
        $this->assertSame(
            SignatureRequestStatus::Draft,
            $requestV2->status,
        );
        $this->assertNotSame(
            (string) $requestV1->getKey(),
            (string) $requestV2->getKey(),
        );
        $this->assertSame(
            (string) $context['documentV2']->getKey(),
            (string) $requestV2->document_version_id,
        );
        $this->assertDatabaseCount('signatures', 1);
    }

    public function test_future_effective_date_remains_ready_for_effect_after_completed_signature(): void
    {
        $context = $this->signatureContext(true);

        $request = $this->createAndSendRequest($context, $context['documentV1']);

        $signature = $this->app->make(SignGovernanceDocument::class)->execute(
            $context['signerUser'],
            $context['business'],
            (string) $request->getKey(),
            'in_app',
            'I consent to sign this exact document version.',
            str_repeat('e', 64),
        );

        $this->assertInstanceOf(Signature::class, $signature);

        $completed = $this->app->make(CompleteSignatureRequest::class)->execute(
            $context['manager'],
            $context['business'],
            (string) $request->getKey(),
        );

        $this->assertNotNull($completed);
        $this->assertSame(SignatureRequestStatus::Completed, $completed->status);

        $prepared = $this->app->make(PrepareGovernedRecordForEffect::class)->execute(
            $context['manager'],
            $context['business'],
            (string) $context['decision']->getKey(),
            (string) $context['governedRecord']->getKey(),
        );

        $this->assertTrue($prepared);
        $this->assertSame(
            'ready_for_effect',
            $this->latestState($context['governedRecord']),
        );

        try {
            $this->app->make(MakeGovernedRecordEffective::class)->execute(
                $context['manager'],
                $context['business'],
                (string) $context['decision']->getKey(),
                (string) $context['governedRecord']->getKey(),
            );

            $this->fail('A future-effective governed record must not become Effective early.');
        } catch (InvalidWorkflowTransition $exception) {
            $this->assertStringContainsString(
                'future-effective version cannot become current early',
                $exception->getMessage(),
            );
        }

        $this->assertSame(
            'ready_for_effect',
            $this->latestState($context['governedRecord']),
        );
        $this->assertDatabaseMissing('record_family_effective_heads', [
            'business_id' => $context['business']->getKey(),
            'formal_record_version_id' => $context['governedRecord']->getKey(),
        ]);
    }

    /**
     * @return array{
     *   business: Business,
     *   manager: User,
     *   managerMembership: Membership,
     *   signerUser: User,
     *   signerMembership: Membership,
     *   otherUser: User,
     *   otherMembership: Membership,
     *   decision: Decision,
     *   governedRecord: FormalRecordVersion,
     *   documentV1: DocumentVersion,
     *   documentV2: DocumentVersion
     * }
     */
    private function signatureContext(bool $futureEffective): array
    {
        $business = Business::query()->create([
            'name' => 'F3 Signature '.Str::uuid7(),
            'origin_type' => 'started_through_pbr',
            'business_stage' => 'idea',
            'setup_phase' => 'formation',
            'workspace_status' => 'active',
            'base_currency' => 'USD',
        ]);

        [$manager, $managerMembership] = $this->userMembership($business, 'manager');
        [$signerUser, $signerMembership] = $this->userMembership($business, 'signer');
        [$otherUser, $otherMembership] = $this->userMembership($business, 'other');

        $this->grant(
            $business,
            $managerMembership,
            CapabilityCatalog::GOVERNANCE_RECORDS_MANAGE,
            [
                ProposalVersion::class,
                Decision::class,
                SignatureRequest::class,
                FormalRecordVersion::class,
            ],
        );

        $this->grant(
            $business,
            $managerMembership,
            'records.manage',
            [],
        );

        $this->grant(
            $business,
            $signerMembership,
            CapabilityCatalog::GOVERNANCE_RECORDS_MANAGE,
            [Decision::class],
        );

        $this->grant(
            $business,
            $signerMembership,
            CapabilityCatalog::GOVERNANCE_SIGNATURE_ACT,
            [SignatureRequest::class],
        );

        $this->grant(
            $business,
            $otherMembership,
            CapabilityCatalog::GOVERNANCE_SIGNATURE_ACT,
            [SignatureRequest::class],
        );

        $authorityFamily = FormalRecordFamily::query()->create([
            'business_id' => $business->getKey(),
            'record_type' => 'formation_authority_policy',
            'subject_type' => 'business',
            'subject_id' => (string) $business->getKey(),
        ]);

        $authorityVersion = FormalRecordVersion::query()->create([
            'business_id' => $business->getKey(),
            'formal_record_family_id' => $authorityFamily->getKey(),
            'version_number' => 1,
            'predecessor_version_id' => null,
            'revision' => 1,
            'change_summary' => 'Signature test Formation Authority',
            'created_by_user_id' => $manager->getKey(),
            'last_changed_by_user_id' => $manager->getKey(),
            'effective_from' => now()->subMinute(),
            'effective_until' => null,
            'review_due_at' => null,
            'content_hash' => str_repeat('1', 64),
            'frozen_at' => null,
        ]);

        RecordVersionStateTransition::query()->create([
            'business_id' => $business->getKey(),
            'formal_record_version_id' => $authorityVersion->getKey(),
            'sequence' => 1,
            'from_state' => null,
            'to_state' => 'draft',
            'transitioned_by_user_id' => $manager->getKey(),
            'occurred_at' => now()->subSeconds(10),
        ]);

        $rule = FormationAuthorityPolicyRule::query()->create([
            'business_id' => $business->getKey(),
            'formal_record_version_id' => $authorityVersion->getKey(),
            'sequence' => 1,
            'decision_type' => 'signature_effectivity_test',
            'decision_method' => DecisionMethod::Approval->value,
            'required_approvals' => 1,
            'required_votes' => 0,
            'quorum_count' => 1,
            'signature_required' => true,
            'reserved_matter' => false,
            'amount_min' => null,
            'amount_max' => null,
        ]);

        FormationAuthorityPolicyActor::query()->create([
            'business_id' => $business->getKey(),
            'formation_authority_policy_rule_id' => $rule->getKey(),
            'membership_id' => $signerMembership->getKey(),
            'capacity' => 'Formation Approver and Signer',
            'can_approve' => true,
            'can_vote' => false,
            'can_sign' => true,
        ]);

        $authorityVersion->frozen_at = now()->subSeconds(5);
        $authorityVersion->save();

        RecordVersionStateTransition::query()->create([
            'business_id' => $business->getKey(),
            'formal_record_version_id' => $authorityVersion->getKey(),
            'sequence' => 2,
            'from_state' => 'draft',
            'to_state' => 'ready_for_review',
            'transitioned_by_user_id' => $manager->getKey(),
            'occurred_at' => now(),
        ]);

        FormationAuthorityEstablishment::query()->create([
            'business_id' => $business->getKey(),
            'formal_record_version_id' => $authorityVersion->getKey(),
            'established_by_membership_id' => $managerMembership->getKey(),
            'establishment_hash' => $authorityVersion->content_hash,
            'established_at' => now(),
        ]);

        $governedFamily = FormalRecordFamily::query()->create([
            'business_id' => $business->getKey(),
            'record_type' => 'signature_effectivity_record',
            'subject_type' => 'business',
            'subject_id' => (string) $business->getKey(),
        ]);

        $governedRecord = FormalRecordVersion::query()->create([
            'business_id' => $business->getKey(),
            'formal_record_family_id' => $governedFamily->getKey(),
            'version_number' => 1,
            'predecessor_version_id' => null,
            'revision' => 1,
            'change_summary' => 'Governed record under signature test',
            'created_by_user_id' => $manager->getKey(),
            'last_changed_by_user_id' => $manager->getKey(),
            'effective_from' => $futureEffective
                ? now()->addDay()
                : now()->subMinute(),
            'effective_until' => null,
            'review_due_at' => null,
            'content_hash' => str_repeat('4', 64),
            'frozen_at' => now(),
        ]);

        $this->seedState($governedRecord, $manager, 1, null, 'draft');
        $this->seedState($governedRecord, $manager, 2, 'draft', 'ready_for_review');
        $this->seedState($governedRecord, $manager, 3, 'ready_for_review', 'under_review');
        $this->seedState($governedRecord, $manager, 4, 'under_review', 'approved');

        $proposal = Proposal::query()->create([
            'business_id' => $business->getKey(),
            'revision' => 1,
            'content_hash' => str_repeat('2', 64),
            'created_by_user_id' => $manager->getKey(),
            'last_changed_by_user_id' => $manager->getKey(),
        ]);

        $proposalVersion = ProposalVersion::query()->create([
            'business_id' => $business->getKey(),
            'proposal_id' => $proposal->getKey(),
            'version_number' => 1,
            'proposal_revision' => 1,
            'proposal_content_hash' => $proposal->content_hash,
            'snapshot_hash' => str_repeat('3', 64),
            'frozen_by_user_id' => $manager->getKey(),
            'frozen_at' => now(),
        ]);

        ProposalVersionRecord::query()->create([
            'business_id' => $business->getKey(),
            'proposal_version_id' => $proposalVersion->getKey(),
            'formal_record_version_id' => $governedRecord->getKey(),
            'captured_content_hash' => $governedRecord->content_hash,
        ]);

        $decision = $this->app->make(OpenGovernanceDecision::class)->execute(
            $manager,
            $business,
            (string) $proposalVersion->getKey(),
            new DecisionType('signature_effectivity_test'),
        );

        $this->assertInstanceOf(Decision::class, $decision);

        $approval = $this->app->make(RecordGovernanceApproval::class)->execute(
            $signerUser,
            $business,
            (string) $decision->getKey(),
            ApprovalOutcome::Approved,
            'Approved for signature behavior testing.',
        );

        $this->assertNotNull($approval);

        $resolved = $this->app->make(ResolveGovernanceDecision::class)->approve(
            $manager,
            $business,
            (string) $decision->getKey(),
        );

        $this->assertNotNull($resolved);

        $document = Document::query()->create([
            'business_id' => $business->getKey(),
            'title' => 'Governed Agreement',
            'category' => 'pbr_generated',
            'created_by_membership_id' => $managerMembership->getKey(),
        ]);

        DocumentAccessGrant::query()->create([
            'business_id' => $business->getKey(),
            'membership_id' => $managerMembership->getKey(),
            'document_id' => $document->getKey(),
            'right' => 'manage',
            'effect' => 'allow',
        ]);

        $documentV1 = DocumentVersion::query()->create([
            'business_id' => $business->getKey(),
            'document_id' => $document->getKey(),
            'version_number' => 1,
            'original_filename' => 'governed-agreement-v1.pdf',
            'storage_key' => 'tests/'.Str::uuid7().'/v1.pdf',
            'size_bytes' => 100,
            'mime_type' => 'application/pdf',
            'content_sha256' => str_repeat('5', 64),
            'uploaded_by_membership_id' => $managerMembership->getKey(),
            'effective_from' => null,
            'supersedes_document_version_id' => null,
        ]);

        $documentV2 = DocumentVersion::query()->create([
            'business_id' => $business->getKey(),
            'document_id' => $document->getKey(),
            'version_number' => 2,
            'original_filename' => 'governed-agreement-v2.pdf',
            'storage_key' => 'tests/'.Str::uuid7().'/v2.pdf',
            'size_bytes' => 101,
            'mime_type' => 'application/pdf',
            'content_sha256' => str_repeat('6', 64),
            'uploaded_by_membership_id' => $managerMembership->getKey(),
            'effective_from' => null,
            'supersedes_document_version_id' => $documentV1->getKey(),
        ]);

        return [
            'business' => $business,
            'manager' => $manager,
            'managerMembership' => $managerMembership,
            'signerUser' => $signerUser,
            'signerMembership' => $signerMembership,
            'otherUser' => $otherUser,
            'otherMembership' => $otherMembership,
            'decision' => $decision->fresh(),
            'governedRecord' => $governedRecord->fresh(),
            'documentV1' => $documentV1,
            'documentV2' => $documentV2,
        ];
    }

    private function createAndSendRequest(array $context, DocumentVersion $document): SignatureRequest
    {
        $request = $this->app->make(CreateSignatureRequest::class)->execute(
            $context['manager'],
            $context['business'],
            (string) $context['decision']->getKey(),
            (string) $document->getKey(),
        );

        $this->assertInstanceOf(SignatureRequest::class, $request);
        $this->assertSame(SignatureRequestStatus::Draft, $request->status);

        $sent = $this->app->make(SendSignatureRequest::class)->execute(
            $context['manager'],
            $context['business'],
            (string) $request->getKey(),
        );

        $this->assertNotNull($sent);
        $this->assertSame(SignatureRequestStatus::Sent, $sent->status);

        return $sent;
    }

    private function seedState(
        FormalRecordVersion $version,
        User $user,
        int $sequence,
        ?string $from,
        string $to,
    ): void {
        RecordVersionStateTransition::query()->create([
            'business_id' => $version->business_id,
            'formal_record_version_id' => $version->getKey(),
            'sequence' => $sequence,
            'from_state' => $from,
            'to_state' => $to,
            'transitioned_by_user_id' => $user->getKey(),
            'occurred_at' => now(),
        ]);
    }

    private function latestState(FormalRecordVersion $version): string
    {
        $latest = RecordVersionStateTransition::query()
            ->where('formal_record_version_id', $version->getKey())
            ->orderByDesc('sequence')
            ->firstOrFail();

        return $latest->to_state->value;
    }

    /** @return array{User, Membership} */
    private function userMembership(Business $business, string $prefix): array
    {
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

        return [$user, $membership];
    }

    /** @param list<class-string> $resourceTypes */
    private function grant(
        Business $business,
        Membership $membership,
        string $capability,
        array $resourceTypes,
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

    private function assertDatabaseRejects(callable $callback): void
    {
        DB::beginTransaction();

        try {
            $callback();
            DB::rollBack();
            $this->fail('Expected PostgreSQL to reject invalid signature evidence.');
        } catch (QueryException) {
            if (DB::transactionLevel() > 0) {
                DB::rollBack();
            }

            $this->addToAssertionCount(1);
        }
    }
}
