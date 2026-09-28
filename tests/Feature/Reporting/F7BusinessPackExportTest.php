<?php

declare(strict_types=1);

namespace Tests\Feature\Reporting;

use App\Application\Access\ProvisionStandardAccessProfiles;
use App\Application\Reporting\AuthorizeBusinessPackDownload;
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
use App\Infrastructure\Persistence\Eloquent\Reporting\BusinessPackExport;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

final class F7BusinessPackExportTest extends TestCase
{
    use RefreshDatabase;

    public function test_generated_output_hash_matches_private_object_and_preserves_user_entered_utf8(): void
    {
        Storage::fake('business_documents');

        [$user, $business, $membership] = $this->workspace(
            'report-export',
        );

        $document = Document::query()->create([
            'business_id' => $business->getKey(),
            'title' => 'မြန်မာ User Entered Contract Title',
            'category' => DocumentCategory::AgreementsContracts,
            'created_by_membership_id' => $membership->getKey(),
        ]);

        $version = DocumentVersion::query()->create([
            'business_id' => $business->getKey(),
            'document_id' => $document->getKey(),
            'version_number' => 1,
            'original_filename' => 'မြန်မာ-original.pdf',
            'storage_key' => 'tests/source/'.Str::uuid7(),
            'size_bytes' => 256,
            'mime_type' => 'application/pdf',
            'content_sha256' => str_repeat('c', 64),
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

        $export = $this->app->make(CreateBusinessPack::class)->execute(
            $user,
            $business,
            ['documents'],
            LanguageMode::Myanmar,
        );

        self::assertNotNull($export);
        self::assertSame(
            (string) $version->getKey(),
            $export->frozen_manifest['sources'][0]['source_id'],
        );
        self::assertSame(
            str_repeat('c', 64),
            $export->frozen_manifest['sources'][0]['source_hash'],
        );

        $available = $this->app->make(GenerateBusinessPack::class)->execute(
            $user,
            $business,
            (string) $export->getKey(),
        );

        self::assertNotNull($available);
        self::assertSame(BusinessPackStatus::Available, $available->status);

        $stored = Storage::disk('business_documents')->get(
            (string) $available->storage_key,
        );

        self::assertSame(
            hash('sha256', $stored),
            (string) $available->content_sha256,
        );
        self::assertSame(strlen($stored), (int) $available->size_bytes);
        self::assertStringContainsString(
            'မြန်မာ User Entered Contract Title',
            $stored,
            'User-entered data must remain unchanged rather than auto-translated.',
        );
        self::assertStringContainsString(
            'ဤဖိုင်သည် frozen manifest',
            $stored,
            'OS-generated labels/content may follow the selected Myanmar output mode.',
        );

        $download = $this->app
            ->make(AuthorizeBusinessPackDownload::class)
            ->execute(
                $user,
                $business,
                (string) $available->getKey(),
            );

        self::assertNotNull($download);
        self::assertTrue(is_resource($download['stream']));
        $downloaded = stream_get_contents($download['stream']);
        fclose($download['stream']);

        self::assertIsString($downloaded);
        self::assertSame(
            (string) $available->content_sha256,
            hash('sha256', $downloaded),
        );
    }

    public function test_frozen_manifest_is_database_immutable(): void
    {
        [$user, $business, $membership] = $this->workspace(
            'report-frozen-immutable',
        );

        $export = $this->documentPack(
            $user,
            $business,
            $membership,
            'Frozen Manifest Source',
            str_repeat('d', 64),
        );

        $this->expectException(QueryException::class);
        $this->expectExceptionMessage(
            'Frozen Business Pack manifest is immutable',
        );

        DB::table('business_pack_exports')
            ->where('id', $export->getKey())
            ->update([
                'frozen_manifest' => json_encode(
                    ['tampered' => true],
                    JSON_THROW_ON_ERROR,
                ),
                'updated_at' => now(),
            ]);
    }

    public function test_available_export_is_database_immutable(): void
    {
        Storage::fake('business_documents');

        [$user, $business, $membership] = $this->workspace(
            'report-available-immutable',
        );

        $export = $this->documentPack(
            $user,
            $business,
            $membership,
            'Available Pack Source',
            str_repeat('e', 64),
        );

        $available = $this->app->make(GenerateBusinessPack::class)->execute(
            $user,
            $business,
            (string) $export->getKey(),
        );

        self::assertNotNull($available);

        $this->expectException(QueryException::class);
        $this->expectExceptionMessage(
            'Available Business Pack export is immutable',
        );

        DB::table('business_pack_exports')
            ->where('id', $available->getKey())
            ->update([
                'status' => 'failed',
                'updated_at' => now(),
            ]);
    }

    private function documentPack(
        User $user,
        Business $business,
        Membership $membership,
        string $title,
        string $contentHash,
    ): BusinessPackExport {
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
            'original_filename' => 'immutable.pdf',
            'storage_key' => 'tests/source/'.Str::uuid7(),
            'size_bytes' => 64,
            'mime_type' => 'application/pdf',
            'content_sha256' => $contentHash,
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

        $export = $this->app->make(CreateBusinessPack::class)->execute(
            $user,
            $business,
            ['documents'],
            LanguageMode::English,
        );

        self::assertNotNull($export);

        return $export;
    }

    /** @return array{User,Business,Membership} */
    private function workspace(string $prefix): array
    {
        $business = Business::query()->create([
            'name' => 'F7 Reporting Export '.Str::uuid7(),
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
