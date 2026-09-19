<?php

namespace Tests\Feature\Evidence;

use App\Domain\Businesses\Enums\BusinessOriginType;
use App\Domain\Businesses\Enums\BusinessStage;
use App\Domain\Businesses\Enums\WorkspaceStatus;
use App\Domain\Documents\Enums\DocumentCategory;
use App\Domain\Evidence\Enums\EvidenceConfidentiality;
use App\Domain\Identity\Enums\AccountStatus;
use App\Domain\Members\Enums\MembershipAccessStatus;
use App\Infrastructure\Persistence\Eloquent\Businesses\Business;
use App\Infrastructure\Persistence\Eloquent\Documents\Document;
use App\Infrastructure\Persistence\Eloquent\Documents\DocumentVersion;
use App\Infrastructure\Persistence\Eloquent\Evidence\Evidence;
use App\Infrastructure\Persistence\Eloquent\Evidence\EvidenceLink;
use App\Infrastructure\Persistence\Eloquent\Identity\User;
use App\Infrastructure\Persistence\Eloquent\Members\Membership;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

final class EvidenceIntegrityTest extends TestCase
{
    use RefreshDatabase;

    public function test_evidence_references_exact_document_version_and_preserves_provenance(): void
    {
        [$business, $membership] = $this->createBusinessMembership(
            'evidence-owner@example.com',
            'Evidence Business',
        );

        $version = $this->createDocumentVersion(
            $business,
            $membership,
            'Evidence Source',
            'documents/evidence/source-v1',
            str_repeat('4', 64),
        );

        $evidence = Evidence::query()->create([
            'business_id' => $business->id,
            'document_version_id' => $version->id,
            'confidentiality' => EvidenceConfidentiality::Restricted,
            'source_date' => '2026-09-19',
            'submitted_by_membership_id' => $membership->id,
            'verified_at' => now(),
            'verified_by_membership_id' => $membership->id,
            'verification_method' => 'manual_review',
            'verification_note' => 'Exact source version verified.',
        ])->refresh();

        $this->assertSame($business->id, $evidence->business->id);
        $this->assertSame($version->id, $evidence->documentVersion->id);
        $this->assertSame($membership->id, $evidence->submittedByMembership->id);
        $this->assertSame($membership->id, $evidence->verifiedByMembership->id);
        $this->assertSame(
            EvidenceConfidentiality::Restricted,
            $evidence->confidentiality,
        );
        $this->assertSame('manual_review', $evidence->verification_method);
        $this->assertSame('2026-09-19', $evidence->source_date->format('Y-m-d'));
    }

    public function test_evidence_rejects_cross_business_document_version_and_partial_verification(): void
    {
        [$businessA, $membershipA] = $this->createBusinessMembership(
            'evidence-a@example.com',
            'Evidence Business A',
        );

        [$businessB, $membershipB] = $this->createBusinessMembership(
            'evidence-b@example.com',
            'Evidence Business B',
        );

        $versionA = $this->createDocumentVersion(
            $businessA,
            $membershipA,
            'Business A Evidence',
            'documents/evidence/a-v1',
            str_repeat('5', 64),
        );

        $this->expectDatabaseRejection(function () use (
            $businessB,
            $membershipB,
            $versionA,
        ): void {
            Evidence::query()->create([
                'business_id' => $businessB->id,
                'document_version_id' => $versionA->id,
                'confidentiality' => EvidenceConfidentiality::Standard,
                'source_date' => '2026-09-19',
                'submitted_by_membership_id' => $membershipB->id,
            ]);
        });

        $this->expectDatabaseRejection(function () use (
            $businessA,
            $membershipA,
            $versionA,
        ): void {
            Evidence::query()->create([
                'business_id' => $businessA->id,
                'document_version_id' => $versionA->id,
                'confidentiality' => EvidenceConfidentiality::Standard,
                'source_date' => '2026-09-19',
                'submitted_by_membership_id' => $membershipA->id,
                'verified_at' => now(),
                'verified_by_membership_id' => null,
                'verification_method' => null,
                'verification_note' => null,
            ]);
        });
    }

    public function test_verified_evidence_provenance_cannot_be_rewritten_or_cleared(): void
    {
        [$business, $membership] = $this->createBusinessMembership(
            'verified-evidence@example.com',
            'Verified Evidence Business',
        );

        $version = $this->createDocumentVersion(
            $business,
            $membership,
            'Verified Source',
            'documents/evidence/verified-v1',
            str_repeat('6', 64),
        );

        $evidence = Evidence::query()->create([
            'business_id' => $business->id,
            'document_version_id' => $version->id,
            'confidentiality' => EvidenceConfidentiality::Standard,
            'source_date' => '2026-09-19',
            'submitted_by_membership_id' => $membership->id,
            'verified_at' => now(),
            'verified_by_membership_id' => $membership->id,
            'verification_method' => 'manual_review',
            'verification_note' => 'Verified once.',
        ]);

        $this->expectDatabaseRejection(function () use ($evidence): void {
            DB::table('evidence')
                ->where('id', $evidence->id)
                ->update([
                    'verification_note' => 'Rewritten verification.',
                    'updated_at' => now(),
                ]);
        });

        $this->expectDatabaseRejection(function () use ($evidence): void {
            DB::table('evidence')
                ->where('id', $evidence->id)
                ->update([
                    'verified_at' => null,
                    'verified_by_membership_id' => null,
                    'verification_method' => null,
                    'verification_note' => null,
                    'updated_at' => now(),
                ]);
        });

        $evidence->refresh();

        $this->assertSame('Verified once.', $evidence->verification_note);
        $this->assertNotNull($evidence->verified_at);
    }

