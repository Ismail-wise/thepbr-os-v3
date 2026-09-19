<?php

namespace Tests\Feature\Documents;

use App\Domain\Businesses\Enums\BusinessOriginType;
use App\Domain\Businesses\Enums\BusinessStage;
use App\Domain\Businesses\Enums\WorkspaceStatus;
use App\Domain\Documents\Enums\DocumentCategory;
use App\Domain\Identity\Enums\AccountStatus;
use App\Domain\Members\Enums\MembershipAccessStatus;
use App\Infrastructure\Persistence\Eloquent\Businesses\Business;
use App\Infrastructure\Persistence\Eloquent\Documents\Document;
use App\Infrastructure\Persistence\Eloquent\Documents\DocumentVersion;
use App\Infrastructure\Persistence\Eloquent\Identity\User;
use App\Infrastructure\Persistence\Eloquent\Members\Membership;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

final class DocumentVersionIntegrityTest extends TestCase
{
    use RefreshDatabase;

    public function test_version_preserves_exact_binary_metadata_and_relationships(): void
    {
        [$business, $membership] = $this->createBusinessMembership(
            'version-owner@example.com',
            'Version Business',
        );

        $document = $this->createDocument($business, $membership, 'Agreement');

        $version = $this->createVersion(
            $business,
            $membership,
            $document,
            1,
            'documents/a1/opaque-v1',
            str_repeat('a', 64),
        )->refresh();

        $this->assertSame($document->id, $version->document->id);
        $this->assertSame($business->id, $version->business->id);
        $this->assertSame($membership->id, $version->uploadedByMembership->id);
        $this->assertSame(1, $version->version_number);
        $this->assertSame('Agreement-v1.pdf', $version->original_filename);
        $this->assertSame('documents/a1/opaque-v1', $version->storage_key);
        $this->assertSame(1024, $version->size_bytes);
        $this->assertSame('application/pdf', $version->mime_type);
        $this->assertSame(str_repeat('a', 64), $version->content_sha256);
    }

    public function test_document_version_update_and_delete_are_blocked_at_database_level(): void
    {
        [$business, $membership] = $this->createBusinessMembership(
            'immutable-version@example.com',
            'Immutable Version Business',
        );

        $document = $this->createDocument($business, $membership, 'Immutable');

        $version = $this->createVersion(
            $business,
            $membership,
            $document,
            1,
            'documents/immutable/v1',
            str_repeat('b', 64),
        );

        $this->expectDatabaseRejection(function () use ($version): void {
            DB::table('document_versions')
                ->where('id', $version->id)
                ->update(['original_filename' => 'rewritten.pdf']);
        });

        $version->refresh();

        $this->assertSame('Immutable-v1.pdf', $version->original_filename);

        $this->expectDatabaseRejection(function () use ($version): void {
            DB::table('document_versions')
                ->where('id', $version->id)
                ->delete();
        });

        $this->assertTrue(
            DocumentVersion::query()
                ->whereKey($version->id)
                ->exists(),
        );
    }

    public function test_version_numbers_are_unique_within_document_and_history_is_additive(): void
    {
        [$business, $membership] = $this->createBusinessMembership(
            'version-history@example.com',
            'History Business',
        );

        $document = $this->createDocument($business, $membership, 'History');

        $versionOne = $this->createVersion(
            $business,
            $membership,
            $document,
            1,
            'documents/history/v1',
            str_repeat('c', 64),
        );

        $this->expectDatabaseRejection(function () use (
            $business,
            $membership,
            $document,
        ): void {
            $this->createVersion(
                $business,
                $membership,
                $document,
                1,
                'documents/history/duplicate-v1',
                str_repeat('d', 64),
            );
        });

        $versionTwo = $this->createVersion(
            $business,
            $membership,
            $document,
            2,
            'documents/history/v2',
            str_repeat('e', 64),
            $versionOne,
        )->refresh();

        $versionOne->refresh();

        $this->assertSame(1, $versionOne->version_number);
        $this->assertSame(str_repeat('c', 64), $versionOne->content_sha256);
        $this->assertSame($versionOne->id, $versionTwo->supersedes->id);
        $this->assertSame(2, $document->versions()->count());
    }

    public function test_cross_business_uploader_and_cross_document_supersession_are_rejected(): void
    {
        [$businessA, $membershipA] = $this->createBusinessMembership(
            'version-a@example.com',
            'Version Business A',
        );

        [$businessB, $membershipB] = $this->createBusinessMembership(
            'version-b@example.com',
            'Version Business B',
        );

        $documentA = $this->createDocument(
            $businessA,
            $membershipA,
            'Document A',
        );

        $documentB = $this->createDocument(
            $businessA,
            $membershipA,
            'Document B',
        );

        $versionA = $this->createVersion(
            $businessA,
            $membershipA,
            $documentA,
            1,
            'documents/a/v1',
            str_repeat('f', 64),
        );

        $this->expectDatabaseRejection(function () use (
            $businessA,
            $membershipB,
            $documentA,
        ): void {
            $this->createVersion(
                $businessA,
                $membershipB,
                $documentA,
                2,
                'documents/a/cross-uploader',
                str_repeat('1', 64),
            );
        });

        $this->expectDatabaseRejection(function () use (
            $businessA,
            $membershipA,
            $documentB,
            $versionA,
        ): void {
            $this->createVersion(
                $businessA,
                $membershipA,
                $documentB,
                1,
                'documents/b/invalid-supersession',
                str_repeat('2', 64),
                $versionA,
            );
        });

        $this->expectDatabaseRejection(function () use (
            $businessB,
            $membershipB,
            $documentA,
        ): void {
            $this->createVersion(
                $businessB,
                $membershipB,
                $documentA,
                2,
                'documents/cross-business/v2',
                str_repeat('3', 64),
            );
        });
    }

    private function expectDatabaseRejection(callable $operation): void
    {
        try {
            DB::transaction(function () use ($operation): void {
                $operation();
            });

            $this->fail('Expected PostgreSQL to reject the invalid document version operation.');
        } catch (QueryException) {
            $this->addToAssertionCount(1);
        }
    }

    private function createDocument(
        Business $business,
        Membership $membership,
        string $title,
    ): Document {
        return Document::query()->create([
            'business_id' => $business->id,
            'title' => $title,
            'category' => DocumentCategory::CorporateLegal,
            'created_by_membership_id' => $membership->id,
        ]);
    }

    private function createVersion(
        Business $business,
        Membership $membership,
        Document $document,
        int $versionNumber,
        string $storageKey,
        string $hash,
        ?DocumentVersion $supersedes = null,
    ): DocumentVersion {
        return DocumentVersion::query()->create([
            'business_id' => $business->id,
            'document_id' => $document->id,
            'version_number' => $versionNumber,
            'original_filename' => $document->title.'-v'.$versionNumber.'.pdf',
            'storage_key' => $storageKey,
            'size_bytes' => 1024,
            'mime_type' => 'application/pdf',
            'content_sha256' => $hash,
            'uploaded_by_membership_id' => $membership->id,
            'effective_from' => null,
            'supersedes_document_version_id' => $supersedes?->id,
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
