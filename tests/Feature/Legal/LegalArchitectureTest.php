<?php

declare(strict_types=1);

namespace Tests\Feature\Legal;

use App\Application\Access\ProvisionStandardAccessProfiles;
use App\Application\Legal\GetLegalWorkspace;
use App\Application\Legal\LegalArchitectureWorkflow;
use App\Http\Middleware\EnsureCurrentBusinessContext;
use App\Infrastructure\Persistence\Eloquent\Businesses\Business;
use App\Infrastructure\Persistence\Eloquent\Identity\User;
use App\Infrastructure\Persistence\Eloquent\Members\Membership;
use App\Infrastructure\Persistence\Eloquent\Records\FormalRecordVersion;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

final class LegalArchitectureTest extends TestCase
{
    use RefreshDatabase;

    public function test_legal_architecture_uses_explicit_structured_records_and_frozen_proposal_binding(): void
    {
        $context = $this->context('legal-owner');
        $workflow = $this->app->make(LegalArchitectureWorkflow::class);

        $created = $workflow->createDraft(
            $context['user'],
            $context['business'],
            $this->payload('restricted'),
            now()->subMinute(),
            now()->addMonths(6),
        );

        self::assertNotNull($created);
        $versionId = $created['formal_record_version_id'];

        $this->assertDatabaseHas('legal_structure_versions', [
            'business_id' => $context['business']->getKey(),
            'formal_record_version_id' => $versionId,
            'legal_form' => 'Private company limited by shares',
            'primary_jurisdiction_code' => 'TH',
            'confidentiality' => 'restricted',
        ]);
        $this->assertDatabaseHas('legal_jurisdiction_applicabilities', [
            'business_id' => $context['business']->getKey(),
            'formal_record_version_id' => $versionId,
            'jurisdiction_code' => 'TH',
            'scope_type' => 'entity',
            'applicability' => 'applicable',
        ]);
        $this->assertDatabaseHas('legal_registrations', [
            'business_id' => $context['business']->getKey(),
            'formal_record_version_id' => $versionId,
            'registration_type' => 'Company registration',
            'authority' => 'Department of Business Development',
            'status' => 'active',
        ]);
        $this->assertDatabaseHas('legal_license_permits', [
            'business_id' => $context['business']->getKey(),
            'formal_record_version_id' => $versionId,
            'name' => 'Operating permit',
            'status' => 'pending',
        ]);
        $this->assertDatabaseHas('legal_requirements', [
            'business_id' => $context['business']->getKey(),
            'formal_record_version_id' => $versionId,
            'requirement_key' => 'annual-filing',
            'status' => 'identified',
            'legal_review_required' => true,
        ]);
        $this->assertDatabaseHas('legal_reviews', [
            'business_id' => $context['business']->getKey(),
            'formal_record_version_id' => $versionId,
            'review_type' => 'Formation review',
            'reviewer_capacity' => 'External legal advisor',
            'outcome' => 'qualified',
        ]);

        $workspace = $this->app->make(GetLegalWorkspace::class)->execute(
            $context['user'],
            $context['business'],
        );

        self::assertNotNull($workspace);
        self::assertCount(1, $workspace['versions']);

        $submitted = $workflow->submitForGovernance(
            $context['user'],
            $context['business'],
            $versionId,
            1,
        );

        self::assertNotNull($submitted);
        $this->assertDatabaseHas('proposal_version_records', [
            'business_id' => $context['business']->getKey(),
            'proposal_version_id' => $submitted['proposal_version_id'],
            'formal_record_version_id' => $versionId,
        ]);

        $version = FormalRecordVersion::query()->findOrFail($versionId);
        self::assertNotNull($version->frozen_at);

        try {
            DB::table('legal_requirements')
                ->where('formal_record_version_id', $versionId)
                ->update(['status' => 'met']);

            self::fail('Frozen Legal Architecture snapshot must be immutable.');
        } catch (QueryException $exception) {
            self::assertStringContainsString(
                'Frozen Legal Architecture snapshot is immutable',
                $exception->getMessage(),
            );
        }
    }

