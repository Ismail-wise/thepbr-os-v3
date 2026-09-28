<?php

declare(strict_types=1);

namespace Tests\Feature\Import;

use App\Application\Access\ProvisionStandardAccessProfiles;
use App\Application\Import\ConfirmImportRecords;
use App\Application\Import\CreateImportBatch;
use App\Application\Import\ParseImportBatch;
use App\Application\Import\ValidateImportBatch;
use App\Domain\Import\Enums\ImportBatchStatus;
use App\Domain\Import\Enums\ImportedRecordStatus;
use App\Infrastructure\Persistence\Eloquent\Businesses\Business;
use App\Infrastructure\Persistence\Eloquent\Identity\User;
use App\Infrastructure\Persistence\Eloquent\Import\ImportBatch;
use App\Infrastructure\Persistence\Eloquent\Import\ImportedRecord;
use App\Infrastructure\Persistence\Eloquent\Members\Membership;
use App\Infrastructure\Persistence\Eloquent\Records\FormalRecordFamily;
use App\Infrastructure\Persistence\Eloquent\Records\FormalRecordVersion;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Tests\TestCase;

final class F7ImportConfirmationTest extends TestCase
{
    use RefreshDatabase;

    public function test_partner_confirmation_routes_through_partner_domain_without_access_ownership_or_governance_side_effects(): void
    {
        [$user, $business] = $this->workspace('import-confirm-partner');

        $batch = $this->stageParseValidate(
            $user,
            $business,
            'partner',
            [[
                'source_record_key' => 'partner-confirm-001',
                'display_name' => 'Imported Prospective Partner',
                'legal_name' => 'Imported Prospective Partner Legal',
                'email' => 'IMPORTED@EXAMPLE.TEST',
                'notes' => 'Observed external record',
            ]],
        );

        $record = ImportedRecord::query()
            ->where('import_batch_id', $batch->getKey())
            ->sole();

        self::assertSame(ImportedRecordStatus::Valid, $record->status);

        $membershipCount = DB::table('memberships')->count();
        $ownershipCount = DB::table('ownership_register_versions')->count();
        $authorityCount = DB::table('authority_snapshots')->count();

        $result = $this->app->make(ConfirmImportRecords::class)->execute(
            $user,
            $business,
            (string) $batch->getKey(),
            [(string) $record->getKey()],
        );

        self::assertNotNull($result);
        self::assertTrue($result['results'][0]['success']);
        self::assertSame('confirmed', $result['results'][0]['status']);
        self::assertSame(
            ImportBatchStatus::Completed,
            $result['batch']->status,
        );

        $partner = DB::table('partners')
            ->where('business_id', $business->getKey())
            ->sole();

        self::assertSame('prospective', $partner->status);
        self::assertSame('imported@example.test', $partner->email);
        self::assertSame(
            'Imported Prospective Partner',
            $partner->display_name,
        );

        $record->refresh();

        self::assertSame(ImportedRecordStatus::Confirmed, $record->status);
        self::assertSame('partner', $record->canonical_resource_type);
        self::assertSame((string) $partner->id, $record->canonical_resource_id);

        self::assertSame(
            $membershipCount,
            DB::table('memberships')->count(),
            'Partner import must not create Membership access.',
        );
        self::assertSame(
            $ownershipCount,
            DB::table('ownership_register_versions')->count(),
            'Partner import must not mutate Ownership.',
        );
        self::assertSame(
            $authorityCount,
            DB::table('authority_snapshots')->count(),
            'Partner import must not create Governance authority.',
        );
    }

