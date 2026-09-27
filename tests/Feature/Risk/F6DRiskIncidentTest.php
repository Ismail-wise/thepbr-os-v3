<?php

declare(strict_types=1);

namespace Tests\Feature\Risk;

use App\Application\Access\ProvisionStandardAccessProfiles;
use App\Application\Risk\RiskIncidentWorkflow;
use App\Domain\Risk\Enums\IncidentStatus;
use App\Infrastructure\Persistence\Eloquent\Businesses\Business;
use App\Infrastructure\Persistence\Eloquent\Identity\User;
use App\Infrastructure\Persistence\Eloquent\Members\Membership;
use App\Infrastructure\Persistence\Eloquent\Records\FormalRecordFamily;
use App\Infrastructure\Persistence\Eloquent\Records\FormalRecordVersion;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Tests\TestCase;

final class F6DRiskIncidentTest extends TestCase
{
    use RefreshDatabase;

    public function test_incident_progresses_through_append_only_history_without_rewriting_source(): void
    {
        [$business, $user, $membership, $riskId] = $this->context();
        $workflow = $this->app->make(RiskIncidentWorkflow::class);

        $incidentId = $workflow->open($user, $business, [
            'risk_item_id' => $riskId,
            'incident_at' => now()->subMinute(),
            'incident_type' => 'supplier_failure',
            'description' => 'Supplier missed a critical delivery.',
            'business_impact' => 'Production stopped.',
            'immediate_action' => 'Activated alternate supplier.',
            'loss_amount_minor_units' => 250000,
            'currency' => 'USD',
            'confidentiality' => 'standard',
        ]);

        self::assertNotNull($incidentId);
        $this->assertDatabaseHas('risk_incidents', [
            'id' => $incidentId,
            'business_id' => $business->getKey(),
            'status' => 'open',
            'revision' => 1,
        ]);
        $this->assertDatabaseCount('risk_incident_updates', 1);

        $revision = 1;
        foreach ([
            IncidentStatus::Investigating,
            IncidentStatus::Contained,
            IncidentStatus::CorrectiveAction,
            IncidentStatus::Resolved,
            IncidentStatus::Closed,
        ] as $status) {
            self::assertTrue($workflow->transition(
                $user,
                $business,
                $incidentId,
                $status,
                $revision,
                rootCause: $status === IncidentStatus::CorrectiveAction
                    ? 'Single supplier dependency.'
                    : null,
                correctiveAction: $status === IncidentStatus::CorrectiveAction
                    ? 'Approve and onboard second supplier.'
                    : null,
            ));
            $revision++;
        }

        $this->assertDatabaseHas('risk_incidents', [
            'id' => $incidentId,
            'status' => 'closed',
            'revision' => 6,
            'description' => 'Supplier missed a critical delivery.',
        ]);
        $this->assertDatabaseCount('risk_incident_updates', 6);

        $this->expectException(InvalidArgumentException::class);
        $workflow->transition(
            $user,
            $business,
            $incidentId,
            IncidentStatus::Investigating,
            6,
        );
    }

    /** @return array{Business,User,Membership,string} */
    private function context(): array
    {
        $business = Business::query()->create([
            'name' => 'F6D Incident '.Str::uuid7(),
            'origin_type' => 'started_through_pbr',
            'business_stage' => 'operating',
            'setup_phase' => 'formation',
            'workspace_status' => 'active',
            'base_currency' => 'USD',
        ]);
        $user = User::query()->create([
            'email' => Str::uuid7().'@example.test',
            'password' => Hash::make('test-password'),
            'status' => 'active',
            'password_changed_at' => now(),
        ]);
        $membership = Membership::query()->create([
            'user_id' => $user->getKey(),
            'business_id' => $business->getKey(),
            'access_status' => 'active',
        ]);

        $this->app->make(ProvisionStandardAccessProfiles::class)
            ->execute($business, $membership);

        $family = FormalRecordFamily::query()->create([
            'business_id' => $business->getKey(),
            'record_type' => 'risk_register',
            'subject_type' => 'business',
            'subject_id' => (string) $business->getKey(),
        ]);
        $version = FormalRecordVersion::query()->create([
            'business_id' => $business->getKey(),
            'formal_record_family_id' => $family->getKey(),
            'version_number' => 1,
            'predecessor_version_id' => null,
            'revision' => 1,
            'change_summary' => 'Effective risk fixture',
            'created_by_user_id' => $user->getKey(),
            'last_changed_by_user_id' => $user->getKey(),
            'effective_from' => now()->subMinute(),
            'effective_until' => null,
            'review_due_at' => null,
            'content_hash' => str_repeat('b', 64),
            'frozen_at' => null,
        ]);

        DB::table('risk_register_versions')->insert([
            'id' => (string) Str::uuid7(),
            'business_id' => $business->getKey(),
            'formal_record_version_id' => $version->getKey(),
            'risk_owner_membership_id' => $membership->getKey(),
            'low_max_score' => 4,
            'medium_max_score' => 9,
            'high_max_score' => 15,
            'review_frequency' => 'Quarterly',
            'notes' => null,
            'created_at' => now(),
        ]);

        $riskId = (string) Str::uuid7();
        DB::table('risk_items')->insert([
            'id' => $riskId,
            'business_id' => $business->getKey(),
            'formal_record_version_id' => $version->getKey(),
            'operations_formal_record_version_id' => null,
            'operations_role_id' => null,
            'owner_membership_id' => $membership->getKey(),
            'category' => 'operational',
            'title' => 'Supplier dependency',
            'description' => 'Critical supplier dependency.',
            'likelihood' => 3,
            'impact' => 4,
            'risk_score' => 12,
            'risk_level' => 'high',
            'warning_indicator' => 'Late delivery',
            'mitigation' => 'Maintain alternate supplier.',
            'response_plan' => 'Activate alternate supplier.',
            'review_date' => now()->addMonth()->toDateString(),
            'status' => 'active',
            'confidentiality' => 'standard',
            'created_at' => now(),
        ]);

        $version->frozen_at = now();
        $version->save();
        $this->effectiveLifecycle($business, $user, $version);

        DB::table('record_family_effective_heads')->insert([
            'id' => (string) Str::uuid7(),
            'business_id' => $business->getKey(),
            'formal_record_family_id' => $family->getKey(),
            'formal_record_version_id' => $version->getKey(),
            'activated_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return [$business, $user, $membership, $riskId];
    }

    private function effectiveLifecycle(
        Business $business,
        User $user,
        FormalRecordVersion $version,
    ): void {
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
    }
}
