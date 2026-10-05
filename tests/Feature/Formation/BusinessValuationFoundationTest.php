<?php

declare(strict_types=1);

namespace Tests\Feature\Formation;

use App\Application\Businesses\CreateBusiness;
use App\Application\Evidence\LinkEvidence;
use App\Application\Formation\BusinessValuationPlanning;
use App\Application\Formation\ExistingBusinessBaseline;
use App\Application\Formation\GetBusinessValuationReadModel;
use App\Application\Journey\GetMasterBusinessJourney;
use App\Domain\Businesses\Enums\BusinessOriginType;
use App\Domain\Businesses\Enums\BusinessStage;
use App\Domain\Documents\Enums\DocumentAccessRight;
use App\Domain\Documents\Enums\DocumentCategory;
use App\Domain\Evidence\Enums\EvidenceConfidentiality;
use App\Domain\Identity\Enums\AccountStatus;
use App\Infrastructure\Persistence\Eloquent\Documents\Document;
use App\Infrastructure\Persistence\Eloquent\Documents\DocumentAccessGrant;
use App\Infrastructure\Persistence\Eloquent\Documents\DocumentVersion;
use App\Infrastructure\Persistence\Eloquent\Evidence\Evidence;
use App\Infrastructure\Persistence\Eloquent\Identity\User;
use App\Infrastructure\Persistence\Eloquent\Members\Membership;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Tests\TestCase;

final class BusinessValuationFoundationTest extends TestCase
{
    use RefreshDatabase;

    public function test_existing_business_valuation_reuses_sources_and_exposes_reproducible_read_model(): void
    {
        $user = $this->activeUser('valuation-foundation@example.test');
        $business = $this->app->make(CreateBusiness::class)->handle(
            $user,
            'Existing Valuation Business',
            BusinessOriginType::ExistingBusinessImportedIntoPbr,
            BusinessStage::Operating,
            'THB',
        );

        $baseline = $this->app->make(ExistingBusinessBaseline::class);

        $snapshotId = $baseline->addFinancialSnapshot(
            $user,
            $business,
            [
                'as_of_date' => '2026-09-30',
                'revenue' => '800000.00',
                'expenses' => '620000.00',
                'cash' => '20000.00',
                'receivables' => '50000.00',
                'payables' => '40000.00',
                'notes' => 'Latest actual snapshot before valuation.',
            ],
        );

        $assetId = $baseline->addAsset(
            $user,
            $business,
            [
                'name' => 'Operating assets',
                'estimated_value' => '300000.00',
                'notes' => 'Recorded asset baseline.',
            ],
        );

        $liabilityId = $baseline->addLiability(
            $user,
            $business,
            [
                'name' => 'Recorded liabilities',
                'outstanding_amount' => '100000.00',
                'notes' => 'Recorded liability baseline.',
            ],
        );

        self::assertNotNull($snapshotId);
        self::assertNotNull($assetId);
        self::assertNotNull($liabilityId);

        $result = $this->app
            ->make(BusinessValuationPlanning::class)
            ->calculateAndRecord(
                $user,
                $business,
                '2026-10-05',
                [
                    'ebitda' => '100000.00',
                    'owner_earnings' => '120000.00',
                    'free_cash_flow' => '80000.00',
                    'debt' => '10000.00',
                ],
                [
                    'ebitda_multiple' => '4',
                    'sde_multiple' => '3',
                    'growth_rate_percent' => '10',
                    'discount_rate_percent' => '15',
                    'terminal_growth_rate_percent' => '3',
                ],
                'reviewed',
            );

        self::assertNotNull($result);
        self::assertSame('390000.00', $result['range']['base']);
        self::assertSame('high', $result['confidence']['level']);
        self::assertSame(
            $snapshotId,
            $result['provenance']['financialSnapshot']['id'],
        );
        self::assertSame(
            [$assetId],
            array_column($result['provenance']['assets'], 'id'),
        );
        self::assertSame(
            [$liabilityId],
            array_column($result['provenance']['liabilities'], 'id'),
        );
        self::assertSame(
            [
                'ebitda',
                'owner_earnings',
                'free_cash_flow',
                'debt',
            ],
            $result['provenance']['explicitHistoricalFields'],
        );
        self::assertArrayNotHasKey(
            'total_ownership_units',
            $result['historical'],
        );
        self::assertFalse($result['semantics']['ownershipTruth']);
        self::assertFalse($result['semantics']['transactionPrice']);
        self::assertFalse($result['semantics']['contributionValuation']);

        $this->assertDatabaseHas('valuations', [
            'id' => $result['baselineValuationId'],
            'business_id' => $business->getKey(),
            'as_of_date' => '2026-10-05',
            'amount' => '390000.00',
            'review_state' => 'reviewed',
        ]);

        $this->assertDatabaseHas('business_valuation_runs', [
            'id' => $result['id'],
            'business_id' => $business->getKey(),
            'as_of_date' => '2026-10-05',
            'formula_version' => 'business-valuation-v1',
            'review_state' => 'reviewed',
            'base_value' => '390000.00',
            'confidence_level' => 'high',
        ]);

        $read = $this->app
            ->make(GetBusinessValuationReadModel::class)
            ->latest($user, $business);

        self::assertNotNull($read);
        self::assertSame($result['id'], $read['id']);
        self::assertSame(
            $result['baselineValuationId'],
            $read['baselineValuationId'],
        );
        self::assertSame('390000.00', $read['range']['base']);
        self::assertSame('traceable', $read['evidenceQuality']['level']);
        self::assertSame(0, $read['evidenceQuality']['linkedEvidenceCount']);

        $journey = $this->app
            ->make(GetMasterBusinessJourney::class)
            ->execute($user, $business, null);

        $valuationStep = collect($journey['steps'])
            ->firstWhere('key', 'business_valuation');

        self::assertIsArray($valuationStep);
        self::assertSame('recorded', $valuationStep['state']);

        $this->assertDatabaseHas('audit_events', [
            'business_id' => $business->getKey(),
            'action' => 'formation.business_valuation.calculated',
            'target_type' => 'business_valuation_run',
            'target_id' => $result['id'],
        ]);
    }

