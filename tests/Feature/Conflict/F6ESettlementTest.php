<?php

declare(strict_types=1);

namespace Tests\Feature\Conflict;

require_once __DIR__.'/F6EConflictPolicyTest.php';

use App\Application\Conflict\ConflictCaseWorkflow;
use App\Application\Conflict\ConflictDirectDiscussionWorkflow;
use App\Application\Conflict\ConflictMediationWorkflow;
use App\Application\Conflict\ConflictSettlementWorkflow;
use App\Application\Governance\CompleteProposalReview;
use App\Application\Governance\CompleteSignatureRequest;
use App\Application\Governance\CreateProposalReview;
use App\Application\Governance\OpenGovernanceDecision;
use App\Application\Governance\RecordGovernanceApproval;
use App\Application\Governance\ResolveGovernanceDecision;
use App\Application\Governance\SendSignatureRequest;
use App\Application\Governance\SignGovernanceDocument;
use App\Domain\Conflict\Enums\ConflictCaseStage;
use App\Domain\Conflict\Enums\DirectDiscussionOutcome;
use App\Domain\Conflict\Enums\MediationResponseOutcome;
use App\Domain\Governance\Enums\ApprovalOutcome;
use App\Domain\Governance\Enums\ProposalReviewOutcome;
use App\Domain\Governance\Enums\SignatureRequestStatus;
use App\Domain\Governance\ValueObjects\DecisionType;
use App\Domain\Records\Enums\FormalRecordState;
use App\Infrastructure\Persistence\Eloquent\Conflict\ConflictCase;
use App\Infrastructure\Persistence\Eloquent\Documents\Document;
use App\Infrastructure\Persistence\Eloquent\Documents\DocumentAccessGrant;
use App\Infrastructure\Persistence\Eloquent\Documents\DocumentVersion;
use App\Infrastructure\Persistence\Eloquent\Governance\Decision;
use App\Infrastructure\Persistence\Eloquent\Governance\SignatureRequest;
use App\Infrastructure\Persistence\Eloquent\Records\RecordFamilyEffectiveHead;
use Illuminate\Support\Str;

