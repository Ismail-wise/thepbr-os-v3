<?php

declare(strict_types=1);

namespace Tests\Feature\Evidence;

use App\Application\Evidence\CreateDocumentVersionEvidence;
use App\Application\Evidence\EvidenceTargetRegistry;
use App\Application\Evidence\LinkEvidence;
use App\Application\Evidence\VerifyEvidence;
use App\Domain\Access\Enums\PermissionEffect;
use App\Domain\Businesses\Enums\BusinessOriginType;
use App\Domain\Businesses\Enums\BusinessStage;
use App\Domain\Businesses\Enums\SetupPhase;
use App\Domain\Businesses\Enums\WorkspaceStatus;
use App\Domain\Documents\Enums\DocumentAccessRight;
use App\Domain\Documents\Enums\DocumentCategory;
use App\Domain\Evidence\Enums\EvidenceConfidentiality;
use App\Domain\Identity\Enums\AccountStatus;
use App\Domain\Members\Enums\MembershipAccessStatus;
use App\Infrastructure\Persistence\Eloquent\Access\Permission;
use App\Infrastructure\Persistence\Eloquent\Access\PermissionGrant;
use App\Infrastructure\Persistence\Eloquent\Access\RecordAccessRule;
use App\Infrastructure\Persistence\Eloquent\Businesses\Business;
use App\Infrastructure\Persistence\Eloquent\Documents\Document;
use App\Infrastructure\Persistence\Eloquent\Documents\DocumentAccessGrant;
use App\Infrastructure\Persistence\Eloquent\Documents\DocumentVersion;
use App\Infrastructure\Persistence\Eloquent\Evidence\Evidence;
use App\Infrastructure\Persistence\Eloquent\Identity\User;
use App\Infrastructure\Persistence\Eloquent\Members\Membership;
use App\Infrastructure\Persistence\Eloquent\Records\FormalRecordFamily;
use App\Infrastructure\Persistence\Eloquent\Records\FormalRecordVersion;
use App\Infrastructure\Persistence\Eloquent\Records\Proposal;
use App\Infrastructure\Persistence\Eloquent\Records\ProposalVersion;
use App\Infrastructure\Persistence\Eloquent\Risk\RiskIncident;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Tests\TestCase;

final class EvidencePrivacyTest extends TestCase
{
    use RefreshDatabase;

    public function test_restricted_evidence_binds_exact_version_and_requires_document_manage_access(): void
    {
        [$user, $business, $membership] = $this->context();

        $this->allowSystem(
            $business,
            $membership,
            'records.manage',
        );

        [$document, $version] = $this->documentVersion(
            $business,
            $membership,
        );

        $this->allowDocument(
            $business,
            $membership,
            $document,
            DocumentAccessRight::Manage,
        );

        $evidence = $this->app
            ->make(CreateDocumentVersionEvidence::class)
            ->execute(
                $user,
                $business,
                (string) $version->getKey(),
                EvidenceConfidentiality::Restricted,
                now()->subDay(),
            );

        $this->assertNotNull($evidence);
        $this->assertSame(
            (string) $version->getKey(),
            (string) $evidence->document_version_id,
        );
        $this->assertSame(
            EvidenceConfidentiality::Restricted,
            $evidence->confidentiality,
        );

        $this->assertDatabaseHas('audit_events', [
            'business_id' => $business->getKey(),
            'action' => 'evidence.created',
            'target_id' => $evidence->getKey(),
        ]);

        $this->assertDatabaseHas('business_events', [
            'business_id' => $business->getKey(),
            'event_type' => 'evidence.created',
            'visibility_resource_type' => 'document',
            'visibility_resource_id' => $document->getKey(),
        ]);
    }

