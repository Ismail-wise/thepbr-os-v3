<?php

declare(strict_types=1);

namespace Tests\Feature\F7;

use App\Application\Access\ProvisionStandardAccessProfiles;
use App\Application\AI\PbrAiAssistant;
use App\Application\Health\GetBusinessHealth;
use App\Application\Import\GetImportWorkspace;
use App\Application\Partnership\ContributionWorkflow;
use App\Application\Partnership\DueDiligenceWorkflow;
use App\Application\Partnership\PartnerDirectory;
use App\Application\Portability\ChangeWorkspaceArchiveState;
use App\Application\Portability\GetPortabilityWorkspace;
use App\Application\Reporting\GetReportsWorkspace;
use App\Application\Search\GlobalSearch;
use App\Domain\Businesses\Enums\WorkspaceStatus;
use App\Domain\Partnership\Enums\ContributionType;
use App\Domain\Partnership\Enums\DueDiligenceStatus;
use App\Domain\Partnership\ValueObjects\ContributionValue;
use App\Infrastructure\Persistence\Eloquent\Businesses\Business;
use App\Infrastructure\Persistence\Eloquent\Identity\User;
use App\Infrastructure\Persistence\Eloquent\Members\Membership;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Tests\TestCase;

final class F7WholeLifecycleTest extends TestCase
{
    use RefreshDatabase;

    public function test_partner_onboarding_keeps_access_ownership_governance_and_documents_separate(): void
    {
        [$user, $business, $membership] = $this->workspace(
            'f7-whole-partner',
        );

        $partner = $this->app->make(PartnerDirectory::class)->create(
            $user,
            $business,
            'Whole Lifecycle Partner',
            null,
            'whole-lifecycle@example.test',
            'F7-10 composition fixture.',
        );

        self::assertNotNull($partner);

        $partnerId = $partner['id'];
        $baseline = $this->separationCounts();

        self::assertNotNull(
            $this->app->make(PartnerDirectory::class)->linkMembership(
                $user,
                $business,
                $partnerId,
                (string) $membership->getKey(),
            ),
        );

        $dd = $this->app->make(DueDiligenceWorkflow::class);
        $fields = $this->dueDiligenceFields();

        $draft = $dd->save(
            $user,
            $business,
            $partnerId,
            null,
            0,
            DueDiligenceStatus::Draft,
            null,
            $fields,
        );

        self::assertNotNull($draft);

        $review = $dd->save(
            $user,
            $business,
            $partnerId,
            $draft['id'],
            1,
            DueDiligenceStatus::InReview,
            null,
            $fields,
        );

        self::assertNotNull($review);

        $complete = $dd->save(
            $user,
            $business,
            $partnerId,
            $draft['id'],
            2,
            DueDiligenceStatus::Completed,
            'low',
            $fields,
        );

        self::assertNotNull($complete);

        $contribution = $this->app
            ->make(ContributionWorkflow::class)
            ->create(
                $user,
                $business,
                $partnerId,
                ContributionType::Cash,
                'USD',
                'Whole lifecycle proposed cash contribution.',
                new ContributionValue('10000.00'),
                null,
                null,
                null,
                [
                    'amount_committed' => '10000.00',
                    'amount_received' => '0.00',
                ],
            );

        self::assertNotNull($contribution);
        self::assertSame(
            'proposed',
            DB::table('contributions')
                ->where('id', $contribution['id'])
                ->value('status'),
        );

        self::assertSame(
            'prospective',
            DB::table('partners')
                ->where('id', $partnerId)
                ->value('status'),
        );

        $after = $this->separationCounts();

        self::assertSame(
            $baseline['ownership_register_versions'],
            $after['ownership_register_versions'],
            'Partner/DD/proposed Contribution must not manufacture Ownership.',
        );
        self::assertSame(
            $baseline['authority_snapshots'],
            $after['authority_snapshots'],
            'System capability must not manufacture Governance authority.',
        );
        self::assertSame(
            $baseline['document_access_grants'],
            $after['document_access_grants'],
            'Membership or Partner linkage must not manufacture Document rights.',
        );

        self::assertSame(
            (string) $membership->getKey(),
            (string) DB::table('partner_membership_links')
                ->where('business_id', $business->getKey())
                ->where('partner_id', $partnerId)
                ->value('membership_id'),
        );
    }