final class F6ESettlementTest extends F6EConflictTestCase
{
    public function test_signed_settlement_is_not_effective_until_explicit_effectivity_step(): void
    {
        $context = $this->context();
        [$case, $mediationId] = $this->acceptedMediation($context);

        self::assertSame(ConflictCaseStage::Settlement, $case->stage);

        $workflow = $this->app->make(ConflictSettlementWorkflow::class);
        $draft = $workflow->createDraft(
            $context['user'],
            $context['business'],
            $case->getKey(),
            [
                'source_mediation_id' => $mediationId,
                'source_decision_id' => null,
                'settlement_terms' => 'The parties accept the exact mediated settlement terms.',
                'required_actions_summary' => 'Operations owner completes follow-up within seven days.',
                'responsible_owner_membership_id' => (string) $context['owner']->getKey(),
                'due_at' => now()->addWeek(),
                'financial_settlement_minor_units' => 25000,
                'currency' => 'USD',
                'confidentiality_terms' => 'Settlement remains restricted to authorized records.',
                'future_conduct_terms' => 'Parties follow the agreed communication protocol.',
                'review_date' => now()->addMonth()->toDateString(),
            ],
            now()->subMinute(),
            now()->addMonths(3),
        );
        self::assertNotNull($draft);

        $versionId = $draft['formal_record_version_id'];

        $this->assertDatabaseHas('conflict_settlement_versions', [
            'business_id' => $context['business']->getKey(),
            'formal_record_version_id' => $versionId,
            'conflict_case_id' => $case->getKey(),
            'source_mediation_id' => $mediationId,
            'settlement_decision_type' => 'conflict_settlement_approval',
            'financial_settlement_minor_units' => 25000,
            'currency' => 'USD',
        ]);
        $this->assertDatabaseCount('finance_payments', 0);

        $submitted = $workflow->submitForGovernance(
            $context['user'],
            $context['business'],
            (string) $case->getKey(),
            $versionId,
            1,
        );
        self::assertNotNull($submitted);

        self::assertTrue($workflow->advanceContentReview(
            $context['user'],
            $context['business'],
            (string) $case->getKey(),
            $versionId,
            FormalRecordState::UnderReview,
        ));
        self::assertTrue($workflow->advanceContentReview(
            $context['user'],
            $context['business'],
            (string) $case->getKey(),
            $versionId,
            FormalRecordState::Approved,
        ));

        $proposalReview = $this->app->make(CreateProposalReview::class)
            ->execute(
                $context['user'],
                $context['business'],
                $submitted['proposal_version_id'],
                (string) $context['owner']->getKey(),
            );
        self::assertNotNull($proposalReview);

        $reviewed = $this->app->make(CompleteProposalReview::class)
            ->execute(
                $context['user'],
                $context['business'],
                (string) $proposalReview->getKey(),
                ProposalReviewOutcome::Approved,
                'Exact Settlement Proposal Version reviewed.',
            );
        self::assertNotNull($reviewed);

        $decision = $this->app->make(OpenGovernanceDecision::class)
            ->execute(
                $context['user'],
                $context['business'],
                $submitted['proposal_version_id'],
                new DecisionType('conflict_settlement_approval'),
            );
        self::assertInstanceOf(Decision::class, $decision);

        $approval = $this->app->make(RecordGovernanceApproval::class)
            ->execute(
                $context['user'],
                $context['business'],
                (string) $decision->getKey(),
                ApprovalOutcome::Approved,
                'Approve exact frozen Settlement version.',
            );
        self::assertNotNull($approval);

        $resolvedDecision = $this->app
            ->make(ResolveGovernanceDecision::class)
            ->approve(
                $context['user'],
                $context['business'],
                (string) $decision->getKey(),
            );
        self::assertNotNull($resolvedDecision);

        [$document, $documentVersion] = $this->settlementDocument($context);

        self::assertTrue($workflow->bindDocument(
            $context['user'],
            $context['business'],
            (string) $case->getKey(),
            $versionId,
            (string) $documentVersion->getKey(),
        ));

        $request = $workflow->requestRequiredSignature(
            $context['user'],
            $context['business'],
            (string) $case->getKey(),
            $versionId,
        );
        self::assertInstanceOf(SignatureRequest::class, $request);
        self::assertSame(
            (string) $documentVersion->getKey(),
            (string) $request->document_version_id,
        );
        self::assertSame(
            (string) $documentVersion->content_sha256,
            (string) $request->document_content_sha256,
        );

        $sent = $this->app->make(SendSignatureRequest::class)->execute(
            $context['user'],
            $context['business'],
            (string) $request->getKey(),
        );
        self::assertNotNull($sent);

        $signature = $this->app->make(SignGovernanceDocument::class)
            ->execute(
                $context['user'],
                $context['business'],
                (string) $request->getKey(),
                'in_app',
                'I consent to sign this exact Settlement Document Version.',
                str_repeat('7', 64),
            );
        self::assertNotNull($signature);

        $completed = $this->app->make(CompleteSignatureRequest::class)
            ->execute(
                $context['user'],
                $context['business'],
                (string) $request->getKey(),
            );
        self::assertNotNull($completed);
        self::assertSame(
            SignatureRequestStatus::Completed,
            $completed->status,
        );

        self::assertFalse(
            RecordFamilyEffectiveHead::query()
                ->where('business_id', $context['business']->getKey())
                ->where('formal_record_version_id', $versionId)
                ->exists(),
        );
        self::assertSame(
            ConflictCaseStage::Settlement,
            ConflictCase::query()->findOrFail($case->getKey())->stage,
        );

        self::assertTrue($workflow->makeEffectiveAndResolve(
            $context['user'],
            $context['business'],
            (string) $case->getKey(),
            (int) $case->revision,
            $versionId,
        ));

        $effectiveCase = ConflictCase::query()->findOrFail($case->getKey());
        self::assertSame(ConflictCaseStage::Resolved, $effectiveCase->stage);

        $this->assertDatabaseHas('record_family_effective_heads', [
            'business_id' => $context['business']->getKey(),
            'formal_record_version_id' => $versionId,
        ]);
        $this->assertDatabaseHas('conflict_case_settlement_links', [
            'business_id' => $context['business']->getKey(),
            'conflict_case_id' => $case->getKey(),
            'settlement_formal_record_version_id' => $versionId,
            'decision_id' => $decision->getKey(),
        ]);
        $this->assertDatabaseCount('finance_payments', 0);

        unset($document);
    }

