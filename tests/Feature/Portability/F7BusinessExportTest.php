<?php

declare(strict_types=1);

namespace Tests\Feature\Portability;

use App\Application\Access\ProvisionStandardAccessProfiles;
use App\Application\Portability\CreateBusinessExport;
use App\Application\Portability\GenerateBusinessExport;
use App\Domain\Access\Enums\PermissionEffect;
use App\Domain\Documents\Enums\DocumentAccessRight;
use App\Domain\Documents\Enums\DocumentCategory;
use App\Domain\Portability\Enums\BusinessExportStatus;
use App\Infrastructure\Persistence\Eloquent\Businesses\Business;
use App\Infrastructure\Persistence\Eloquent\Documents\Document;
use App\Infrastructure\Persistence\Eloquent\Documents\DocumentAccessGrant;
use App\Infrastructure\Persistence\Eloquent\Documents\DocumentVersion;
use App\Infrastructure\Persistence\Eloquent\Identity\User;
use App\Infrastructure\Persistence\Eloquent\Members\Membership;
use App\Infrastructure\Persistence\Eloquent\Records\FormalRecordFamily;
use App\Infrastructure\Persistence\Eloquent\Records\FormalRecordVersion;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Tests\TestCase;

final class F7BusinessExportTest extends TestCase
{
    use RefreshDatabase;

    public function test_portability_export_freezes_self_describing_authorized_manifest_and_private_json(): void
    {
        Storage::fake('business_documents');

        [$user, $business, $membership] = $this->workspace(
            'portability-export',
        );

        $family = FormalRecordFamily::query()->create([
            'business_id' => $business->getKey(),
            'record_type' => 'portable_policy',
            'subject_type' => 'business',
            'subject_id' => (string) $business->getKey(),
        ]);

        $version = FormalRecordVersion::query()->create([
            'business_id' => $business->getKey(),
            'formal_record_family_id' => $family->getKey(),
            'version_number' => 1,
            'predecessor_version_id' => null,
            'revision' => 1,
            'change_summary' => 'Portable policy version',
            'created_by_user_id' => $user->getKey(),
            'last_changed_by_user_id' => $user->getKey(),
            'effective_from' => null,
            'effective_until' => null,
            'review_due_at' => null,
            'content_hash' => str_repeat('a', 64),
            'frozen_at' => now(),
        ]);

        $document = Document::query()->create([
            'business_id' => $business->getKey(),
            'title' => 'မူရင်း Business Contract',
            'category' => DocumentCategory::AgreementsContracts,
            'created_by_membership_id' => $membership->getKey(),
        ]);

        $documentVersion = DocumentVersion::query()->create([
            'business_id' => $business->getKey(),
            'document_id' => $document->getKey(),
            'version_number' => 1,
            'original_filename' => 'မူရင်း-contract.pdf',
            'storage_key' => 'tests/source/'.Str::uuid7(),
            'size_bytes' => 321,
            'mime_type' => 'application/pdf',
            'content_sha256' => str_repeat('b', 64),
            'uploaded_by_membership_id' => $membership->getKey(),
            'effective_from' => now()->subMinute(),
            'supersedes_document_version_id' => null,
        ]);

        DocumentAccessGrant::query()->create([
            'business_id' => $business->getKey(),
            'membership_id' => $membership->getKey(),
            'document_id' => $document->getKey(),
            'right' => DocumentAccessRight::View,
            'effect' => PermissionEffect::Allow->value,
        ]);

        $partnerId = (string) Str::uuid7();

        DB::table('partners')->insert([
            'id' => $partnerId,
            'business_id' => $business->getKey(),
            'display_name' => 'မူရင်း Partner Name',
            'legal_name' => null,
            'email' => 'portable@example.test',
            'status' => 'prospective',
            'notes' => 'User-entered partner note',
            'revision' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $export = $this->app->make(CreateBusinessExport::class)->execute(
            $user,
            $business,
            [
                'business',
                'formal_records',
                'documents',
                'partners',
            ],
        );

        self::assertNotNull($export);
        self::assertSame(
            BusinessExportStatus::ManifestFrozen,
            $export->status,
        );

        $manifest = $export->frozen_manifest;
        self::assertIsArray($manifest);
        self::assertSame(
            'pbr-business-portability-v1',
            $manifest['schema_version'],
        );
        self::assertSame(
            (string) $business->getKey(),
            $manifest['business_id'],
        );
        self::assertSame(
            (string) $membership->getKey(),
            $manifest['requester']['membership_id'],
        );
        self::assertSame(
            [
                'business',
                'formal_records',
                'documents',
                'partners',
            ],
            $manifest['included_categories'],
        );

        $sources = collect($manifest['sources']);

        $formalSource = $sources->firstWhere(
            'source_kind',
            'formal_record_version',
        );
        self::assertIsArray($formalSource);
        self::assertSame(
            (string) $family->getKey(),
            $formalSource['record_id'],
        );
        self::assertSame(
            (string) $version->getKey(),
            $formalSource['version_id'],
        );
        self::assertSame(str_repeat('a', 64), $formalSource['source_hash']);

        $documentSource = $sources->firstWhere(
            'source_kind',
            'document_version',
        );
        self::assertIsArray($documentSource);
        self::assertSame(
            (string) $document->getKey(),
            $documentSource['document_id'],
        );
        self::assertSame(
            (string) $documentVersion->getKey(),
            $documentSource['version_id'],
        );
        self::assertSame(
            str_repeat('b', 64),
            $documentSource['source_hash'],
        );

        $partnerSource = $sources->firstWhere(
            'source_kind',
            'partner_snapshot',
        );
        self::assertIsArray($partnerSource);
        self::assertSame($partnerId, $partnerSource['source_id']);

        $generated = $this->app
            ->make(GenerateBusinessExport::class)
            ->execute(
                $user,
                $business,
                (string) $export->getKey(),
            );

        self::assertNotNull($generated);
        self::assertSame(
            BusinessExportStatus::Available,
            $generated->status,
        );
        self::assertNotNull($generated->manifest_hash);
        self::assertNotNull($generated->content_sha256);

        Storage::disk('business_documents')->assertExists(
            (string) $generated->storage_key,
        );

        $stored = Storage::disk('business_documents')->get(
            (string) $generated->storage_key,
        );

        self::assertSame(
            hash('sha256', $stored),
            (string) $generated->content_sha256,
        );
        self::assertStringContainsString(
            'မူရင်း Business Contract',
            $stored,
        );
        self::assertStringContainsString(
            'မူရင်း Partner Name',
            $stored,
        );
        self::assertStringNotContainsString(
            (string) $user->password,
            $stored,
            'Portability Export must never contain password hashes.',
        );
        self::assertStringNotContainsString(
            'PBR_DOCUMENTS_S3_SECRET',
            $stored,
        );
    }

    public function test_portability_export_requires_explicit_categories_and_rejects_export_everything_shortcut(): void
    {
        [$user, $business] = $this->workspace(
            'portability-explicit',
        );

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('explicit supported categories');

        $this->app->make(CreateBusinessExport::class)->execute(
            $user,
            $business,
            ['all'],
        );
    }

    /** @return array{User,Business,Membership} */
    private function workspace(string $prefix): array
    {
        $business = Business::query()->create([
            'name' => 'F7 Portability Export '.Str::uuid7(),
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
