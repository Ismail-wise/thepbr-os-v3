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
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

final class F7BusinessPackPrivacyTest extends TestCase
{
    use RefreshDatabase;

    public function test_later_document_access_revocation_blocks_existing_pack_download(): void
    {
        Storage::fake('business_documents');

        [$user, $business, $membership] = $this->workspace(
            'report-revoke',
        );

        [$document] = $this->document(
            $business,
            $membership,
            'Private Litigation File',
        );

        $grant = $this->allowDocument(
            $business,
            $membership,
            $document,
        );

        $export = $this->app->make(CreateBusinessPack::class)->execute(
            $user,
            $business,
            ['documents'],
            LanguageMode::English,
        );

        self::assertNotNull($export);

        $available = $this->app->make(GenerateBusinessPack::class)->execute(
            $user,
            $business,
            (string) $export->getKey(),
        );

        self::assertNotNull($available);
        self::assertSame(
            BusinessPackStatus::Available,
            $available->status,
        );

        self::assertNotNull(
            $this->app->make(AuthorizeBusinessPackDownload::class)->execute(
                $user,
                $business,
                (string) $available->getKey(),
            ),
        );

        $grant->effect = PermissionEffect::Deny->value;
        $grant->save();

        self::assertNull(
            $this->app->make(AuthorizeBusinessPackDownload::class)->execute(
                $user,
                $business,
                (string) $available->getKey(),
            ),
            'A stale generated export must not bypass later Document access revocation.',
        );
    }

    public function test_cross_business_export_identifier_fails_closed(): void
    {
        Storage::fake('business_documents');

        [$userA, $businessA, $membershipA] = $this->workspace(
            'report-a',
        );
        [$userB, $businessB] = $this->workspace('report-b');

        [$document] = $this->document(
            $businessA,
            $membershipA,
            'Tenant A Agreement',
        );
        $this->allowDocument($businessA, $membershipA, $document);

        $export = $this->app->make(CreateBusinessPack::class)->execute(
            $userA,
            $businessA,
            ['documents'],
            LanguageMode::English,
        );

        self::assertNotNull($export);

        $available = $this->app->make(GenerateBusinessPack::class)->execute(
            $userA,
            $businessA,
            (string) $export->getKey(),
        );

        self::assertNotNull($available);

        self::assertNull(
            $this->app->make(AuthorizeBusinessPackDownload::class)->execute(
                $userB,
                $businessB,
                (string) $available->getKey(),
            ),
        );
    }

    public function test_hidden_document_existence_does_not_leak_into_manifest_or_exclusions(): void
    {
        [$user, $business, $membership] = $this->workspace(
            'report-hidden',
        );

        [$hiddenDocument, $hiddenVersion] = $this->document(
            $business,
            $membership,
            'Top Secret Acquisition Plan',
        );

        $export = $this->app->make(CreateBusinessPack::class)->execute(
            $user,
            $business,
            ['documents'],
            LanguageMode::English,
        );

        self::assertNotNull($export);

        $manifest = $export->frozen_manifest;
        self::assertIsArray($manifest);
        self::assertSame([], $manifest['sources']);
        self::assertSame(
            [[
                'scope' => 'documents',
                'reason' => 'no_authorized_source_included',
            ]],
            $manifest['explicit_exclusions'],
        );

        $serialized = json_encode($manifest, JSON_THROW_ON_ERROR);

        self::assertStringNotContainsString(
            (string) $hiddenDocument->getKey(),
            $serialized,
        );
        self::assertStringNotContainsString(
            (string) $hiddenVersion->getKey(),
            $serialized,
        );
        self::assertStringNotContainsString(
            'Top Secret Acquisition Plan',
            $serialized,
        );
    }

    /** @return array{User,Business,Membership} */
    private function workspace(string $prefix): array
    {
        $business = Business::query()->create([
            'name' => 'F7 Reporting Privacy '.Str::uuid7(),
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
    ): array {
        $document = Document::query()->create([
            'business_id' => $business->getKey(),
            'title' => $title,
            'category' => DocumentCategory::CorporateLegal,
            'created_by_membership_id' => $membership->getKey(),
        ]);

        $version = DocumentVersion::query()->create([
            'business_id' => $business->getKey(),
            'document_id' => $document->getKey(),
            'version_number' => 1,
            'original_filename' => 'private-source.pdf',
            'storage_key' => 'tests/source/'.Str::uuid7(),
            'size_bytes' => 128,
            'mime_type' => 'application/pdf',
            'content_sha256' => str_repeat('b', 64),
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
    ): DocumentAccessGrant {
        return DocumentAccessGrant::query()->create([
            'business_id' => $business->getKey(),
            'membership_id' => $membership->getKey(),
            'document_id' => $document->getKey(),
            'right' => DocumentAccessRight::View,
            'effect' => PermissionEffect::Allow->value,
        ]);
    }
}