    public function test_signature_is_blocked_until_exact_document_binding_exists(): void
    {
        $context = $this->context();
        [$case, $mediationId] = $this->acceptedMediation($context);

        $workflow = $this->app->make(ConflictSettlementWorkflow::class);
        $draft = $workflow->createDraft(
            $context['user'],
            $context['business'],
            (string) $case->getKey(),
            [
                'source_mediation_id' => $mediationId,
                'settlement_terms' => 'Exact terms requiring signature.',
                'responsible_owner_membership_id' => (string) $context['owner']->getKey(),
            ],
            now()->subMinute(),
        );
        self::assertNotNull($draft);

        self::assertNull($workflow->requestRequiredSignature(
            $context['user'],
            $context['business'],
            (string) $case->getKey(),
            $draft['formal_record_version_id'],
        ));
        $this->assertDatabaseCount('signature_requests', 0);
    }

    /** @return array{ConflictCase,string} */
    private function acceptedMediation(array $context): array
    {
        $opened = $this->openCase($context);
        $caseWorkflow = $this->app->make(ConflictCaseWorkflow::class);

        $case = $caseWorkflow->transition(
            $context['user'],
            $context['business'],
            $opened['id'],
            1,
            ConflictCaseStage::DirectDiscussion,
        );
        self::assertNotNull($case);

        $discussion = $this->app
            ->make(ConflictDirectDiscussionWorkflow::class)
            ->record(
                $context['user'],
                $context['business'],
                $opened['id'],
                (int) $case->revision,
                'Discuss exact conflict facts.',
                'Each party records its position.',
                'Proceed to neutral mediation.',
                DirectDiscussionOutcome::ContinueMediation,
                [(string) $context['party']->getKey()],
            );
        self::assertNotNull($discussion);

        $case = ConflictCase::query()->findOrFail($opened['id']);
        $mediation = $this->app->make(ConflictMediationWorkflow::class);
        $mediationId = $mediation->schedule(
            $context['user'],
            $context['business'],
            $opened['id'],
            (int) $case->revision,
            'external',
            'No mediator conflict identified.',
            now()->addHour(),
            null,
            'Independent Mediator SET-001',
            now()->addDay(),
        );
        self::assertNotNull($mediationId);

        $this->grantCapability(
            $context['business'],
            $context['party'],
            'conflict.view',
        );
        self::assertTrue($caseWorkflow->grantAccess(
            $context['user'],
            $context['business'],
            $opened['id'],
            [(string) $context['party']->getKey()],
            false,
        ));

        self::assertTrue($mediation->recordResponse(
            $context['party_user'],
            $context['business'],
            $opened['id'],
            $mediationId,
            MediationResponseOutcome::Accepted,
            'Accept exact proposed settlement basis.',
        ));

        $completed = $mediation->complete(
            $context['user'],
            $context['business'],
            $opened['id'],
            $mediationId,
            (int) $case->revision,
            1,
            'Mediation completed with accepted basis.',
            'Proceed to a governed Settlement Agreement.',
        );
        self::assertNotNull($completed);

        return [
            ConflictCase::query()->findOrFail($opened['id']),
            $mediationId,
        ];
    }

    /** @return array{Document,DocumentVersion} */
    private function settlementDocument(array $context): array
    {
        $document = Document::query()->create([
            'business_id' => $context['business']->getKey(),
            'title' => 'Conflict Settlement Agreement',
            'category' => 'agreements_contracts',
            'created_by_membership_id' => $context['owner']->getKey(),
        ]);

        DocumentAccessGrant::query()->create([
            'business_id' => $context['business']->getKey(),
            'membership_id' => $context['owner']->getKey(),
            'document_id' => $document->getKey(),
            'right' => 'manage',
            'effect' => 'allow',
        ]);

        $version = DocumentVersion::query()->create([
            'business_id' => $context['business']->getKey(),
            'document_id' => $document->getKey(),
            'version_number' => 1,
            'original_filename' => 'conflict-settlement-v1.pdf',
            'storage_key' => 'tests/'.Str::uuid7().'/settlement-v1.pdf',
            'size_bytes' => 512,
            'mime_type' => 'application/pdf',
            'content_sha256' => str_repeat('8', 64),
            'uploaded_by_membership_id' => $context['owner']->getKey(),
            'effective_from' => null,
            'supersedes_document_version_id' => null,
        ]);

        return [$document, $version];
    }
}
