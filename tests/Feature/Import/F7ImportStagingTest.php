<?php

declare(strict_types=1);

namespace Tests\Feature\Import;

use App\Application\Access\ProvisionStandardAccessProfiles;
use App\Application\Import\CreateImportBatch;
use App\Application\Import\ParseImportBatch;
use App\Application\Import\ValidateImportBatch;
use App\Domain\Import\Enums\ImportBatchStatus;
use App\Domain\Import\Enums\ImportedRecordStatus;
use App\Infrastructure\Persistence\Eloquent\Businesses\Business;
use App\Infrastructure\Persistence\Eloquent\Identity\User;
use App\Infrastructure\Persistence\Eloquent\Import\ImportedRecord;
use App\Infrastructure\Persistence\Eloquent\Members\Membership;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Tests\TestCase;

final class F7ImportStagingTest extends TestCase
{
    use RefreshDatabase;

    public function test_csv_import_stages_parses_and_validates_without_creating_canonical_truth(): void
    {
        [$user, $business] = $this->workspace('import-csv');

        $source = implode("\n", [
            'source_record_key,display_name,legal_name,email,notes',
            'p-001,Alice Example,Alice Example,alice@example.test,Founding prospect',
            'p-002,Bob Example,,bob@example.test,Second prospect',
        ]);

        $batch = $this->app->make(CreateImportBatch::class)->execute(
            $user,
            $business,
            'csv',
            'legacy-crm',
            'partners.csv',
            $source,
            'partners-v1',
            'partner',
        );

        self::assertNotNull($batch);
        self::assertSame(ImportBatchStatus::Staged, $batch->status);
        self::assertSame(hash('sha256', $source), $batch->source_fingerprint);
        self::assertSame('pbr.csv', $batch->parser_identity);
        self::assertSame('1.0', $batch->parser_version);
        self::assertSame('partners-v1', $batch->schema_version);
        self::assertSame(0, DB::table('partners')->count());

        $parsed = $this->app->make(ParseImportBatch::class)->execute(
            $user,
            $business,
            (string) $batch->getKey(),
        );

        self::assertNotNull($parsed);
        self::assertSame(ImportBatchStatus::Parsed, $parsed->status);

        $records = ImportedRecord::query()
            ->where('business_id', $business->getKey())
            ->where('import_batch_id', $batch->getKey())
            ->orderBy('source_record_key')
            ->get();

        self::assertCount(2, $records);
        self::assertSame('p-001', $records[0]->source_record_key);
        self::assertSame(
            'Alice Example',
            $records[0]->observed_payload['display_name'],
        );
        self::assertSame(ImportedRecordStatus::Observed, $records[0]->status);
        self::assertSame(0, DB::table('partners')->count());

        $validated = $this->app->make(ValidateImportBatch::class)->execute(
            $user,
            $business,
            (string) $batch->getKey(),
        );

        self::assertNotNull($validated);
        self::assertSame(ImportBatchStatus::ReviewReady, $validated->status);
        self::assertSame(
            2,
            ImportedRecord::query()
                ->where('business_id', $business->getKey())
                ->where('import_batch_id', $batch->getKey())
                ->where('status', ImportedRecordStatus::Valid->value)
                ->count(),
        );

        self::assertSame(
            0,
            DB::table('partners')->count(),
            'Staging and validation must not create canonical Partner truth.',
        );
        self::assertSame(
            0,
            DB::table('ownership_register_versions')->count(),
            'Import staging must never mutate Ownership.',
        );
    }

    public function test_json_parser_preserves_unicode_user_data_without_translation(): void
    {
        [$user, $business] = $this->workspace('import-json');

        $source = json_encode([
            [
                'source_record_key' => 'json-001',
                'display_name' => 'မူရင်း မြန်မာအမည်',
                'legal_name' => null,
                'email' => 'burmese@example.test',
                'notes' => 'User ထည့်ထားသော မူရင်း data',
            ],
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);

        $batch = $this->app->make(CreateImportBatch::class)->execute(
            $user,
            $business,
            'json',
            'legacy-json',
            'partners.json',
            $source,
            'partners-v1',
            'partner',
        );

        self::assertNotNull($batch);

        $this->app->make(ParseImportBatch::class)->execute(
            $user,
            $business,
            (string) $batch->getKey(),
        );

        $record = ImportedRecord::query()
            ->where('business_id', $business->getKey())
            ->where('import_batch_id', $batch->getKey())
            ->sole();

        self::assertSame(
            'မူရင်း မြန်မာအမည်',
            $record->observed_payload['display_name'],
        );
        self::assertSame(
            'User ထည့်ထားသော မူရင်း data',
            $record->observed_payload['notes'],
        );

        $this->app->make(ValidateImportBatch::class)->execute(
            $user,
            $business,
            (string) $batch->getKey(),
        );

        $record->refresh();

        self::assertSame(
            'မူရင်း မြန်မာအမည်',
            $record->normalized_payload['display_name'],
        );
        self::assertSame(
            'User ထည့်ထားသော မူရင်း data',
            $record->normalized_payload['notes'],
        );
    }

    public function test_partner_import_rejects_ownership_or_authority_fields_as_explicit_validation_issues(): void
    {
        [$user, $business] = $this->workspace('import-restricted-fields');

        $source = json_encode([
            [
                'source_record_key' => 'restricted-001',
                'display_name' => 'Unsafe Imported Partner',
                'email' => 'unsafe@example.test',
                'ownership_percent' => 90,
                'governance_authority' => 'all',
            ],
        ], JSON_THROW_ON_ERROR);

        $batch = $this->app->make(CreateImportBatch::class)->execute(
            $user,
            $business,
            'json',
            'external-system',
            'unsafe.json',
            $source,
            'partners-v1',
            'partner',
        );

        self::assertNotNull($batch);

        $this->app->make(ParseImportBatch::class)->execute(
            $user,
            $business,
            (string) $batch->getKey(),
        );

        $this->app->make(ValidateImportBatch::class)->execute(
            $user,
            $business,
            (string) $batch->getKey(),
        );

        $record = ImportedRecord::query()
            ->where('import_batch_id', $batch->getKey())
            ->sole();

        self::assertSame(ImportedRecordStatus::Invalid, $record->status);

        $this->assertDatabaseHas('import_validation_results', [
            'business_id' => $business->getKey(),
            'imported_record_id' => $record->getKey(),
            'severity' => 'error',
            'code' => 'unsupported_field',
        ]);

        self::assertSame(0, DB::table('partners')->count());
        self::assertSame(0, DB::table('ownership_register_versions')->count());
        self::assertSame(0, DB::table('authority_snapshots')->count());
    }

    /** @return array{User,Business,Membership} */
    private function workspace(string $prefix): array
    {
        $business = Business::query()->create([
            'name' => 'F7 Import '.Str::uuid7(),
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
