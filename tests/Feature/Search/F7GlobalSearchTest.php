<?php

declare(strict_types=1);

namespace Tests\Feature\Search;

use App\Application\Access\ProvisionStandardAccessProfiles;
use App\Application\Search\GlobalSearch;
use App\Domain\Documents\Enums\DocumentAccessRight;
use App\Infrastructure\Persistence\Eloquent\Businesses\Business;
use App\Infrastructure\Persistence\Eloquent\Documents\Document;
use App\Infrastructure\Persistence\Eloquent\Documents\DocumentAccessGrant;
use App\Infrastructure\Persistence\Eloquent\Identity\User;
use App\Infrastructure\Persistence\Eloquent\Members\Membership;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Tests\TestCase;

final class F7GlobalSearchTest extends TestCase
{
    use RefreshDatabase;

    public function test_search_is_business_scoped_and_returns_only_authorized_derived_results(): void
    {
        [$userA, $businessA] = $this->workspace('search-a');
        [, $businessB] = $this->workspace('search-b');

        $partnerA = $this->partner(
            $businessA,
            'Alpha North Partner',
            'Alpha North Holdings',
        );
        $partnerB = $this->partner(
            $businessB,
            'Alpha Foreign Partner',
            'Alpha Foreign Holdings',
        );

        $beforePartnerCount = DB::table('partners')->count();
        $beforeRecordCount = DB::table('formal_record_versions')->count();
        $beforePermissionGrants = DB::table('permission_grants')->count();
        $beforeRecordAccessRules = DB::table('record_access_rules')->count();
        $beforeDocumentAccessGrants = DB::table('document_access_grants')->count();
        $beforeAuthoritySnapshots = DB::table('authority_snapshots')->count();
        $beforeOwnershipVersions = DB::table('ownership_register_versions')->count();

        $result = $this->app->make(GlobalSearch::class)->execute(
            $userA,
            $businessA,
            'Alpha',
        );

        self::assertNotNull($result);
        self::assertSame('Alpha', $result['query']);
        self::assertSame(1, $result['count']);
        self::assertCount(1, $result['items']);
        self::assertSame('partner', $result['items'][0]['source_type']);
        self::assertSame($partnerA, $result['items'][0]['source_id']);
        self::assertSame('Alpha North Partner', $result['items'][0]['title']);
        self::assertNotContains(
            'Alpha Foreign Partner',
            $result['suggestions'],
        );

        self::assertDatabaseHas('search_index_entries', [
            'business_id' => $businessA->getKey(),
            'source_type' => 'partner',
            'source_id' => $partnerA,
        ]);
        self::assertDatabaseMissing('search_index_entries', [
            'business_id' => $businessA->getKey(),
            'source_type' => 'partner',
            'source_id' => $partnerB,
        ]);

        self::assertSame(
            $beforePartnerCount,
            DB::table('partners')->count(),
            'Derived Search projection must not mutate canonical Partner truth.',
        );
        self::assertSame(
            $beforeRecordCount,
            DB::table('formal_record_versions')->count(),
            'Search must not manufacture Formal or Effective truth.',
        );
        self::assertSame(
            $beforePermissionGrants,
            DB::table('permission_grants')->count(),
            'Search must not manufacture System capability grants.',
        );
        self::assertSame(
            $beforeRecordAccessRules,
            DB::table('record_access_rules')->count(),
            'Search must not manufacture record visibility rights.',
        );
        self::assertSame(
            $beforeDocumentAccessGrants,
            DB::table('document_access_grants')->count(),
            'Search must not manufacture Document access.',
        );
        self::assertSame(
            $beforeAuthoritySnapshots,
            DB::table('authority_snapshots')->count(),
            'Search must not manufacture Governance authority.',
        );
        self::assertSame(
            $beforeOwnershipVersions,
            DB::table('ownership_register_versions')->count(),
            'Search must not manufacture Ownership rights.',
        );
    }

    public function test_document_search_reuses_document_permission_domain(): void
    {
        [$user, $business, $membership] = $this->workspace(
            'search-document',
            true,
        );

        $document = Document::query()->create([
            'business_id' => $business->getKey(),
            'title' => 'Orchid Authorized Agreement',
            'category' => 'agreements_contracts',
            'created_by_membership_id' => $membership->getKey(),
        ]);

        DocumentAccessGrant::query()->create([
            'business_id' => $business->getKey(),
            'membership_id' => $membership->getKey(),
            'document_id' => $document->getKey(),
            'right' => DocumentAccessRight::View,
            'effect' => 'allow',
        ]);

        $result = $this->app->make(GlobalSearch::class)->execute(
            $user,
            $business,
            'Orchid',
        );

        self::assertNotNull($result);
        self::assertSame(1, $result['count']);
        self::assertSame('document', $result['items'][0]['source_type']);
        self::assertSame(
            (string) $document->getKey(),
            $result['items'][0]['source_id'],
        );
        self::assertSame(
            '/records/documents',
            $result['items'][0]['route'],
        );
    }

    public function test_blank_search_returns_no_result_metadata_without_projecting(): void
    {
        [$user, $business] = $this->workspace('search-blank');
        $this->partner($business, 'Blank Search Partner', null);

        $result = $this->app->make(GlobalSearch::class)->execute(
            $user,
            $business,
            '   ',
        );

        self::assertNotNull($result);
        self::assertSame('', $result['query']);
        self::assertSame(0, $result['count']);
        self::assertSame([], $result['items']);
        self::assertSame([], $result['suggestions']);
        self::assertSame(
            0,
            DB::table('search_index_entries')
                ->where('business_id', $business->getKey())
                ->count(),
        );
    }

    private function partner(
        Business $business,
        string $displayName,
        ?string $legalName,
    ): string {
        $id = (string) Str::uuid7();

        DB::table('partners')->insert([
            'id' => $id,
            'business_id' => $business->getKey(),
            'display_name' => $displayName,
            'legal_name' => $legalName,
            'email' => null,
            'status' => 'active',
            'notes' => null,
            'revision' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $id;
    }

    /**
     * @return array{0:User,1:Business,2?:Membership}
     */
    private function workspace(
        string $prefix,
        bool $includeMembership = false,
    ): array {
        $business = Business::query()->create([
            'name' => 'F7 Search '.Str::uuid7(),
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

        return $includeMembership
            ? [$user, $business, $membership]
            : [$user, $business];
    }
}
