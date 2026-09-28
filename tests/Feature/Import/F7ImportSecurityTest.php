<?php

declare(strict_types=1);

namespace Tests\Feature\Import;

use App\Application\Access\ProvisionStandardAccessProfiles;
use App\Application\Import\ConfirmImportRecords;
use App\Application\Import\CreateImportBatch;
use App\Application\Import\GetImportWorkspace;
use App\Application\Import\ParseImportBatch;
use App\Application\Import\ValidateImportBatch;
use App\Domain\Access\Enums\PermissionEffect;
use App\Domain\Import\Enums\ImportBatchStatus;
use App\Domain\Import\Enums\ImportedRecordStatus;
use App\Http\Middleware\EnsureCurrentBusinessContext;
use App\Infrastructure\Persistence\Eloquent\Access\Permission;
use App\Infrastructure\Persistence\Eloquent\Access\PermissionGrant;
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

final class F7ImportSecurityTest extends TestCase
{
    use RefreshDatabase;

    public function test_cross_business_batch_identifier_fails_closed_in_use_case_and_route(): void
    {
        [$userA, $businessA] = $this->workspace('import-tenant-a');
        [$userB, $businessB] = $this->workspace('import-tenant-b');

        $batchA = $this->stagePartnerBatch(
            $userA,
            $businessA,
            'tenant-a-001',
        );

        self::assertNull(
            $this->app->make(ParseImportBatch::class)->execute(
                $userB,
                $businessB,
                (string) $batchA->getKey(),
            ),
        );

        $this->actingAs($userB)
            ->withSession([
                EnsureCurrentBusinessContext::SESSION_KEY => $businessB->getKey(),
            ])
            ->post(
                '/import/batches/'.$batchA->getKey().'/parse',
            )
            ->assertNotFound();

        self::assertSame(
            0,
            ImportedRecord::query()
                ->where('business_id', $businessB->getKey())
                ->count(),
        );
    }

    public function test_revoked_membership_cannot_view_or_mutate_import_staging(): void
    {
        [$user, $business, $membership] = $this->workspace(
            'import-revoked',
        );

        $batch = $this->stagePartnerBatch(
            $user,
            $business,
            'revoked-001',
        );

        $membership->access_status = 'revoked';
        $membership->save();

        self::assertNull(
            $this->app->make(GetImportWorkspace::class)->execute(
                $user,
                $business,
            ),
        );

        self::assertNull(
            $this->app->make(ParseImportBatch::class)->execute(
                $user,
                $business,
                (string) $batch->getKey(),
            ),
        );

        $batch->refresh();

        self::assertSame(ImportBatchStatus::Staged, $batch->status);
    }

    public function test_import_manage_does_not_bypass_target_partner_manage_denial(): void
    {
        [$user, $business, $membership] = $this->workspace(
            'import-target-deny',
        );

        $permission = Permission::query()
            ->where('key', 'partners.manage')
            ->sole();

        PermissionGrant::query()->create([
            'business_id' => $business->getKey(),
            'membership_id' => $membership->getKey(),
            'permission_id' => $permission->getKey(),
            'effect' => PermissionEffect::Deny,
        ]);

        $batch = $this->stagePartnerBatch(
            $user,
            $business,
            'denied-001',
        );

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

        $record = ImportedRecord::query()
            ->where('import_batch_id', $batch->getKey())
            ->sole();

        self::assertSame(ImportedRecordStatus::Valid, $record->status);

        $result = $this->app->make(ConfirmImportRecords::class)->execute(
            $user,
            $business,
            (string) $batch->getKey(),
            [(string) $record->getKey()],
        );

        self::assertNotNull($result);
        self::assertFalse($result['results'][0]['success']);
        self::assertSame(
            'target_domain_rejected',
            $result['results'][0]['code'],
        );

        $record->refresh();

        self::assertSame(
            ImportedRecordStatus::ConfirmationFailed,
            $record->status,
        );
        self::assertSame(0, DB::table('partners')->count());
        self::assertSame(
            ImportBatchStatus::CompletedWithErrors,
            $result['batch']->status,
        );
    }