    public function test_effective_record_collision_creates_draft_amendment_and_never_overwrites_effective_truth(): void
    {
        [$user, $business] = $this->workspace('import-effective-amendment');

        $source = $this->effectiveRecord(
            $user,
            $business,
            str_repeat('a', 64),
        );

        $batch = $this->stageParseValidate(
            $user,
            $business,
            'formal_record_amendment',
            [[
                'source_record_key' => 'amendment-001',
                'source_version_id' => (string) $source->getKey(),
                'content_hash' => str_repeat('b', 64),
                'change_summary' => 'Imported proposed amendment',
            ]],
        );

        $record = ImportedRecord::query()
            ->where('import_batch_id', $batch->getKey())
            ->sole();

        self::assertSame(ImportedRecordStatus::Valid, $record->status);

        $this->assertDatabaseHas('import_validation_results', [
            'business_id' => $business->getKey(),
            'imported_record_id' => $record->getKey(),
            'severity' => 'info',
            'code' => 'effective_truth_preserved_by_amendment',
        ]);

        $result = $this->app->make(ConfirmImportRecords::class)->execute(
            $user,
            $business,
            (string) $batch->getKey(),
            [(string) $record->getKey()],
        );

        self::assertNotNull($result);
        self::assertTrue($result['results'][0]['success']);

        $source->refresh();

        self::assertSame(str_repeat('a', 64), $source->content_hash);
        self::assertNotNull($source->frozen_at);

        $sourceLatestState = DB::table('record_version_state_transitions')
            ->where('formal_record_version_id', $source->getKey())
            ->orderByDesc('sequence')
            ->value('to_state');

        self::assertSame('effective', $sourceLatestState);

        $amendment = FormalRecordVersion::query()
            ->where('business_id', $business->getKey())
            ->where('formal_record_family_id', $source->formal_record_family_id)
            ->where('predecessor_version_id', $source->getKey())
            ->sole();

        self::assertSame(2, (int) $amendment->version_number);
        self::assertSame(str_repeat('b', 64), $amendment->content_hash);
        self::assertNull($amendment->frozen_at);
        self::assertNull($amendment->effective_from);

        $amendmentState = DB::table('record_version_state_transitions')
            ->where('formal_record_version_id', $amendment->getKey())
            ->orderByDesc('sequence')
            ->value('to_state');

        self::assertSame(
            'draft',
            $amendmentState,
            'Imported formal truth must enter the normal Draft workflow.',
        );

        $effectiveHeadId = DB::table('record_family_effective_heads')
            ->where(
                'formal_record_family_id',
                $source->formal_record_family_id,
            )
            ->value('formal_record_version_id');

        self::assertSame(
            (string) $source->getKey(),
            (string) $effectiveHeadId,
            'Import must not replace the Current Effective Record head.',
        );
    }

    /**
     * @param  list<array<string,mixed>>  $rows
     */
    private function stageParseValidate(
        User $user,
        Business $business,
        string $target,
        array $rows,
    ): ImportBatch {
        $source = json_encode(
            $rows,
            JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE,
        );

        $batch = $this->app->make(CreateImportBatch::class)->execute(
            $user,
            $business,
            'json',
            'legacy-system',
            'import.json',
            $source,
            'schema-v1',
            $target,
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

    private function effectiveRecord(
        User $user,
        Business $business,
        string $hash,
    ): FormalRecordVersion {
        $family = FormalRecordFamily::query()->create([
            'business_id' => $business->getKey(),
            'record_type' => 'import_test_policy',
            'subject_type' => 'business',
            'subject_id' => (string) $business->getKey(),
        ]);

        $version = FormalRecordVersion::query()->create([
            'business_id' => $business->getKey(),
            'formal_record_family_id' => $family->getKey(),
            'version_number' => 1,
            'predecessor_version_id' => null,
            'revision' => 1,
            'change_summary' => 'Existing Effective import collision fixture',
            'created_by_user_id' => $user->getKey(),
            'last_changed_by_user_id' => $user->getKey(),
            'effective_from' => now()->subMinute(),
            'effective_until' => null,
            'review_due_at' => null,
            'content_hash' => $hash,
            'frozen_at' => now()->subMinute(),
        ]);

        foreach ([
            [null, 'draft'],
            ['draft', 'ready_for_review'],
            ['ready_for_review', 'under_review'],
            ['under_review', 'approved'],
            ['approved', 'ready_for_effect'],
            ['ready_for_effect', 'effective'],
        ] as $index => [$from, $to]) {
            DB::table('record_version_state_transitions')->insert([
                'id' => (string) Str::uuid7(),
                'business_id' => $business->getKey(),
                'formal_record_version_id' => $version->getKey(),
                'sequence' => $index + 1,
                'from_state' => $from,
                'to_state' => $to,
                'transitioned_by_user_id' => $user->getKey(),
                'occurred_at' => now(),
                'created_at' => now(),
            ]);
        }

        DB::table('record_family_effective_heads')->insert([
            'id' => (string) Str::uuid7(),
            'business_id' => $business->getKey(),
            'formal_record_family_id' => $family->getKey(),
            'formal_record_version_id' => $version->getKey(),
            'activated_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $version;
    }

    /** @return array{User,Business,Membership} */
    private function workspace(string $prefix): array
    {
        $business = Business::query()->create([
            'name' => 'F7 Import Confirmation '.Str::uuid7(),
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
