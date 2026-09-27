<?php

declare(strict_types=1);

namespace Tests\Feature\Risk;

use App\Infrastructure\Persistence\Eloquent\Businesses\Business;
use App\Infrastructure\Persistence\Eloquent\Identity\User;
use App\Infrastructure\Persistence\Eloquent\Members\Membership;
use App\Infrastructure\Persistence\Eloquent\Records\FormalRecordFamily;
use App\Infrastructure\Persistence\Eloquent\Records\FormalRecordVersion;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

final class F6DRiskDatabaseInvariantTest extends TestCase
{
    use RefreshDatabase;

    public function test_frozen_risk_snapshot_cannot_be_mutated(): void
    {
        [$business, $version, $riskId] = $this->frozenRisk();

        $this->expectException(QueryException::class);

        DB::table('risk_items')
            ->where('business_id', $business->getKey())
            ->where('id', $riskId)
            ->update(['title' => 'Silently rewritten']);
    }

    public function test_database_rejects_risk_level_that_does_not_match_exact_thresholds(): void
    {
        [$business, $version] = $this->draftRiskHeader();

        $this->expectException(QueryException::class);

        DB::table('risk_items')->insert([
            'id' => (string) Str::uuid7(),
            'business_id' => $business->getKey(),
            'formal_record_version_id' => $version->getKey(),
            'operations_formal_record_version_id' => null,
            'operations_role_id' => null,
            'owner_membership_id' => null,
            'category' => 'financial',
            'title' => 'Score mismatch',
            'description' => 'Invalid level for exact thresholds.',
            'likelihood' => 3,
            'impact' => 4,
            'risk_score' => 12,
            'risk_level' => 'low',
            'warning_indicator' => null,
            'mitigation' => 'Control.',
            'response_plan' => 'Respond.',
            'review_date' => null,
            'status' => 'active',
            'confidentiality' => 'standard',
            'created_at' => now(),
        ]);
    }

    public function test_completed_risk_control_test_is_immutable_at_database_layer(): void
    {
        [$business, $version, $riskId, $membership] =
            $this->frozenRisk(withMembership: true);

        $testId = (string) Str::uuid7();

        DB::table('risk_control_tests')->insert([
            'id' => $testId,
            'business_id' => $business->getKey(),
            'formal_record_version_id' => $version->getKey(),
            'risk_item_id' => $riskId,
            'risk_protection_record_id' => null,
            'control_name' => 'Completed fixture control',
            'scenario' => 'Completed immutable test.',
            'tested_at' => now(),
            'result' => 'failed',
            'gap_found' => 'Fixture gap.',
            'corrective_action' => 'Fixture corrective action.',
            'owner_membership_id' => $membership->getKey(),
            'next_test_date' => now()->addMonth()->toDateString(),
            'completed_at' => now(),
            'confidentiality' => 'standard',
            'created_at' => now(),
        ]);

        $this->expectException(QueryException::class);

        DB::table('risk_control_tests')
            ->where('id', $testId)
            ->update(['result' => 'passed']);
    }

    public function test_incident_source_and_update_history_are_append_only(): void
    {
        [$business, $version, $riskId, $membership] = $this->frozenRisk(withMembership: true);

        $incidentId = (string) Str::uuid7();
        DB::table('risk_incidents')->insert([
            'id' => $incidentId,
            'business_id' => $business->getKey(),
            'formal_record_version_id' => $version->getKey(),
            'risk_item_id' => $riskId,
            'incident_at' => now(),
            'incident_type' => 'fixture',
            'description' => 'Original description',
            'business_impact' => 'Original impact',
            'immediate_action' => 'Original response',
            'loss_amount_minor_units' => null,
            'currency' => null,
            'status' => 'open',
            'confidentiality' => 'standard',
            'opened_by_membership_id' => $membership->getKey(),
            'revision' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $updateId = (string) Str::uuid7();
        DB::table('risk_incident_updates')->insert([
            'id' => $updateId,
            'business_id' => $business->getKey(),
            'risk_incident_id' => $incidentId,
            'status' => 'open',
            'root_cause' => null,
            'corrective_action' => null,
            'note' => 'Original history',
            'actor_membership_id' => $membership->getKey(),
            'occurred_at' => now(),
            'created_at' => now(),
        ]);

        try {
            DB::table('risk_incidents')
                ->where('id', $incidentId)
                ->update(['description' => 'Rewritten description']);
            self::fail('Incident source identity must be immutable.');
        } catch (QueryException) {
            self::assertTrue(true);
        }

        $this->expectException(QueryException::class);
        DB::table('risk_incident_updates')
            ->where('id', $updateId)
            ->update(['note' => 'Rewritten history']);
    }

    /** @return array{Business,FormalRecordVersion,string,Membership}|array{Business,FormalRecordVersion,string} */
    private function frozenRisk(bool $withMembership = false): array
    {
        [$business, $version, $membership] = $this->draftRiskHeader(true);

        $riskId = (string) Str::uuid7();
        DB::table('risk_items')->insert([
            'id' => $riskId,
            'business_id' => $business->getKey(),
            'formal_record_version_id' => $version->getKey(),
            'operations_formal_record_version_id' => null,
            'operations_role_id' => null,
            'owner_membership_id' => $membership->getKey(),
            'category' => 'operational',
            'title' => 'Frozen Risk',
            'description' => 'Frozen snapshot.',
            'likelihood' => 3,
            'impact' => 4,
            'risk_score' => 12,
            'risk_level' => 'high',
            'warning_indicator' => null,
            'mitigation' => 'Frozen mitigation.',
            'response_plan' => 'Frozen response.',
            'review_date' => null,
            'status' => 'active',
            'confidentiality' => 'standard',
            'created_at' => now(),
        ]);

        $version->frozen_at = now();
        $version->save();

        return $withMembership
            ? [$business, $version, $riskId, $membership]
            : [$business, $version, $riskId];
    }

    /** @return array{Business,FormalRecordVersion,Membership}|array{Business,FormalRecordVersion} */
    private function draftRiskHeader(bool $withMembership = false): array
    {
        $business = Business::query()->create([
            'name' => 'F6D DB '.Str::uuid7(),
            'origin_type' => 'started_through_pbr',
            'business_stage' => 'operating',
            'setup_phase' => 'formation',
            'workspace_status' => 'active',
            'base_currency' => 'USD',
        ]);
        $user = User::query()->create([
            'email' => Str::uuid7().'@example.test',
            'password' => 'not-a-real-hash',
            'status' => 'active',
            'password_changed_at' => now(),
        ]);
        $membership = Membership::query()->create([
            'user_id' => $user->getKey(),
            'business_id' => $business->getKey(),
            'access_status' => 'active',
        ]);
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
            'change_summary' => 'Risk DB invariant fixture',
            'created_by_user_id' => $user->getKey(),
            'last_changed_by_user_id' => $user->getKey(),
            'effective_from' => now(),
            'effective_until' => null,
            'review_due_at' => null,
            'content_hash' => str_repeat('d', 64),
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

        return $withMembership
            ? [$business, $version, $membership]
            : [$business, $version];
    }
}
