<?php

declare(strict_types=1);

namespace Tests\Feature\Risk;

use App\Application\Access\ProvisionStandardAccessProfiles;
use App\Application\Continuity\CreateRiskContinuityAction;
use App\Application\Risk\RiskRegisterWorkflow;
use App\Infrastructure\Persistence\Eloquent\Access\Permission;
use App\Infrastructure\Persistence\Eloquent\Businesses\Business;
use App\Infrastructure\Persistence\Eloquent\Governance\Action;
use App\Infrastructure\Persistence\Eloquent\Identity\User;
use App\Infrastructure\Persistence\Eloquent\Members\Membership;
use App\Infrastructure\Persistence\Eloquent\Records\FormalRecordFamily;
use App\Infrastructure\Persistence\Eloquent\Records\FormalRecordVersion;
use App\Infrastructure\Persistence\Eloquent\Risk\RiskItem;
use App\Infrastructure\Persistence\Eloquent\Risk\RiskProtectionRecord;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Tests\TestCase;

final class F6DRiskRegisterTest extends TestCase
{
    use RefreshDatabase;

    public function test_risk_register_uses_exact_thresholds_operations_owner_and_restricted_rules(): void
    {
        $context = $this->context();
        $workflow = $this->app->make(RiskRegisterWorkflow::class);

        $created = $workflow->createDraft(
            $context['user'],
            $context['business'],
            $this->payload($context),
            now()->subMinute(),
            now()->addMonths(3),
        );

        self::assertNotNull($created);
        $versionId = $created['formal_record_version_id'];

        $risk = RiskItem::query()
            ->where('formal_record_version_id', $versionId)
            ->firstOrFail();

        self::assertSame(12, $risk->risk_score);
        self::assertSame('high', $risk->risk_level);
        self::assertSame($context['operations_version_id'], (string) $risk->operations_formal_record_version_id);
        self::assertSame($context['role_id'], (string) $risk->operations_role_id);
        self::assertSame((string) $context['membership']->getKey(), (string) $risk->owner_membership_id);
        self::assertSame('restricted', $risk->confidentiality);

        $protection = RiskProtectionRecord::query()
            ->where('formal_record_version_id', $versionId)
            ->firstOrFail();

        self::assertSame('restricted', $protection->confidentiality);

        foreach (['risk.view', 'risk.manage'] as $capability) {
            $permissionId = Permission::query()->where('key', $capability)->value('id');
            self::assertNotNull($permissionId);

            $this->assertDatabaseHas('record_access_rules', [
                'business_id' => $context['business']->getKey(),
                'membership_id' => $context['membership']->getKey(),
                'permission_id' => $permissionId,
                'resource_type' => RiskItem::class,
                'resource_id' => $risk->getKey(),
                'effect' => 'allow',
            ]);

            $this->assertDatabaseHas('record_access_rules', [
                'business_id' => $context['business']->getKey(),
                'membership_id' => $context['membership']->getKey(),
                'permission_id' => $permissionId,
                'resource_type' => RiskProtectionRecord::class,
                'resource_id' => $protection->getKey(),
                'effect' => 'allow',
            ]);
        }

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

        self::assertNotNull(
            FormalRecordVersion::query()->findOrFail($versionId)->frozen_at,
        );
    }

    public function test_secret_like_protection_payload_is_rejected(): void
    {
        $context = $this->context();
        $payload = $this->payload($context);
        $payload['protections'][0]['password'] = 'never-store-me';

        $this->expectException(InvalidArgumentException::class);

        $this->app->make(RiskRegisterWorkflow::class)->createDraft(
            $context['user'],
            $context['business'],
            $payload,
            now()->subMinute(),
        );
    }

    public function test_amendment_creates_new_version_without_overwriting_effective_history(): void
    {
        $context = $this->context();
        $workflow = $this->app->make(RiskRegisterWorkflow::class);

        $first = $workflow->createDraft(
            $context['user'],
            $context['business'],
            $this->payload($context),
            now()->subMinutes(2),
        );

        self::assertNotNull($first);
        $this->forceEffective(
            $context['business'],
            $context['user'],
            $first['formal_record_version_id'],
        );

        $secondPayload = $this->payload($context);
        $secondPayload['risks'][0]['title'] = 'Amended supplier disruption';

        $second = $workflow->createDraft(
            $context['user'],
            $context['business'],
            $secondPayload,
            now()->addDay(),
        );

        self::assertNotNull($second);
        self::assertNotSame(
            $first['formal_record_version_id'],
            $second['formal_record_version_id'],
        );

        $v1 = FormalRecordVersion::query()->findOrFail($first['formal_record_version_id']);
        $v2 = FormalRecordVersion::query()->findOrFail($second['formal_record_version_id']);

        self::assertSame(1, (int) $v1->version_number);
        self::assertSame(2, (int) $v2->version_number);
        self::assertSame((string) $v1->getKey(), (string) $v2->predecessor_version_id);

        $this->assertDatabaseHas('risk_items', [
            'formal_record_version_id' => $v1->getKey(),
            'title' => 'Supplier disruption',
        ]);
        $this->assertDatabaseHas('risk_items', [
            'formal_record_version_id' => $v2->getKey(),
            'title' => 'Amended supplier disruption',
        ]);
    }