    public function test_document_grant_cannot_be_bypassed_for_evidence_creation(): void
    {
        [$user, $business, $membership] = $this->context();

        $this->allowSystem(
            $business,
            $membership,
            'records.manage',
        );

        [, $version] = $this->documentVersion(
            $business,
            $membership,
        );

        $this->assertNull(
            $this->app
                ->make(CreateDocumentVersionEvidence::class)
                ->execute(
                    $user,
                    $business,
                    (string) $version->getKey(),
                    EvidenceConfidentiality::Restricted,
                    sourceDate: now()->toImmutable(),
                ),
        );

        $this->assertDatabaseCount('evidence', 0);
    }

    public function test_verification_is_null_to_verified_once_and_preserves_provenance(): void
    {
        [$user, $business, $membership] = $this->context();

        $this->allowSystem(
            $business,
            $membership,
            'records.manage',
        );

        [$document, $version] = $this->documentVersion(
            $business,
            $membership,
        );

        $this->allowDocument(
            $business,
            $membership,
            $document,
            DocumentAccessRight::Manage,
        );

        $evidence = $this->app
            ->make(CreateDocumentVersionEvidence::class)
            ->execute(
                $user,
                $business,
                (string) $version->getKey(),
                EvidenceConfidentiality::Standard,
                sourceDate: now()->toImmutable(),
            );

        $this->assertNotNull($evidence);
        $this->assertNull($evidence->verified_at);

        $verified = $this->app
            ->make(VerifyEvidence::class)
            ->execute(
                $user,
                $business,
                (string) $evidence->getKey(),
                'manual_review',
                'Verified against the exact file.',
            );

        $this->assertNotNull($verified);
        $this->assertNotNull($verified->verified_at);
        $this->assertSame(
            (string) $membership->getKey(),
            (string) $verified->verified_by_membership_id,
        );
        $this->assertSame(
            'manual_review',
            $verified->verification_method,
        );

        $this->assertNull(
            $this->app
                ->make(VerifyEvidence::class)
                ->execute(
                    $user,
                    $business,
                    (string) $evidence->getKey(),
                    'second_review',
                ),
        );

        $preserved = Evidence::query()->findOrFail(
            $evidence->getKey(),
        );

        $this->assertSame(
            'manual_review',
            $preserved->verification_method,
        );
        $this->assertSame(
            'Verified against the exact file.',
            $preserved->verification_note,
        );
    }

    public function test_target_registry_is_closed(): void
    {
        $registry = $this->app->make(
            EvidenceTargetRegistry::class,
        );

        $this->assertSame(
            [
                'formal_record_version',
                'proposal_version',
                'contribution',
                'finance_payment',
                'finance_reconciliation',
                'finance_exception',
                'distribution_run',
                'risk_protection',
                'risk_incident',
                'risk_control_test',
                'continuity_test',
                'continuity_emergency_access_activation',
                'conflict_case',
                'exit_case',
                'closure_case',
            ],
            $registry->supportedTypes(),
        );

        $this->expectException(
            InvalidArgumentException::class,
        );

        $registry->resolve(
            'arbitrary_php_class',
            (string) Str::uuid7(),
            (string) Str::uuid7(),
        );
    }

    public function test_same_business_proposal_version_links_only_through_authorized_evidence(): void
    {
        [$user, $business, $membership] = $this->context();

        $this->allowSystem(
            $business,
            $membership,
            'records.manage',
        );

        [$document, $version] = $this->documentVersion(
            $business,
            $membership,
        );

        $this->allowDocument(
            $business,
            $membership,
            $document,
            DocumentAccessRight::Manage,
        );

        $evidence = $this->app
            ->make(CreateDocumentVersionEvidence::class)
            ->execute(
                $user,
                $business,
                (string) $version->getKey(),
                EvidenceConfidentiality::Restricted,
                sourceDate: now()->toImmutable(),
            );

        $this->assertNotNull($evidence);

        $proposalVersion = $this->proposalVersion(
            $business,
            $user,
        );

        $link = $this->app
            ->make(LinkEvidence::class)
            ->execute(
                $user,
                $business,
                (string) $evidence->getKey(),
                'proposal_version',
                (string) $proposalVersion->getKey(),
            );

        $this->assertNotNull($link);

        $this->assertDatabaseHas('evidence_links', [
            'business_id' => $business->getKey(),
            'evidence_id' => $evidence->getKey(),
            'target_type' => 'proposal_version',
            'target_id' => $proposalVersion->getKey(),
        ]);

        $this->assertDatabaseHas('audit_events', [
            'business_id' => $business->getKey(),
            'action' => 'evidence.linked',
            'target_id' => $evidence->getKey(),
        ]);
    }

