<?php

declare(strict_types=1);

namespace Tests\Feature\Portability;

use App\Application\Access\ProvisionStandardAccessProfiles;
use App\Application\Portability\ChangeWorkspaceArchiveState;
use App\Application\Portability\CreateBusinessExport;
use App\Application\Portability\GenerateBusinessExport;
use App\Domain\Documents\Enums\DocumentCategory;
use App\Infrastructure\Persistence\Eloquent\Businesses\Business;
use App\Infrastructure\Persistence\Eloquent\Documents\Document;
use App\Infrastructure\Persistence\Eloquent\Documents\DocumentVersion;
use App\Infrastructure\Persistence\Eloquent\Identity\User;
use App\Infrastructure\Persistence\Eloquent\Members\Membership;
use App\Infrastructure\Persistence\Eloquent\Portability\BusinessArchiveTransition;
use App\Infrastructure\Persistence\Eloquent\Records\FormalRecordFamily;
use App\Infrastructure\Persistence\Eloquent\Records\FormalRecordVersion;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

final class F7PortabilityHistoryTest extends TestCase
{
    use RefreshDatabase;

    public function test_archive_preserves_canonical_and_historical_tables_in_place(): void
    {
        [$user, $business, $membership] = $this->workspace(
            'archive-history',
        );

        $family = FormalRecordFamily::query()->create([
            'business_id' => $business->getKey(),
            'record_type' => 'archive_history_policy',
            'subject_type' => 'business',
            'subject_id' => (string) $business->getKey(),
        ]);

        $version = FormalRecordVersion::query()->create([
            'business_id' => $business->getKey(),
            'formal_record_family_id' => $family->getKey(),
            'version_number' => 1,
            'predecessor_version_id' => null,
            'revision' => 1,
            'change_summary' => 'Archive history fixture',
            'created_by_user_id' => $user->getKey(),
            'last_changed_by_user_id' => $user->getKey(),
            'effective_from' => null,
            'effective_until' => null,
            'review_due_at' => null,
            'content_hash' => str_repeat('1', 64),
            'frozen_at' => now(),
        ]);

        $document = Document::query()->create([
            'business_id' => $business->getKey(),
            'title' => 'Archive Preserved Document',
            'category' => DocumentCategory::CorporateLegal,
            'created_by_membership_id' => $membership->getKey(),
        ]);

        $documentVersion = DocumentVersion::query()->create([
            'business_id' => $business->getKey(),
            'document_id' => $document->getKey(),
            'version_number' => 1,
            'original_filename' => 'archive-preserved.pdf',
            'storage_key' => 'tests/source/'.Str::uuid7(),
            'size_bytes' => 100,
            'mime_type' => 'application/pdf',
            'content_sha256' => str_repeat('2', 64),
            'uploaded_by_membership_id' => $membership->getKey(),
            'effective_from' => null,
            'supersedes_document_version_id' => null,
        ]);

        $partnerId = (string) Str::uuid7();

        DB::table('partners')->insert([
            'id' => $partnerId,
            'business_id' => $business->getKey(),
            'display_name' => 'Archive Preserved Partner',
            'legal_name' => null,
            'email' => null,
            'status' => 'prospective',
            'notes' => null,
            'revision' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $before = $this->historyCounts();

        $archived = $this->app
            ->make(ChangeWorkspaceArchiveState::class)
            ->archive(
                $user,
                $business,
                'Archive while preserving all business history.',
            );

        self::assertNotNull($archived);

        self::assertTrue(
            FormalRecordVersion::query()
                ->whereKey($version->getKey())
                ->exists(),
        );
        self::assertTrue(
            DocumentVersion::query()
                ->whereKey($documentVersion->getKey())
                ->exists(),
        );
        self::assertTrue(
            DB::table('partners')
                ->where('id', $partnerId)
                ->exists(),
        );
        self::assertTrue(
            Membership::query()
                ->whereKey($membership->getKey())
                ->exists(),
        );

        $after = $this->historyCounts();

        foreach ($before as $table => $count) {
            self::assertGreaterThanOrEqual(
                $count,
                $after[$table],
                'Archive must not delete history from '.$table.'.',
            );
        }
    }

    public function test_archive_transition_history_is_database_append_only(): void
    {
        [$user, $business] = $this->workspace(
            'archive-append-only',
        );

        $this->app->make(ChangeWorkspaceArchiveState::class)->archive(
            $user,
            $business,
            'Create append-only archive transition.',
        );

        $transition = BusinessArchiveTransition::query()
            ->where('business_id', $business->getKey())
            ->sole();

        $this->expectException(QueryException::class);
        $this->expectExceptionMessage(
            'Business Archive transition history is append-only',
        );

        DB::table('business_archive_transitions')
            ->where('id', $transition->getKey())
            ->update([
                'reason' => 'tampered',
            ]);
    }

    public function test_available_portability_export_is_database_immutable(): void
    {
        Storage::fake('business_documents');

        [$user, $business] = $this->workspace(
            'export-immutable',
        );

        $export = $this->app->make(CreateBusinessExport::class)->execute(
            $user,
            $business,
            ['business'],
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

        $this->expectException(QueryException::class);
        $this->expectExceptionMessage(
            'Available Portability Export is immutable',
        );

        DB::table('business_portability_exports')
            ->where('id', $available->getKey())
            ->update([
                'status' => 'failed',
                'updated_at' => now(),
            ]);
    }

    /** @return array<string,int> */
    private function historyCounts(): array
    {
        $tables = [
            'formal_record_versions',
            'record_version_state_transitions',
            'audit_events',
            'business_events',
            'authority_snapshots',
            'votes',
            'approvals',
            'signature_requests',
            'document_versions',
            'evidence',
            'evidence_links',
            'memberships',
            'partners',
            'operations_role_assignments',
            'ownership_register_versions',
        ];

        $counts = [];

        foreach ($tables as $table) {
            $counts[$table] = DB::table($table)->count();
        }

        return $counts;
    }

    /** @return array{User,Business,Membership} */
    private function workspace(string $prefix): array
    {
        $business = Business::query()->create([
            'name' => 'F7 Portability History '.Str::uuid7(),
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