    public function test_evidence_link_stays_inside_evidence_business_and_rejects_duplicates(): void
    {
        [$businessA, $membershipA] = $this->createBusinessMembership(
            'link-a@example.com',
            'Link Business A',
        );

        [$businessB, $membershipB] = $this->createBusinessMembership(
            'link-b@example.com',
            'Link Business B',
        );

        $version = $this->createDocumentVersion(
            $businessA,
            $membershipA,
            'Link Source',
            'documents/evidence/link-v1',
            str_repeat('7', 64),
        );

        $evidence = Evidence::query()->create([
            'business_id' => $businessA->id,
            'document_version_id' => $version->id,
            'confidentiality' => EvidenceConfidentiality::Standard,
            'source_date' => '2026-09-19',
            'submitted_by_membership_id' => $membershipA->id,
        ]);

        $targetId = (string) Str::uuid7();

        $link = EvidenceLink::query()->create([
            'business_id' => $businessA->id,
            'evidence_id' => $evidence->id,
            'target_type' => 'test_record',
            'target_id' => $targetId,
            'created_by_membership_id' => $membershipA->id,
        ])->refresh();

        $this->assertSame($evidence->id, $link->evidence->id);
        $this->assertSame($businessA->id, $link->business->id);
        $this->assertSame($membershipA->id, $link->createdByMembership->id);

        $this->expectDatabaseRejection(function () use (
            $businessB,
            $membershipB,
            $evidence,
        ): void {
            EvidenceLink::query()->create([
                'business_id' => $businessB->id,
                'evidence_id' => $evidence->id,
                'target_type' => 'test_record',
                'target_id' => (string) Str::uuid7(),
                'created_by_membership_id' => $membershipB->id,
            ]);
        });

        $this->expectDatabaseRejection(function () use (
            $businessA,
            $membershipB,
            $evidence,
        ): void {
            EvidenceLink::query()->create([
                'business_id' => $businessA->id,
                'evidence_id' => $evidence->id,
                'target_type' => 'test_record',
                'target_id' => (string) Str::uuid7(),
                'created_by_membership_id' => $membershipB->id,
            ]);
        });

        $this->expectDatabaseRejection(function () use (
            $businessA,
            $membershipA,
            $evidence,
            $targetId,
        ): void {
            EvidenceLink::query()->create([
                'business_id' => $businessA->id,
                'evidence_id' => $evidence->id,
                'target_type' => 'test_record',
                'target_id' => $targetId,
                'created_by_membership_id' => $membershipA->id,
            ]);
        });
    }

    private function expectDatabaseRejection(callable $operation): void
    {
        try {
            DB::transaction(function () use ($operation): void {
                $operation();
            });

            $this->fail('Expected PostgreSQL to reject the invalid evidence operation.');
        } catch (QueryException) {
            $this->addToAssertionCount(1);
        }
    }

    private function createDocumentVersion(
        Business $business,
        Membership $membership,
        string $title,
        string $storageKey,
        string $hash,
    ): DocumentVersion {
        $document = Document::query()->create([
            'business_id' => $business->id,
            'title' => $title,
            'category' => DocumentCategory::CorporateLegal,
            'created_by_membership_id' => $membership->id,
        ]);

        return DocumentVersion::query()->create([
            'business_id' => $business->id,
            'document_id' => $document->id,
            'version_number' => 1,
            'original_filename' => $title.'.pdf',
            'storage_key' => $storageKey,
            'size_bytes' => 2048,
            'mime_type' => 'application/pdf',
            'content_sha256' => $hash,
            'uploaded_by_membership_id' => $membership->id,
            'effective_from' => null,
            'supersedes_document_version_id' => null,
        ]);
    }

    /**
     * @return array{Business, Membership}
     */
    private function createBusinessMembership(
        string $email,
        string $businessName,
    ): array {
        $user = User::query()->create([
            'email' => $email,
            'password' => 'not-a-real-hash',
            'status' => AccountStatus::Provisioned,
            'password_changed_at' => now(),
        ]);

        $business = Business::query()->create([
            'name' => $businessName,
            'origin_type' => BusinessOriginType::StartedThroughPbr,
            'business_stage' => BusinessStage::Idea,
            'setup_phase' => null,
            'workspace_status' => WorkspaceStatus::Active,
            'base_currency' => 'USD',
        ]);

        $membership = Membership::query()->create([
            'user_id' => $user->id,
            'business_id' => $business->id,
            'access_status' => MembershipAccessStatus::Active,
        ]);

        return [$business, $membership];
    }
}