    public function test_authorized_restricted_risk_target_evidence_linking_succeeds(): void
    {
        [$user, $business, $membership] = $this->context();

        $this->allowSystem($business, $membership, 'records.manage');
        $this->allowSystem($business, $membership, 'risk.manage');

        [$document, $version] = $this->documentVersion(
            $business,
            $membership,
        );
        $this->allowDocument(
            $business,
            $membership,
            $document,
            DocumentAccessRight::Manage,
        );

        $evidence = $this->app
            ->make(CreateDocumentVersionEvidence::class)
            ->execute(
                $user,
                $business,
                (string) $version->getKey(),
                EvidenceConfidentiality::Restricted,
                sourceDate: now()->toImmutable(),
            );

        self::assertNotNull($evidence);

        $incident = $this->restrictedRiskIncident(
            $business,
            $user,
            $membership,
        );

        $this->allowRecord(
            $business,
            $membership,
            'risk.manage',
            RiskIncident::class,
            (string) $incident->getKey(),
        );

        $link = $this->app->make(LinkEvidence::class)->execute(
            $user,
            $business,
            (string) $evidence->getKey(),
            'risk_incident',
            (string) $incident->getKey(),
        );

        self::assertNotNull($link);
        $this->assertDatabaseHas('evidence_links', [
            'business_id' => $business->getKey(),
            'evidence_id' => $evidence->getKey(),
            'target_type' => 'risk_incident',
            'target_id' => $incident->getKey(),
        ]);
    }

    public function test_unauthorized_restricted_target_link_fails_closed_without_existence_oracle(): void
    {
        [$user, $business, $membership] = $this->context();

        $this->allowSystem($business, $membership, 'records.manage');
        $this->allowSystem($business, $membership, 'risk.manage');

        [$document, $version] = $this->documentVersion(
            $business,
            $membership,
        );
        $this->allowDocument(
            $business,
            $membership,
            $document,
            DocumentAccessRight::Manage,
        );

        $evidence = $this->app
            ->make(CreateDocumentVersionEvidence::class)
            ->execute(
                $user,
                $business,
                (string) $version->getKey(),
                EvidenceConfidentiality::Restricted,
                sourceDate: now()->toImmutable(),
            );

        self::assertNotNull($evidence);

        $incident = $this->restrictedRiskIncident(
            $business,
            $user,
            $membership,
        );

        $hiddenResult = $this->app->make(LinkEvidence::class)->execute(
            $user,
            $business,
            (string) $evidence->getKey(),
            'risk_incident',
            (string) $incident->getKey(),
        );

        $missingResult = $this->app->make(LinkEvidence::class)->execute(
            $user,
            $business,
            (string) $evidence->getKey(),
            'risk_incident',
            (string) Str::uuid7(),
        );

        self::assertNull($hiddenResult);
        self::assertSame(
            $missingResult,
            $hiddenResult,
            'Hidden restricted target and missing target must fail identically.',
        );
        $this->assertDatabaseCount('evidence_links', 0);
    }

