<?php

declare(strict_types=1);

namespace Tests\Feature\Partnership;

use App\Application\Businesses\CreateBusiness;
use App\Application\Partnership\DueDiligenceWorkflow;
use App\Application\Partnership\PartnerDirectory;
use App\Application\Partnership\PartnerDynamicsReference;
use App\Domain\Businesses\Enums\BusinessOriginType;
use App\Domain\Businesses\Enums\BusinessStage;
use App\Domain\Identity\Enums\AccountStatus;
use App\Domain\Partnership\Enums\DueDiligenceStatus;
use App\Infrastructure\Persistence\Eloquent\Identity\User;
use DateTimeImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

final class F5PartnerCoreTest extends TestCase
{
    use RefreshDatabase;

    public function test_partner_is_domain_identity_not_membership_or_ownership(): void
    {
        $user = $this->activeUser(
            'f5-partner-owner@example.test',
        );

        $business = $this->app
            ->make(CreateBusiness::class)
            ->handle(
                $user,
                'F5 Partnership',
                BusinessOriginType::StartedThroughPbr,
                BusinessStage::Planning,
                'THB',
            );

        $partner = $this->app
            ->make(PartnerDirectory::class)
            ->create(
                $user,
                $business,
                'Prospective Partner',
                null,
                'partner@example.test',
                null,
            );

        self::assertNotNull($partner);

        $this->assertDatabaseHas('partners', [
            'id' => $partner['id'],
            'business_id' => $business->getKey(),
            'status' => 'prospective',
        ]);

        $this->assertDatabaseCount(
            'partner_membership_links',
            0,
        );

        self::assertFalse(
            DB::getSchemaBuilder()
                ->hasColumn('partners', 'ownership_percent'),
        );
    }

    public function test_partner_invitation_reuses_existing_business_access_invitation_foundation(): void
    {
        $user = $this->activeUser(
            'f5-invite-owner@example.test',
        );

        $business = $this->app
            ->make(CreateBusiness::class)
            ->handle(
                $user,
                'F5 Invitation',
                BusinessOriginType::StartedThroughPbr,
                BusinessStage::Planning,
                'USD',
            );

        $directory = $this->app->make(
            PartnerDirectory::class,
        );

        $partner = $directory->create(
            $user,
            $business,
            'Invited Partner',
            null,
            'invited@example.test',
            null,
        );

        $invite = $directory->invite(
            $user,
            $business,
            $partner['id'],
            'invited@example.test',
        );

        self::assertNotNull($invite);
        self::assertNotSame('', $invite['token']);

        $this->assertDatabaseHas(
            'business_access_invitations',
            [
                'id' => $invite['invitation_id'],
                'business_id' => $business->getKey(),
                'status' => 'pending',
            ],
        );

        $this->assertDatabaseHas(
            'partner_access_invitation_links',
            [
                'business_id' => $business->getKey(),
                'partner_id' => $partner['id'],
                'business_access_invitation_id' => $invite['invitation_id'],
            ],
        );

        $this->assertDatabaseCount(
            'partner_membership_links',
            0,
        );
    }

    public function test_due_diligence_terminal_case_is_historically_immutable(): void
    {
        $user = $this->activeUser(
            'f5-dd-owner@example.test',
        );

        $business = $this->app
            ->make(CreateBusiness::class)
            ->handle(
                $user,
                'F5 DD',
                BusinessOriginType::StartedThroughPbr,
                BusinessStage::Planning,
                'USD',
            );

        $partner = $this->app
            ->make(PartnerDirectory::class)
            ->create(
                $user,
                $business,
                'DD Partner',
                null,
                null,
                null,
            );

        $workflow = $this->app->make(
            DueDiligenceWorkflow::class,
        );

        $fields = $this->ddFields();

        $case = $workflow->save(
            $user,
            $business,
            $partner['id'],
            null,
            0,
            DueDiligenceStatus::Draft,
            null,
            $fields,
        );

        $case = $workflow->save(
            $user,
            $business,
            $partner['id'],
            $case['id'],
            1,
            DueDiligenceStatus::InReview,
            'moderate',
            $fields,
        );

        $case = $workflow->save(
            $user,
            $business,
            $partner['id'],
            $case['id'],
            2,
            DueDiligenceStatus::Completed,
            'moderate',
            $fields,
        );

        self::assertSame('completed', $case['status']);

        $this->expectException(QueryException::class);

        DB::table('partner_due_diligence_cases')
            ->where('id', $case['id'])
            ->update([
                'notes' => 'Silent rewrite attempt.',
                'revision' => 4,
                'updated_at' => now(),
            ]);
    }

