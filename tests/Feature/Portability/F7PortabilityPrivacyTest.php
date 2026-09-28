<?php

declare(strict_types=1);

namespace Tests\Feature\Portability;

use App\Application\Access\ProvisionStandardAccessProfiles;
use App\Application\Portability\AuthorizeBusinessExportDownload;
use App\Application\Portability\CreateBusinessExport;
use App\Application\Portability\GenerateBusinessExport;
use App\Domain\Access\Enums\PermissionEffect;
use App\Domain\Documents\Enums\DocumentAccessRight;
use App\Domain\Documents\Enums\DocumentCategory;
use App\Infrastructure\Persistence\Eloquent\Access\Permission;
use App\Infrastructure\Persistence\Eloquent\Access\PermissionGrant;
use App\Infrastructure\Persistence\Eloquent\Businesses\Business;
use App\Infrastructure\Persistence\Eloquent\Documents\Document;
use App\Infrastructure\Persistence\Eloquent\Documents\DocumentAccessGrant;
use App\Infrastructure\Persistence\Eloquent\Documents\DocumentVersion;
use App\Infrastructure\Persistence\Eloquent\Identity\User;
use App\Infrastructure\Persistence\Eloquent\Members\Membership;
use App\Infrastructure\Persistence\Eloquent\Records\FormalRecordFamily;
use App\Infrastructure\Persistence\Eloquent\Records\FormalRecordVersion;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

final class F7PortabilityPrivacyTest extends TestCase
{
    use RefreshDatabase;

    public function test_later_document_permission_revocation_blocks_existing_export_download(): void
    {
        Storage::fake('business_documents');

        [$user, $business, $membership] = $this->workspace(
            'portability-revoke',
        );

        [$document, $grant] = $this->document(
            $business,
            $membership,
            'Restricted Document',
        );

        $export = $this->app->make(CreateBusinessExport::class)->execute(
            $user,
            $business,
            ['documents'],
        );

        self::assertNotNull($export);

        $available = $this->app
            ->make(GenerateBusinessExport::class)
            ->execute(
                $user,
                $business,
                (string) $export->getKey(),
            );

        self::assertNotNull($available);
        self::assertNotNull(
            $this->app
                ->make(AuthorizeBusinessExportDownload::class)
                ->execute(
                    $user,
                    $business,
                    (string) $available->getKey(),
                ),
        );

        $grant->effect = PermissionEffect::Deny->value;
        $grant->save();

        self::assertNull(
            $this->app
                ->make(AuthorizeBusinessExportDownload::class)
                ->execute(
                    $user,
                    $business,
                    (string) $available->getKey(),
                ),
            'Later Document permission revocation must block an already-generated export.',
        );

        self::assertTrue(
            Document::query()
                ->whereKey($document->getKey())
                ->exists(),
        );
    }

    public function test_cross_business_export_identifier_fails_closed(): void
    {
        Storage::fake('business_documents');

        [$userA, $businessA] = $this->workspace('portability-a');
        [$userB, $businessB] = $this->workspace('portability-b');

        $export = $this->app->make(CreateBusinessExport::class)->execute(
            $userA,
            $businessA,
            ['business'],
        );

        self::assertNotNull($export);

        $available = $this->app
            ->make(GenerateBusinessExport::class)
            ->execute(
                $userA,
                $businessA,
                (string) $export->getKey(),
            );

        self::assertNotNull($available);

        self::assertNull(
            $this->app
                ->make(AuthorizeBusinessExportDownload::class)
                ->execute(
                    $userB,
                    $businessB,
                    (string) $available->getKey(),
                ),
        );
    }