    public function test_risk_follow_up_action_reuses_current_operations_action_and_preserves_source_binding(): void
    {
        $context = $this->context();
        $workflow = $this->app->make(RiskRegisterWorkflow::class);

        $created = $workflow->createDraft(
            $context['user'],
            $context['business'],
            $this->payload($context),
            now()->subMinute(),
        );

        self::assertNotNull($created);

        $this->forceEffective(
            $context['business'],
            $context['user'],
            $created['formal_record_version_id'],
        );

        $risk = RiskItem::query()
            ->where(
                'formal_record_version_id',
                $created['formal_record_version_id'],
            )
            ->sole();

        $action = $this->app->make(CreateRiskContinuityAction::class)->execute(
            $context['user'],
            $context['business'],
            'risk_item',
            (string) $risk->getKey(),
            $context['role_id'],
            (string) $context['membership']->getKey(),
            'Mitigate supplier disruption',
            'Execute the approved alternate-supplier mitigation.',
            now()->addDay(),
        );

        self::assertInstanceOf(Action::class, $action);

        $this->assertDatabaseHas('operations_action_links', [
            'business_id' => $context['business']->getKey(),
            'action_id' => $action->getKey(),
            'formal_record_version_id' => $context['operations_version_id'],
            'operations_role_id' => $context['role_id'],
        ]);

        $this->assertDatabaseHas('risk_continuity_action_links', [
            'business_id' => $context['business']->getKey(),
            'action_id' => $action->getKey(),
            'formal_record_version_id' => $created['formal_record_version_id'],
            'operations_role_id' => $context['role_id'],
            'source_type' => 'risk_item',
            'source_id' => $risk->getKey(),
        ]);

        [, $unassigned] = $this->member(
            $context['business'],
            'unassigned',
        );

        self::assertNull(
            $this->app->make(CreateRiskContinuityAction::class)->execute(
                $context['user'],
                $context['business'],
                'risk_item',
                (string) $risk->getKey(),
                $context['role_id'],
                (string) $unassigned->getKey(),
                'Must not assign outside current Operations responsibility',
            ),
        );

        $this->assertDatabaseCount('risk_continuity_action_links', 1);
    }