    public function test_cross_business_restricted_target_cannot_be_linked(): void
    {
        [$user, $business, $membership] = $this->context();

        $this->allowSystem($business, $membership, 'records.manage');
        $this->allowSystem($business, $membership, 'risk.manage');

        [$document, $version] = $this->documentVersion(
            $business,
            $membership,
        );
        $this->allowDocument(
            $business,
            $membership,
            $document,
            DocumentAccessRight::Manage,
        );

        $evidence = $this->app
            ->make(CreateDocumentVersionEvidence::class)
            ->execute(
                $user,
                $business,
                (string) $version->getKey(),
                EvidenceConfidentiality::Restricted,
                sourceDate: now()->toImmutable(),
            );

        self::assertNotNull($evidence);

        [$otherUser, $otherBusiness, $otherMembership] = $this->context();
        $otherIncident = $this->restrictedRiskIncident(
            $otherBusiness,
            $otherUser,
            $otherMembership,
        );

        $result = $this->app->make(LinkEvidence::class)->execute(
            $user,
            $business,
            (string) $evidence->getKey(),
            'risk_incident',
            (string) $otherIncident->getKey(),
        );

        self::assertNull($result);
        $this->assertDatabaseMissing('evidence_links', [
            'business_id' => $business->getKey(),
            'target_type' => 'risk_incident',
            'target_id' => $otherIncident->getKey(),
        ]);
    }

    /**
     * @return array{User, Business, Membership}
     */
    private function context(): array
    {
        $user = User::query()->create([
            'email' => Str::uuid7().'@example.test',
            'password' => 'not-a-real-hash',
            'status' => AccountStatus::Active,
            'password_changed_at' => now(),
        ]);

        $business = Business::query()->create([
            'name' => 'Evidence Privacy '.Str::uuid7(),
            'origin_type' => BusinessOriginType::StartedThroughPbr,
            'business_stage' => BusinessStage::Idea,
            'setup_phase' => SetupPhase::Formation,
            'workspace_status' => WorkspaceStatus::Active,
            'base_currency' => 'USD',
        ]);

        $membership = Membership::query()->create([
            'user_id' => $user->getKey(),
            'business_id' => $business->getKey(),
            'access_status' => MembershipAccessStatus::Active,
        ]);

        return [$user, $business, $membership];
    }

    private function allowSystem(
        Business $business,
        Membership $membership,
        string $key,
    ): void {
        $permission = Permission::query()->firstOrCreate([
            'key' => $key,
        ]);

        PermissionGrant::query()->create([
            'business_id' => $business->getKey(),
            'membership_id' => $membership->getKey(),
            'permission_id' => $permission->getKey(),
            'effect' => PermissionEffect::Allow,
        ]);
    }

    private function allowRecord(
        Business $business,
        Membership $membership,
        string $capability,
        string $resourceType,
        string $resourceId,
    ): void {
        $permission = Permission::query()
            ->where('key', $capability)
            ->sole();

        RecordAccessRule::query()->create([
            'business_id' => $business->getKey(),
            'membership_id' => $membership->getKey(),
            'permission_profile_id' => null,
            'permission_id' => $permission->getKey(),
            'resource_type' => $resourceType,
            'resource_id' => $resourceId,
            'effect' => PermissionEffect::Allow,
        ]);
    }

    /**
     * @return array{Document, DocumentVersion}
     */
    private function documentVersion(
        Business $business,
        Membership $membership,
    ): array {
        $document = Document::query()->create([
            'business_id' => $business->getKey(),
            'title' => 'Evidence Source',
            'category' => DocumentCategory::CorporateLegal,
            'created_by_membership_id' => $membership->getKey(),
        ]);

        $version = DocumentVersion::query()->create([
            'business_id' => $business->getKey(),
            'document_id' => $document->getKey(),
            'version_number' => 1,
            'original_filename' => 'evidence.pdf',
            'storage_key' => 'test/'.Str::uuid7(),
            'size_bytes' => 10,
            'mime_type' => 'application/pdf',
            'content_sha256' => str_repeat('a', 64),
            'uploaded_by_membership_id' => $membership->getKey(),
            'effective_from' => null,
            'supersedes_document_version_id' => null,
        ]);

        return [$document, $version];
    }

    private function allowDocument(
        Business $business,
        Membership $membership,
        Document $document,
        DocumentAccessRight $right,
    ): void {
        DocumentAccessGrant::query()->create([
            'business_id' => $business->getKey(),
            'membership_id' => $membership->getKey(),
            'document_id' => $document->getKey(),
            'right' => $right,
            'effect' => 'allow',
        ]);
    }

