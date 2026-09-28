<?php

declare(strict_types=1);

namespace Tests\Feature\Reporting;

use App\Application\Access\ProvisionStandardAccessProfiles;
use App\Application\Reporting\CreateBusinessPack;
use App\Application\Reporting\GenerateBusinessPack;
use App\Domain\Access\Enums\PermissionEffect;
use App\Domain\Documents\Enums\DocumentAccessRight;
use App\Domain\Documents\Enums\DocumentCategory;
use App\Domain\Identity\Enums\LanguageMode;
use App\Domain\Reporting\Enums\BusinessPackStatus;
use App\Infrastructure\Persistence\Eloquent\Businesses\Business;
use App\Infrastructure\Persistence\Eloquent\Documents\Document;
use App\Infrastructure\Persistence\Eloquent\Documents\DocumentAccessGrant;
use App\Infrastructure\Persistence\Eloquent\Documents\DocumentVersion;
use App\Infrastructure\Persistence\Eloquent\Identity\User;
use App\Infrastructure\Persistence\Eloquent\Members\Membership;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Tests\TestCase;

final class F7BusinessPackTest extends TestCase
{
    use RefreshDatabase;

    public function test_business_pack_freezes_exact_authorized_document_manifest_and_generates_private_representation(): void
    {
        Storage::fake('business_documents');

        [$user, $business, $membership] = $this->workspace('pack-main');
        [$document, $version] = $this->document(
            $business,
            $membership,
            'Board Agreement မူရင်း',
            str_repeat('a', 64),
        );

        $this->allowDocument($business, $membership, $document);

        $beforeDocuments = DB::table('documents')->count();
        $beforeFormalRecords = DB::table('formal_record_versions')->count();

        $export = $this->app->make(CreateBusinessPack::class)->execute(
            $user,
            $business,
            ['documents'],
            LanguageMode::Myanmar,
        );

        self::assertNotNull($export);
        self::assertSame(
            BusinessPackStatus::ManifestFrozen,
            $export->status,
        );

        $manifest = $export->frozen_manifest;
        self::assertIsArray($manifest);
        self::assertSame(
            (string) $business->getKey(),
            $manifest['business']['id'],
        );
        self::assertSame(
            (string) $membership->getKey(),
            $manifest['requester']['membership_id'],
        );
        self::assertSame('my', $manifest['output_language']);
        self::assertSame(['documents'], $manifest['requested_scope']);
        self::assertCount(1, $manifest['sources']);
        self::assertSame(
            'document_version',
            $manifest['sources'][0]['source_kind'],
        );
        self::assertSame(
            (string) $version->getKey(),
            $manifest['sources'][0]['source_id'],
        );
        self::assertSame(
            (string) $document->getKey(),
            $manifest['sources'][0]['container_id'],
        );
        self::assertSame(
            str_repeat('a', 64),
            $manifest['sources'][0]['source_hash'],
        );
        self::assertSame(
            'Board Agreement မူရင်း',
            $manifest['sources'][0]['label'],
        );
        self::assertSame([], $manifest['explicit_exclusions']);

        $generated = $this->app->make(GenerateBusinessPack::class)->execute(
            $user,
            $business,
            (string) $export->getKey(),
        );

        self::assertNotNull($generated);
        self::assertSame(BusinessPackStatus::Available, $generated->status);
        self::assertNotNull($generated->content_sha256);
        self::assertNotNull($generated->manifest_hash);
        self::assertNotNull($generated->available_at);
        Storage::disk('business_documents')->assertExists(
            (string) $generated->storage_key,
        );

        self::assertSame(
            $beforeDocuments,
            DB::table('documents')->count(),
            'Business Pack generation must not alter Document truth.',
        );
        self::assertSame(
            $beforeFormalRecords,
            DB::table('formal_record_versions')->count(),
            'Business Pack generation must not create canonical Formal truth.',
        );

        self::assertDatabaseHas('business_pack_export_transitions', [
            'business_id' => $business->getKey(),
            'business_pack_export_id' => $generated->getKey(),
            'to_status' => 'available',
        ]);
    }

    public function test_business_pack_rejects_include_everything_shortcuts(): void
    {
        [$user, $business] = $this->workspace('pack-explicit');

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('explicit supported categories');

        $this->app->make(CreateBusinessPack::class)->execute(
            $user,
            $business,
            ['all'],
            LanguageMode::English,
        );
    }

    /** @return array{User,Business,Membership} */
    private function workspace(string $prefix): array
    {
        $business = Business::query()->create([
            'name' => 'F7 Reporting '.Str::uuid7(),
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

    /** @return array{Document,DocumentVersion} */
    private function document(
        Business $business,
        Membership $membership,
        string $title,
        string $hash,
    ): array {
        $document = Document::query()->create([
            'business_id' => $business->getKey(),
            'title' => $title,
            'category' => DocumentCategory::AgreementsContracts,
            'created_by_membership_id' => $membership->getKey(),
        ]);

        $version = DocumentVersion::query()->create([
            'business_id' => $business->getKey(),
            'document_id' => $document->getKey(),
            'version_number' => 1,
            'original_filename' => 'board-agreement-original.pdf',
            'storage_key' => 'tests/source/'.Str::uuid7(),
            'size_bytes' => 128,
            'mime_type' => 'application/pdf',
            'content_sha256' => $hash,
            'uploaded_by_membership_id' => $membership->getKey(),
            'effective_from' => now()->subMinute(),
            'supersedes_document_version_id' => null,
        ]);

        return [$document, $version];
    }

    private function allowDocument(
        Business $business,
        Membership $membership,
        Document $document,
    ): void {
        DocumentAccessGrant::query()->create([
            'business_id' => $business->getKey(),
            'membership_id' => $membership->getKey(),
            'document_id' => $document->getKey(),
            'right' => DocumentAccessRight::View,
            'effect' => PermissionEffect::Allow->value,
        ]);
    }
}