    public function test_due_diligence_accepts_canonical_fields_in_any_order_and_missing_case_returns_null(): void
    {
        $user = $this->activeUser(
            'f5-dd-order@example.test',
        );

        $business = $this->app
            ->make(CreateBusiness::class)
            ->handle(
                $user,
                'F5 DD Order',
                BusinessOriginType::StartedThroughPbr,
                BusinessStage::Planning,
                'USD',
            );

        $partner = $this->app
            ->make(PartnerDirectory::class)
            ->create(
                $user,
                $business,
                'DD Order Partner',
                null,
                null,
                null,
            );

        $workflow = $this->app->make(
            DueDiligenceWorkflow::class,
        );

        $fields = array_reverse(
            $this->ddFields(),
            true,
        );

        $case = $workflow->save(
            $user,
            $business,
            $partner['id'],
            null,
            0,
            DueDiligenceStatus::Draft,
            null,
            $fields,
        );

        self::assertNotNull($case);
        self::assertSame('draft', $case['status']);

        $missing = $workflow->save(
            $user,
            $business,
            $partner['id'],
            '00000000-0000-0000-0000-000000000001',
            1,
            DueDiligenceStatus::InReview,
            'moderate',
            $fields,
        );

        self::assertNull($missing);
    }

    public function test_partner_dynamics_is_reference_only_and_does_not_create_rights(): void
    {
        $user = $this->activeUser(
            'f5-pd-owner@example.test',
        );

        $business = $this->app
            ->make(CreateBusiness::class)
            ->handle(
                $user,
                'F5 PartnerDynamics',
                BusinessOriginType::StartedThroughPbr,
                BusinessStage::Planning,
                'USD',
            );

        $partner = $this->app
            ->make(PartnerDirectory::class)
            ->create(
                $user,
                $business,
                'Dynamics Partner',
                null,
                null,
                null,
            );

        $referenceId = $this->app
            ->make(PartnerDynamicsReference::class)
            ->record(
                $user,
                $business,
                $partner['id'],
                'legacy-assessment-123',
                null,
                'v1',
                'visionary',
                'analyst',
                new DateTimeImmutable(
                    '2026-09-26T08:00:00+00:00',
                ),
            );

        self::assertNotNull($referenceId);

        $this->assertDatabaseHas(
            'partner_dynamics_assessment_references',
            [
                'id' => $referenceId,
                'partner_id' => $partner['id'],
                'primary_profile' => 'visionary',
                'secondary_profile' => 'analyst',
            ],
        );

        $this->assertDatabaseCount(
            'partner_membership_links',
            0,
        );

        self::assertFalse(
            DB::getSchemaBuilder()
                ->hasColumn(
                    'partner_dynamics_assessment_references',
                    'ownership_percent',
                ),
        );

        self::assertFalse(
            DB::getSchemaBuilder()
                ->hasColumn(
                    'partner_dynamics_assessment_references',
                    'vote_weight',
                ),
        );
    }

    /**
     * @return array<string, ?string>
     */
    private function ddFields(): array
    {
        return [
            'identity_legal_info' => 'Identity checked',
            'background_summary' => 'Background reviewed',
            'business_experience' => 'Relevant experience',
            'financial_capacity' => 'Capacity reviewed',
            'reputation' => 'No material issue identified',
            'existing_business_interests' => 'Declared',
            'conflict_of_interest' => 'No current conflict',
            'time_commitment' => 'Agreed commitment',
            'legal_regulatory_check' => 'No known blocker',
            'notes' => 'F5 test case',
        ];
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