    private function restrictedRiskIncident(
        Business $business,
        User $user,
        Membership $membership,
    ): RiskIncident {
        $family = FormalRecordFamily::query()->create([
            'business_id' => $business->getKey(),
            'record_type' => 'risk_register',
            'subject_type' => 'business',
            'subject_id' => (string) $business->getKey(),
        ]);

        $version = FormalRecordVersion::query()->create([
            'business_id' => $business->getKey(),
            'formal_record_family_id' => $family->getKey(),
            'version_number' => 1,
            'predecessor_version_id' => null,
            'revision' => 1,
            'change_summary' => 'Evidence restricted target fixture',
            'created_by_user_id' => $user->getKey(),
            'last_changed_by_user_id' => $user->getKey(),
            'effective_from' => now(),
            'effective_until' => null,
            'review_due_at' => null,
            'content_hash' => hash('sha256', 'risk-'.Str::uuid7()),
            'frozen_at' => null,
        ]);

        return RiskIncident::query()->create([
            'business_id' => $business->getKey(),
            'formal_record_version_id' => $version->getKey(),
            'risk_item_id' => null,
            'incident_at' => now(),
            'incident_type' => 'restricted_fixture',
            'description' => 'Restricted target fixture.',
            'business_impact' => 'Restricted impact.',
            'immediate_action' => 'Restricted response.',
            'loss_amount_minor_units' => null,
            'currency' => null,
            'status' => 'open',
            'confidentiality' => 'restricted',
            'opened_by_membership_id' => $membership->getKey(),
            'revision' => 1,
        ]);
    }

    private function proposalVersion(
        Business $business,
        User $user,
    ): ProposalVersion {
        $hash = str_repeat('b', 64);

        $proposal = Proposal::query()->create([
            'business_id' => $business->getKey(),
            'revision' => 1,
            'content_hash' => $hash,
            'created_by_user_id' => $user->getKey(),
            'last_changed_by_user_id' => $user->getKey(),
        ]);

        return ProposalVersion::query()->create([
            'business_id' => $business->getKey(),
            'proposal_id' => $proposal->getKey(),
            'version_number' => 1,
            'proposal_revision' => 1,
            'proposal_content_hash' => $hash,
            'snapshot_hash' => str_repeat('c', 64),
            'frozen_by_user_id' => $user->getKey(),
            'frozen_at' => now(),
        ]);
    }

    public function test_evidence_mutations_reuse_round3_occurrence_infrastructure(): void
    {
        foreach ([
            base_path(
                'app/Application/Evidence/CreateDocumentVersionEvidence.php',
            ),
            base_path(
                'app/Application/Evidence/LinkEvidence.php',
            ),
            base_path(
                'app/Application/Evidence/VerifyEvidence.php',
            ),
        ] as $path) {
            $source = file_get_contents($path);

            $this->assertIsString($source);
            $this->assertStringContainsString(
                'RecordBusinessOccurrence',
                $source,
            );
            $this->assertStringContainsString(
                'SafeAuditMetadata::from',
                $source,
            );
            $this->assertStringNotContainsString(
                'AuditEvent::query()->create',
                $source,
            );
            $this->assertStringNotContainsString(
                'BusinessEvent::query()->create',
                $source,
            );
        }
    }

    public function test_document_version_evidence_requires_source_date_at_application_boundary(): void
    {
        $sourceDate = null;

        $reflection = new \ReflectionClass(
            CreateDocumentVersionEvidence::class,
        );

        foreach (
            $reflection->getMethods(
                \ReflectionMethod::IS_PUBLIC,
            ) as $method
        ) {
            foreach ($method->getParameters() as $parameter) {
                if ($parameter->getName() === 'sourceDate') {
                    $sourceDate = $parameter;

                    break 2;
                }
            }
        }

        $this->assertInstanceOf(
            \ReflectionParameter::class,
            $sourceDate,
        );

        $this->assertFalse(
            $sourceDate->allowsNull(),
            'Evidence sourceDate must be required at the application boundary.',
        );
    }
}