    /** @return array<string,mixed> */
    private function context(): array
    {
        $business = Business::query()->create([
            'name' => 'F6D Risk '.Str::uuid7(),
            'origin_type' => 'started_through_pbr',
            'business_stage' => 'operating',
            'setup_phase' => 'formation',
            'workspace_status' => 'active',
            'base_currency' => 'USD',
        ]);

        [$user, $membership] = $this->member($business, 'owner');
        [, $backup] = $this->member($business, 'backup');

        $this->app->make(ProvisionStandardAccessProfiles::class)
            ->execute($business, $membership);

        [$operationsVersionId, $roleId] = $this->operations(
            $business,
            $user,
            $membership,
            $backup,
        );

        return [
            'business' => $business,
            'user' => $user,
            'membership' => $membership,
            'backup' => $backup,
            'operations_version_id' => $operationsVersionId,
            'role_id' => $roleId,
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

    /** @return array{string,string} */
    private function operations(
        Business $business,
        User $user,
        Membership $owner,
        Membership $backup,
    ): array {
        $family = FormalRecordFamily::query()->create([
            'business_id' => $business->getKey(),
            'record_type' => 'operations_register',
            'subject_type' => 'business',
            'subject_id' => (string) $business->getKey(),
        ]);

        $version = FormalRecordVersion::query()->create([
            'business_id' => $business->getKey(),
            'formal_record_family_id' => $family->getKey(),
            'version_number' => 1,
            'predecessor_version_id' => null,
            'revision' => 1,
            'change_summary' => 'F6D operations fixture',
            'created_by_user_id' => $user->getKey(),
            'last_changed_by_user_id' => $user->getKey(),
            'effective_from' => now()->subMinute(),
            'effective_until' => null,
            'review_due_at' => null,
            'content_hash' => str_repeat('a', 64),
            'frozen_at' => null,
        ]);

        DB::table('operations_register_versions')->insert([
            'id' => (string) Str::uuid7(),
            'business_id' => $business->getKey(),
            'formal_record_version_id' => $version->getKey(),
            'organization_name' => 'F6D Operations',
            'notes' => null,
            'created_at' => now(),
        ]);

        $roleId = (string) Str::uuid7();
        DB::table('operations_roles')->insert([
            'id' => $roleId,
            'business_id' => $business->getKey(),
            'formal_record_version_id' => $version->getKey(),
            'role_key' => 'continuity_lead',
            'name' => 'Continuity Lead',
            'function_name' => 'Operations',
            'purpose' => 'Deliver risk and continuity controls.',
            'responsibilities' => 'Own assigned operational controls.',
            'operational_authority' => null,
            'reports_to_role_key' => null,
            'report_type' => null,
            'reporting_frequency' => 'Weekly',
            'meeting_frequency' => null,
            'review_frequency' => 'Quarterly',
            'status' => 'active',
            'created_at' => now(),
        ]);

        foreach ([[$owner, 'primary'], [$backup, 'backup']] as [$member, $type]) {
            DB::table('operations_role_assignments')->insert([
                'id' => (string) Str::uuid7(),
                'business_id' => $business->getKey(),
                'formal_record_version_id' => $version->getKey(),
                'operations_role_id' => $roleId,
                'membership_id' => $member->getKey(),
                'assignment_type' => $type,
                'created_at' => now(),
            ]);
        }

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

        return [(string) $version->getKey(), $roleId];
    }

    /** @param array<string,mixed> $context */
    private function payload(array $context): array
    {
        return [
            'risk_owner_membership_id' => (string) $context['membership']->getKey(),
            'low_max_score' => 4,
            'medium_max_score' => 9,
            'high_max_score' => 15,
            'review_frequency' => 'Quarterly',
            'notes' => 'F6D Risk Register fixture.',
            'risks' => [[
                'category' => 'operational',
                'title' => 'Supplier disruption',
                'description' => 'Primary supplier can stop delivery.',
                'operations_role_id' => $context['role_id'],
                'owner_membership_id' => (string) $context['membership']->getKey(),
                'likelihood' => 3,
                'impact' => 4,
                'warning_indicator' => 'Late shipments',
                'mitigation' => 'Maintain qualified alternate supplier.',
                'response_plan' => 'Activate alternate supplier and continuity plan.',
                'review_date' => now()->addMonth()->toDateString(),
                'status' => 'active',
                'confidentiality' => 'restricted',
            ]],
            'protections' => [[
                'risk_index' => 0,
                'protection_type' => 'insurance',
                'covered_subject' => 'Business interruption',
                'provider' => 'Fixture Insurer',
                'policy_reference' => 'MASKED-RISK-001',
                'coverage_amount_minor_units' => 10000000,
                'currency' => 'USD',
                'deductible_minor_units' => 100000,
                'main_exclusions' => 'Fixture exclusions',
                'premium_minor_units' => 25000,
                'start_date' => now()->toDateString(),
                'renewal_date' => now()->addYear()->toDateString(),
                'owner_membership_id' => (string) $context['membership']->getKey(),
                'access_rule' => 'Authorized Risk team only.',
                'protection_method' => 'Insurance plus alternate supplier control.',
                'confidentiality_requirement' => 'Restricted.',
                'evidence_reference' => 'Vault evidence reference only.',
                'review_date' => now()->addMonth()->toDateString(),
                'status' => 'active',
                'confidentiality' => 'restricted',
            ]],
        ];
    }

    private function forceEffective(
        Business $business,
        User $user,
        string $versionId,
    ): void {
        $version = FormalRecordVersion::query()->findOrFail($versionId);
        $version->frozen_at = now();
        $version->save();

        foreach ([
            ['draft', 'ready_for_review'],
            ['ready_for_review', 'under_review'],
            ['under_review', 'approved'],
            ['approved', 'ready_for_effect'],
            ['ready_for_effect', 'effective'],
        ] as $offset => [$from, $to]) {
            DB::table('record_version_state_transitions')->insert([
                'id' => (string) Str::uuid7(),
                'business_id' => $business->getKey(),
                'formal_record_version_id' => $version->getKey(),
                'sequence' => $offset + 2,
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
            'formal_record_family_id' => $version->formal_record_family_id,
            'formal_record_version_id' => $version->getKey(),
            'activated_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
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