    public function test_legal_architecture_is_tenant_scoped_permission_filtered_and_route_backed(): void
    {
        $owner = $this->context('legal-a');
        $other = $this->context('legal-b');
        $workflow = $this->app->make(LegalArchitectureWorkflow::class);

        $created = $workflow->createDraft(
            $owner['user'],
            $owner['business'],
            $this->payload(),
            now()->subMinute(),
        );

        self::assertNotNull($created);
        $versionId = $created['formal_record_version_id'];

        self::assertNull($workflow->submitForGovernance(
            $other['user'],
            $other['business'],
            $versionId,
            1,
        ));

        self::assertNull(
            $this->app->make(GetLegalWorkspace::class)->execute(
                $owner['user'],
                $other['business'],
            ),
        );

        [$viewer] = $this->member($owner['business'], 'legal-unprivileged');

        self::assertNull(
            $this->app->make(GetLegalWorkspace::class)->execute(
                $viewer,
                $owner['business'],
            ),
        );

        $session = [
            EnsureCurrentBusinessContext::SESSION_KEY => (string) $owner['business']->getKey(),
        ];

        $this
            ->actingAs($owner['user'])
            ->withSession($session)
            ->get('/business/legal-structure')
            ->assertOk()
            ->assertInertia(
                fn (Assert $page) => $page
                    ->component('Business/LegalStructure')
                    ->where(
                        'legal.business.id',
                        (string) $owner['business']->getKey(),
                    )
                    ->where('legal.permissions.manage', true),
            );

        $this
            ->actingAs($viewer)
            ->withSession($session)
            ->get('/business/legal-structure')
            ->assertNotFound();
    }

    /** @return array{business:Business,user:User,membership:Membership} */
    private function context(string $prefix): array
    {
        $business = Business::query()->create([
            'name' => 'Legal '.Str::uuid7(),
            'origin_type' => 'started_through_pbr',
            'business_stage' => 'planning',
            'setup_phase' => 'formation',
            'workspace_status' => 'active',
            'base_currency' => 'USD',
        ]);

        [$user, $membership] = $this->member($business, $prefix);

        $this->app->make(ProvisionStandardAccessProfiles::class)
            ->execute($business, $membership);

        return [
            'business' => $business,
            'user' => $user,
            'membership' => $membership,
        ];
    }

    /** @return array{User,Membership} */
    private function member(Business $business, string $prefix): array
    {
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

        return [$user, $membership];
    }

    /** @return array<string,mixed> */
    private function payload(string $confidentiality = 'standard'): array
    {
        return [
            'legal_form' => 'Private company limited by shares',
            'entity_name' => 'PBR Legal Fixture Co., Ltd.',
            'primary_jurisdiction_code' => 'TH',
            'governing_law_reference' => 'Applicable Thai law',
            'registered_address' => 'Registered office reference',
            'confidentiality' => $confidentiality,
            'notes' => 'Jurisdiction-specific requirements require qualified review.',
            'jurisdictions' => [[
                'jurisdiction_code' => 'TH',
                'scope_type' => 'entity',
                'scope_reference' => 'Primary entity',
                'applicability' => 'applicable',
                'rationale' => 'Entity is registered in this jurisdiction.',
                'legal_review_required' => false,
            ]],
            'registrations' => [[
                'registration_type' => 'Company registration',
                'authority' => 'Department of Business Development',
                'reference_number' => 'MASKED-REG-001',
                'jurisdiction_code' => 'TH',
                'registration_date' => now()->subYear()->toDateString(),
                'status' => 'active',
                'evidence_reference' => 'Document Vault reference',
            ]],
            'licenses' => [[
                'name' => 'Operating permit',
                'authority' => 'Competent authority',
                'reference_number' => 'MASKED-LIC-001',
                'jurisdiction_code' => 'TH',
                'start_date' => now()->toDateString(),
                'expiry_date' => now()->addYear()->toDateString(),
                'review_date' => now()->addMonths(9)->toDateString(),
                'status' => 'pending',
                'evidence_reference' => 'Document Vault reference',
            ]],
            'requirements' => [[
                'requirement_key' => 'annual-filing',
                'title' => 'Annual filing',
                'category' => 'corporate',
                'description' => 'Confirm applicable annual filing obligations.',
                'jurisdiction_code' => 'TH',
                'source_authority' => 'Competent authority',
                'applicable_from' => now()->startOfYear()->toDateString(),
                'applicable_until' => null,
                'status' => 'identified',
                'legal_review_required' => true,
                'evidence_reference' => 'Legal checklist reference',
            ]],
            'reviews' => [[
                'requirement_index' => 0,
                'review_type' => 'Formation review',
                'reviewer_name' => 'Fixture Reviewer',
                'reviewer_capacity' => 'External legal advisor',
                'reviewer_organization' => 'Fixture Legal',
                'review_date' => now()->toDateString(),
                'outcome' => 'qualified',
                'notes' => 'Review is evidence, not system-generated legal advice.',
                'evidence_reference' => 'Review memo reference',
            ]],
        ];
    }
}