    public function test_cross_business_formal_record_identifier_is_reported_only_as_generic_unavailable_target(): void
    {
        [$userA, $businessA] = $this->workspace(
            'import-record-tenant-a',
        );
        [$userB, $businessB] = $this->workspace(
            'import-record-tenant-b',
        );

        $foreignFamily = FormalRecordFamily::query()->create([
            'business_id' => $businessB->getKey(),
            'record_type' => 'secret_foreign_policy',
            'subject_type' => 'business',
            'subject_id' => (string) $businessB->getKey(),
        ]);

        $foreignVersion = FormalRecordVersion::query()->create([
            'business_id' => $businessB->getKey(),
            'formal_record_family_id' => $foreignFamily->getKey(),
            'version_number' => 1,
            'predecessor_version_id' => null,
            'revision' => 1,
            'change_summary' => 'Foreign restricted truth',
            'created_by_user_id' => $userB->getKey(),
            'last_changed_by_user_id' => $userB->getKey(),
            'effective_from' => null,
            'effective_until' => null,
            'review_due_at' => null,
            'content_hash' => str_repeat('f', 64),
            'frozen_at' => now(),
        ]);

        $source = json_encode([
            [
                'source_record_key' => 'cross-tenant-record',
                'source_version_id' => (string) $foreignVersion->getKey(),
                'content_hash' => str_repeat('a', 64),
                'change_summary' => 'Attempted cross-tenant amendment',
            ],
        ], JSON_THROW_ON_ERROR);

        $batch = $this->app->make(CreateImportBatch::class)->execute(
            $userA,
            $businessA,
            'json',
            'external',
            'cross-tenant.json',
            $source,
            'records-v1',
            'formal_record_amendment',
        );

        self::assertNotNull($batch);

        $this->app->make(ParseImportBatch::class)->execute(
            $userA,
            $businessA,
            (string) $batch->getKey(),
        );

        $this->app->make(ValidateImportBatch::class)->execute(
            $userA,
            $businessA,
            (string) $batch->getKey(),
        );

        $record = ImportedRecord::query()
            ->where('import_batch_id', $batch->getKey())
            ->sole();

        self::assertSame(ImportedRecordStatus::Invalid, $record->status);

        $issue = DB::table('import_validation_results')
            ->where('business_id', $businessA->getKey())
            ->where('imported_record_id', $record->getKey())
            ->sole();

        self::assertSame('source_version_unavailable', $issue->code);
        self::assertStringNotContainsString(
            'secret_foreign_policy',
            (string) $issue->message,
        );
        self::assertStringNotContainsString(
            'Foreign restricted truth',
            (string) $issue->message,
        );

        self::assertSame(
            1,
            FormalRecordVersion::query()
                ->where('business_id', $businessB->getKey())
                ->count(),
        );
        self::assertSame(
            0,
            FormalRecordVersion::query()
                ->where('business_id', $businessA->getKey())
                ->count(),
        );
    }

    private function stagePartnerBatch(
        User $user,
        Business $business,
        string $sourceRecordKey,
    ): ImportBatch {
        $source = json_encode([
            [
                'source_record_key' => $sourceRecordKey,
                'display_name' => 'Security Test Partner',
                'email' => $sourceRecordKey.'@example.test',
            ],
        ], JSON_THROW_ON_ERROR);

        $batch = $this->app->make(CreateImportBatch::class)->execute(
            $user,
            $business,
            'json',
            'security-test',
            'partners.json',
            $source,
            'partners-v1',
            'partner',
        );

        self::assertNotNull($batch);

        return $batch;
    }

    /** @return array{User,Business,Membership} */
    private function workspace(string $prefix): array
    {
        $business = Business::query()->create([
            'name' => 'F7 Import Security '.Str::uuid7(),
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