    public function test_supporting_intelligence_surfaces_are_derived_and_do_not_mutate_canonical_truth(): void
    {
        [$user, $business] = $this->workspace(
            'f7-whole-intelligence',
        );

        $partner = $this->app->make(PartnerDirectory::class)->create(
            $user,
            $business,
            'Whole Intelligence Partner',
            null,
            null,
            null,
        );

        self::assertNotNull($partner);

        $before = $this->canonicalCounts();

        $search = $this->app->make(GlobalSearch::class)->execute(
            $user,
            $business,
            'Whole Intelligence',
        );

        self::assertNotNull($search);
        self::assertSame(1, $search['count']);

        self::assertNotNull(
            $this->app->make(GetBusinessHealth::class)->execute(
                $user,
                $business,
            ),
        );
        self::assertNotNull(
            $this->app->make(GetReportsWorkspace::class)->execute(
                $user,
                $business,
            ),
        );
        self::assertNotNull(
            $this->app->make(GetImportWorkspace::class)->execute(
                $user,
                $business,
            ),
        );
        self::assertNotNull(
            $this->app->make(GetPortabilityWorkspace::class)->execute(
                $user,
                $business,
            ),
        );
        self::assertNotNull(
            $this->app->make(PbrAiAssistant::class)->workspace(
                $user,
                $business,
            ),
        );

        self::assertSame(
            $before,
            $this->canonicalCounts(),
            'Derived/supporting modules must not mutate canonical truth.',
        );
    }

    public function test_archive_round_trip_preserves_lifecycle_history_and_never_uncloses_business(): void
    {
        [$user, $business] = $this->workspace(
            'f7-whole-archive',
        );

        $partner = $this->app->make(PartnerDirectory::class)->create(
            $user,
            $business,
            'Archive History Partner',
            null,
            null,
            null,
        );

        self::assertNotNull($partner);

        $partnerCount = DB::table('partners')->count();
        $membershipCount = DB::table('memberships')->count();

        $archive = $this->app->make(
            ChangeWorkspaceArchiveState::class,
        );

        $archived = $archive->archive(
            $user,
            $business,
            'F7-10 verifies Archive is state, not deletion.',
        );

        self::assertNotNull($archived);
        self::assertSame(
            WorkspaceStatus::Archived,
            $archived->workspace_status,
        );

        $restored = $archive->unarchive(
            $user,
            $archived,
            'F7-10 explicit restore.',
        );

        self::assertNotNull($restored);
        self::assertSame(
            WorkspaceStatus::Active,
            $restored->workspace_status,
        );
        self::assertSame($partnerCount, DB::table('partners')->count());
        self::assertSame(
            $membershipCount,
            DB::table('memberships')->count(),
        );
        self::assertSame(
            2,
            DB::table('business_archive_transitions')
                ->where('business_id', $business->getKey())
                ->count(),
        );

        self::assertNotSame(
            WorkspaceStatus::Closed,
            $business->fresh()->workspace_status,
            'Archive operations must not perform legal/workspace Closure.',
        );
    }

    public function test_lifecycle_history_guards_cover_transfer_exit_closure_and_archive(): void
    {
        $expected = [
            'partner_lifecycle_transitions_append_only',
            'ownership_transfer_sources_append_only',
            'membership_access_transitions_append_only',
            'exit_case_transitions_append_only',
            'closure_case_transitions_append_only',
            'business_archive_transitions_append_only',
            'business_portability_transitions_append_only',
        ];

        $found = DB::table('pg_trigger')
            ->whereIn('tgname', $expected)
            ->where('tgisinternal', false)
            ->pluck('tgname')
            ->all();

        sort($expected);
        sort($found);

        self::assertSame($expected, $found);
    }

    /** @return array<string,int> */
    private function separationCounts(): array
    {
        return [
            'ownership_register_versions' => DB::table('ownership_register_versions')->count(),
            'authority_snapshots' => DB::table('authority_snapshots')->count(),
            'document_access_grants' => DB::table('document_access_grants')->count(),
        ];
    }

    /** @return array<string,int> */
    private function canonicalCounts(): array
    {
        $tables = [
            'partners',
            'memberships',
            'contributions',
            'ownership_register_versions',
            'authority_snapshots',
            'decisions',
            'approvals',
            'votes',
            'signature_requests',
            'formal_record_versions',
            'document_versions',
        ];

        $counts = [];

        foreach ($tables as $table) {
            $counts[$table] = DB::table($table)->count();
        }

        return $counts;
    }

    /** @return array<string,string> */
    private function dueDiligenceFields(): array
    {
        return [
            'identity_legal_info' => 'Verified identity.',
            'background_summary' => 'Reviewed.',
            'business_experience' => 'Reviewed.',
            'financial_capacity' => 'Reviewed.',
            'reputation' => 'Reviewed.',
            'existing_business_interests' => 'None declared.',
            'conflict_of_interest' => 'None identified.',
            'time_commitment' => 'Confirmed.',
            'legal_regulatory_check' => 'Clear.',
            'notes' => 'F7-10 whole lifecycle fixture.',
        ];
    }

    /**
     * @return array{User,Business,Membership}
     */
    private function workspace(string $prefix): array
    {
        $business = Business::query()->create([
            'name' => 'F7 Whole Lifecycle '.Str::uuid7(),
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
