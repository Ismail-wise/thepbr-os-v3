<?php

declare(strict_types=1);

namespace Tests\Feature\Import;

use App\Application\Access\ProvisionStandardAccessProfiles;
use App\Application\Import\ConfirmImportRecords;
use App\Application\Import\CreateImportBatch;
use App\Application\Import\ParseImportBatch;
use App\Application\Import\ValidateImportBatch;
use App\Domain\Import\Enums\ImportedRecordStatus;
use App\Infrastructure\Persistence\Eloquent\Businesses\Business;
use App\Infrastructure\Persistence\Eloquent\Identity\User;
use App\Infrastructure\Persistence\Eloquent\Import\ImportBatch;
use App\Infrastructure\Persistence\Eloquent\Import\ImportedRecord;
use App\Infrastructure\Persistence\Eloquent\Members\Membership;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Tests\TestCase;

final class F7ImportIdempotencyTest extends TestCase
{
    use RefreshDatabase;

    public function test_same_business_source_fingerprint_record_key_and_target_cannot_create_duplicate_partner_truth(): void
    {
        [$user, $business] = $this->workspace('import-idempotent');

        $source = json_encode([
            [
                'source_record_key' => 'partner-001',
                'display_name' => 'Idempotent Partner',
                'email' => 'idempotent@example.test',
            ],
        ], JSON_THROW_ON_ERROR);

        $first = $this->stageParseValidate(
            $user,
            $business,
            'legacy-crm',
            $source,
        );

        $firstRecord = ImportedRecord::query()
            ->where('import_batch_id', $first->getKey())
            ->sole();

        $result = $this->app->make(ConfirmImportRecords::class)->execute(
            $user,
            $business,
            (string) $first->getKey(),
            [(string) $firstRecord->getKey()],
        );

        self::assertNotNull($result);
        self::assertTrue($result['results'][0]['success']);
        self::assertSame(1, DB::table('partners')->count());

        $second = $this->stageParseValidate(
            $user,
            $business,
            'legacy-crm',
            $source,
        );

        $secondRecord = ImportedRecord::query()
            ->where('import_batch_id', $second->getKey())
            ->sole();

        self::assertSame(
            (string) $firstRecord->idempotency_key,
            (string) $secondRecord->idempotency_key,
        );
        self::assertSame(
            ImportedRecordStatus::Conflict,
            $secondRecord->status,
        );

        $this->assertDatabaseHas('import_validation_results', [
            'business_id' => $business->getKey(),
            'imported_record_id' => $secondRecord->getKey(),
            'code' => 'duplicate_import_identity',
            'severity' => 'error',
        ]);

        self::assertSame(
            1,
            DB::table('partners')->count(),
            'Duplicate import identity must not create duplicate canonical Partner truth.',
        );
    }

    public function test_source_system_is_part_of_the_deterministic_idempotency_identity(): void
    {
        [$user, $business] = $this->workspace('import-source-system');

        $source = json_encode([
            [
                'source_record_key' => 'shared-001',
                'display_name' => 'Same Payload Partner',
                'email' => 'same-payload@example.test',
            ],
        ], JSON_THROW_ON_ERROR);

        $crmBatch = $this->stageParseValidate(
            $user,
            $business,
            'legacy-crm',
            $source,
        );

        $erpBatch = $this->stageParseValidate(
            $user,
            $business,
            'legacy-erp',
            $source,
        );

        $crmRecord = ImportedRecord::query()
            ->where('import_batch_id', $crmBatch->getKey())
            ->sole();

        $erpRecord = ImportedRecord::query()
            ->where('import_batch_id', $erpBatch->getKey())
            ->sole();

        self::assertNotSame(
            (string) $crmRecord->idempotency_key,
            (string) $erpRecord->idempotency_key,
        );
        self::assertSame(ImportedRecordStatus::Valid, $crmRecord->status);
        self::assertSame(ImportedRecordStatus::Valid, $erpRecord->status);
    }

    private function stageParseValidate(
        User $user,
        Business $business,
        string $sourceSystem,
        string $source,
    ): ImportBatch {
        $batch = $this->app->make(CreateImportBatch::class)->execute(
            $user,
            $business,
            'json',
            $sourceSystem,
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

        $validated = $this->app->make(ValidateImportBatch::class)->execute(
            $user,
            $business,
            (string) $batch->getKey(),
        );

        self::assertNotNull($validated);

        return $validated;
    }

    /** @return array{User,Business,Membership} */
    private function workspace(string $prefix): array
    {
        $business = Business::query()->create([
            'name' => 'F7 Import Idempotency '.Str::uuid7(),
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