    public function test_restricted_formal_record_existence_is_absent_from_manifest_and_exclusion_metadata(): void
    {
        [$user, $business] = $this->workspace(
            'portability-hidden-record',
        );

        $permission = Permission::query()
            ->where('key', 'conflict.view')
            ->sole();

        $membership = Membership::query()
            ->where('user_id', $user->getKey())
            ->where('business_id', $business->getKey())
            ->sole();

        PermissionGrant::query()->create([
            'business_id' => $business->getKey(),
            'membership_id' => $membership->getKey(),
            'permission_id' => $permission->getKey(),
            'effect' => PermissionEffect::Deny,
        ]);

        $secretSubjectId = (string) Str::uuid7();

        $family = FormalRecordFamily::query()->create([
            'business_id' => $business->getKey(),
            'record_type' => 'conflict_settlement',
            'subject_type' => 'conflict_case',
            'subject_id' => $secretSubjectId,
        ]);

        $version = FormalRecordVersion::query()->create([
            'business_id' => $business->getKey(),
            'formal_record_family_id' => $family->getKey(),
            'version_number' => 1,
            'predecessor_version_id' => null,
            'revision' => 1,
            'change_summary' => 'Highly Restricted Settlement Title',
            'created_by_user_id' => $user->getKey(),
            'last_changed_by_user_id' => $user->getKey(),
            'effective_from' => null,
            'effective_until' => null,
            'review_due_at' => null,
            'content_hash' => str_repeat('f', 64),
            'frozen_at' => now(),
        ]);

        $export = $this->app->make(CreateBusinessExport::class)->execute(
            $user,
            $business,
            ['formal_records'],
        );

        self::assertNotNull($export);

        $manifest = $export->frozen_manifest;
        self::assertIsArray($manifest);
        self::assertSame([], $manifest['sources']);
        self::assertEquals(
            [[
                'category' => 'business',
                'reason' => 'not_requested',
            ], [
                'category' => 'formal_records',
                'reason' => 'no_authorized_source_included',
            ], [
                'category' => 'documents',
                'reason' => 'not_requested',
            ], [
                'category' => 'ownership',
                'reason' => 'not_requested',
            ], [
                'category' => 'partners',
                'reason' => 'not_requested',
            ]],
            $manifest['excluded_categories'],
        );

        $serialized = json_encode($manifest, JSON_THROW_ON_ERROR);

        self::assertStringNotContainsString(
            (string) $family->getKey(),
            $serialized,
        );
        self::assertStringNotContainsString(
            (string) $version->getKey(),
            $serialized,
        );
        self::assertStringNotContainsString(
            $secretSubjectId,
            $serialized,
        );
        self::assertStringNotContainsString(
            'Highly Restricted Settlement Title',
            $serialized,
        );
        self::assertStringNotContainsString(
            str_repeat('f', 64),
            $serialized,
        );
    }

    /**
     * @return array{Document,DocumentAccessGrant}
     */
    private function document(
        Business $business,
        Membership $membership,
        string $title,
    ): array {
        $document = Document::query()->create([
            'business_id' => $business->getKey(),
            'title' => $title,
            'category' => DocumentCategory::CorporateLegal,
            'created_by_membership_id' => $membership->getKey(),
        ]);

        DocumentVersion::query()->create([
            'business_id' => $business->getKey(),
            'document_id' => $document->getKey(),
            'version_number' => 1,
            'original_filename' => 'restricted.pdf',
            'storage_key' => 'tests/source/'.Str::uuid7(),
            'size_bytes' => 128,
            'mime_type' => 'application/pdf',
            'content_sha256' => str_repeat('c', 64),
            'uploaded_by_membership_id' => $membership->getKey(),
            'effective_from' => now()->subMinute(),
            'supersedes_document_version_id' => null,
        ]);

        $grant = DocumentAccessGrant::query()->create([
            'business_id' => $business->getKey(),
            'membership_id' => $membership->getKey(),
            'document_id' => $document->getKey(),
            'right' => DocumentAccessRight::View,
            'effect' => PermissionEffect::Allow->value,
        ]);

        return [$document, $grant];
    }

    /** @return array{User,Business,Membership} */
    private function workspace(string $prefix): array
    {
        $business = Business::query()->create([
            'name' => 'F7 Portability Privacy '.Str::uuid7(),
            'origin_type' => 'started_through_pbr',
            'business_stage' => 'operating',
            'setup_phase' => 'formation',
            'workspace_status' => 'active',
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