    public function test_business_valuation_evidence_uses_existing_document_authorization_and_evidence_links(): void
    {
        $user = $this->activeUser('valuation-evidence@example.test');
        $business = $this->app->make(CreateBusiness::class)->handle(
            $user,
            'Valuation Evidence Business',
            BusinessOriginType::ExistingBusinessImportedIntoPbr,
            BusinessStage::Operating,
            'USD',
        );

        $baseline = $this->app->make(ExistingBusinessBaseline::class);
        $baseline->addAsset(
            $user,
            $business,
            [
                'name' => 'Net operating assets',
                'estimated_value' => '250000.00',
                'notes' => null,
            ],
        );

        $result = $this->app
            ->make(BusinessValuationPlanning::class)
            ->calculateAndRecord(
                $user,
                $business,
                '2026-10-05',
                [],
                [],
            );

        self::assertNotNull($result);

        $membership = Membership::query()
            ->where('business_id', $business->getKey())
            ->where('user_id', $user->getKey())
            ->sole();

        $document = Document::query()->create([
            'business_id' => $business->getKey(),
            'title' => 'Valuation source statement',
            'category' => DocumentCategory::FinanceTax,
            'created_by_membership_id' => $membership->getKey(),
        ]);

        $version = DocumentVersion::query()->create([
            'business_id' => $business->getKey(),
            'document_id' => $document->getKey(),
            'version_number' => 1,
            'original_filename' => 'valuation-source.pdf',
            'storage_key' => 'test/'.Str::uuid7(),
            'size_bytes' => 100,
            'mime_type' => 'application/pdf',
            'content_sha256' => str_repeat('d', 64),
            'uploaded_by_membership_id' => $membership->getKey(),
            'effective_from' => null,
            'supersedes_document_version_id' => null,
        ]);

        DocumentAccessGrant::query()->create([
            'business_id' => $business->getKey(),
            'membership_id' => $membership->getKey(),
            'document_id' => $document->getKey(),
            'right' => DocumentAccessRight::Manage,
            'effect' => 'allow',
        ]);

        $evidence = Evidence::query()->create([
            'business_id' => $business->getKey(),
            'document_version_id' => $version->getKey(),
            'confidentiality' => EvidenceConfidentiality::Standard,
            'source_date' => '2026-10-01',
            'submitted_by_membership_id' => $membership->getKey(),
            'verified_at' => now(),
            'verified_by_membership_id' => $membership->getKey(),
            'verification_method' => 'manual_review',
            'verification_note' => 'Matched to valuation source.',
        ]);

        $link = $this->app
            ->make(LinkEvidence::class)
            ->execute(
                $user,
                $business,
                (string) $evidence->getKey(),
                'business_valuation_run',
                $result['id'],
            );

        self::assertNotNull($link);

        $read = $this->app
            ->make(GetBusinessValuationReadModel::class)
            ->latest($user, $business);

        self::assertNotNull($read);
        self::assertSame('documented', $read['evidenceQuality']['level']);
        self::assertSame(1, $read['evidenceQuality']['linkedEvidenceCount']);
        self::assertSame(1, $read['evidenceQuality']['verifiedEvidenceCount']);
        self::assertSame(
            [(string) $evidence->getKey()],
            $read['evidenceQuality']['evidenceIds'],
        );
    }

    public function test_new_business_cannot_create_business_valuation_truth(): void
    {
        $user = $this->activeUser('valuation-new-business@example.test');
        $business = $this->app->make(CreateBusiness::class)->handle(
            $user,
            'New Business',
            BusinessOriginType::StartedThroughPbr,
            BusinessStage::Idea,
            'USD',
        );

        $this->expectException(InvalidArgumentException::class);

        $this->app
            ->make(BusinessValuationPlanning::class)
            ->calculateAndRecord(
                $user,
                $business,
                '2026-10-05',
                [],
                [],
            );
    }

    public function test_valuation_run_is_immutable_after_recording(): void
    {
        $user = $this->activeUser('valuation-immutable@example.test');
        $business = $this->app->make(CreateBusiness::class)->handle(
            $user,
            'Immutable Valuation Business',
            BusinessOriginType::ExistingBusinessImportedIntoPbr,
            BusinessStage::Operating,
            'USD',
        );

        $this->app->make(ExistingBusinessBaseline::class)->addAsset(
            $user,
            $business,
            [
                'name' => 'Recorded asset',
                'estimated_value' => '100000.00',
                'notes' => null,
            ],
        );

        $result = $this->app
            ->make(BusinessValuationPlanning::class)
            ->calculateAndRecord(
                $user,
                $business,
                '2026-10-05',
                [],
                [],
            );

        self::assertNotNull($result);

        $this->expectException(QueryException::class);

        DB::table('business_valuation_runs')
            ->where('id', $result['id'])
            ->update([
                'base_value' => '999999.00',
            ]);
    }

    private function activeUser(string $email): User
    {
        return User::query()->create([
            'email' => $email,
            'password' => 'not-a-real-hash',
            'status' => AccountStatus::Active,
            'password_changed_at' => now(),
        ]);
    }
}
