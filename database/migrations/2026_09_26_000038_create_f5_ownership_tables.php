<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

return new class extends Migration
{
    public function up(): void
    {
        $this->backfillOwnershipCapabilities();

        Schema::create('ownership_scenarios', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('business_id');
            $table->string('name', 160);
            $table->string('currency', 3);
            $table->unsignedBigInteger('share_value_minor_units');
            $table->decimal('authorized_shares', 28, 8);
            $table->decimal('reserved_unissued_shares', 28, 8)->default(0);
            $table->string('status', 32)->default('draft');
            $table->unsignedInteger('revision')->default(1);
            $table->timestampTz('frozen_at')->nullable();
            $table->uuid('created_by_membership_id');
            $table->timestampsTz();

            $table->foreign('business_id')
                ->references('id')
                ->on('businesses');

            $table->foreign('created_by_membership_id')
                ->references('id')
                ->on('memberships');

            $table->index(['business_id', 'status']);
        });

        Schema::create(
            'ownership_scenario_share_classes',
            function (Blueprint $table): void {
                $table->uuid('id')->primary();
                $table->uuid('business_id');
                $table->uuid('ownership_scenario_id');
                $table->string('name', 120);
                $table->decimal('voting_right_per_share', 20, 8)->default(1);
                $table->decimal('profit_right_per_share', 20, 8)->default(1);
                $table->boolean('transfer_allowed')->default(true);
                $table->text('restrictions')->nullable();
                $table->text('special_rights')->nullable();
                $table->timestampsTz();

                $table->foreign('business_id')
                    ->references('id')
                    ->on('businesses');

                $table->foreign('ownership_scenario_id')
                    ->references('id')
                    ->on('ownership_scenarios');

                $table->unique(
                    ['ownership_scenario_id', 'name'],
                    'ownership_scenario_share_classes_name_unique',
                );
            },
        );

        Schema::create(
            'ownership_scenario_positions',
            function (Blueprint $table): void {
                $table->uuid('id')->primary();
                $table->uuid('business_id');
                $table->uuid('ownership_scenario_id');
                $table->uuid('partner_id');
                $table->uuid('share_class_id');

                $table->unsignedBigInteger(
                    'accepted_contribution_minor_units',
                );

                $table->decimal('shares_issued', 28, 8);
                $table->decimal('shares_vested', 28, 8);
                $table->decimal('voting_rights', 28, 8);
                $table->decimal('profit_rights', 28, 8);

                $table->date('issue_date')->nullable();

                $table->date('vesting_start_date')->nullable();
                $table->unsignedInteger('vesting_period_months')->nullable();
                $table->unsignedInteger('vesting_cliff_months')->nullable();
                $table->text('vesting_conditions')->nullable();
                $table->text('early_exit_treatment')->nullable();

                $table->timestampsTz();

                $table->foreign('business_id')
                    ->references('id')
                    ->on('businesses');

                $table->foreign('ownership_scenario_id')
                    ->references('id')
                    ->on('ownership_scenarios');

                $table->foreign('partner_id')
                    ->references('id')
                    ->on('partners');

                $table->foreign('share_class_id')
                    ->references('id')
                    ->on('ownership_scenario_share_classes');

                $table->unique(
                    [
                        'ownership_scenario_id',
                        'partner_id',
                        'share_class_id',
                    ],
                    'ownership_scenario_positions_partner_class_unique',
                );
            },
        );

        Schema::create(
            'ownership_scenario_contribution_sources',
            function (Blueprint $table): void {
                $table->uuid('id')->primary();
                $table->uuid('business_id');
                $table->uuid('ownership_scenario_id');
                $table->uuid('contribution_id');
                $table->uuid('partner_id');
                $table->unsignedInteger('contribution_revision');
                $table->string('currency', 3);
                $table->unsignedBigInteger('accepted_value_minor_units');
                $table->timestampTz('captured_at');

                $table->foreign('business_id')
                    ->references('id')
                    ->on('businesses');

                $table->foreign('ownership_scenario_id')
                    ->references('id')
                    ->on('ownership_scenarios');

                $table->foreign('contribution_id')
                    ->references('id')
                    ->on('contributions');

                $table->foreign('partner_id')
                    ->references('id')
                    ->on('partners');

                $table->unique(
                    ['ownership_scenario_id', 'contribution_id'],
                    'ownership_scenario_contribution_unique',
                );
            },
        );

        Schema::create('ownership_registers', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('business_id')->unique();
            $table->timestampsTz();

            $table->foreign('business_id')
                ->references('id')
                ->on('businesses');
        });

        Schema::create(
            'ownership_register_versions',
            function (Blueprint $table): void {
                $table->uuid('id')->primary();
                $table->uuid('business_id');
                $table->uuid('ownership_register_id');
                $table->unsignedInteger('version_number');
                $table->uuid('source_ownership_scenario_id');
                $table->uuid('proposal_version_id')->nullable();
                $table->uuid('governance_decision_id')->nullable();

                $table->string('currency', 3);
                $table->unsignedBigInteger('share_value_minor_units');
                $table->decimal('authorized_shares', 28, 8);
                $table->decimal('issued_shares', 28, 8);
                $table->decimal('reserved_unissued_shares', 28, 8);
                $table->decimal('available_shares', 28, 8);

                $table->string('status', 32)->default('pending_effect');

                $table->timestampTz('approved_at')->nullable();
                $table->timestampTz('signed_at')->nullable();
                $table->timestampTz('effective_from')->nullable();
                $table->timestampTz('effective_until')->nullable();

                $table->uuid('authority_snapshot_id')->nullable();
                $table->uuid('created_by_membership_id');
                $table->timestampsTz();

                $table->foreign('business_id')
                    ->references('id')
                    ->on('businesses');

                $table->foreign('ownership_register_id')
                    ->references('id')
                    ->on('ownership_registers');

                $table->foreign('source_ownership_scenario_id')
                    ->references('id')
                    ->on('ownership_scenarios');

                $table->foreign('proposal_version_id')
                    ->references('id')
                    ->on('proposal_versions');

                $table->foreign('created_by_membership_id')
                    ->references('id')
                    ->on('memberships');

                $table->unique(
                    ['ownership_register_id', 'version_number'],
                    'ownership_register_versions_number_unique',
                );

                $table->index(
                    ['business_id', 'status', 'effective_from'],
                    'ownership_register_versions_effective_lookup',
                );
            },
        );

        Schema::create(
            'ownership_register_share_classes',
            function (Blueprint $table): void {
                $table->uuid('id')->primary();
                $table->uuid('business_id');
                $table->uuid('ownership_register_version_id');
                $table->string('name', 120);
                $table->decimal('voting_right_per_share', 20, 8);
                $table->decimal('profit_right_per_share', 20, 8);
                $table->boolean('transfer_allowed');
                $table->text('restrictions')->nullable();
                $table->text('special_rights')->nullable();

                $table->foreign('business_id')
                    ->references('id')
                    ->on('businesses');

                $table->foreign('ownership_register_version_id')
                    ->references('id')
                    ->on('ownership_register_versions');

                $table->unique(
                    ['ownership_register_version_id', 'name'],
                    'ownership_register_share_classes_name_unique',
                );
            },
        );

        Schema::create(
            'ownership_register_positions',
            function (Blueprint $table): void {
                $table->uuid('id')->primary();
                $table->uuid('business_id');
                $table->uuid('ownership_register_version_id');
                $table->uuid('partner_id');
                $table->uuid('share_class_id');

                $table->unsignedBigInteger(
                    'accepted_contribution_minor_units',
                );

                $table->decimal('shares_issued', 28, 8);
                $table->decimal('shares_vested', 28, 8);
                $table->decimal('voting_rights', 28, 8);
                $table->decimal('profit_rights', 28, 8);

                $table->date('issue_date')->nullable();
                $table->date('vesting_start_date')->nullable();
                $table->unsignedInteger('vesting_period_months')->nullable();
                $table->unsignedInteger('vesting_cliff_months')->nullable();
                $table->text('vesting_conditions')->nullable();
                $table->text('early_exit_treatment')->nullable();

                $table->foreign('business_id')
                    ->references('id')
                    ->on('businesses');

                $table->foreign('ownership_register_version_id')
                    ->references('id')
                    ->on('ownership_register_versions');

                $table->foreign('partner_id')
                    ->references('id')
                    ->on('partners');

                $table->foreign('share_class_id')
                    ->references('id')
                    ->on('ownership_register_share_classes');

                $table->unique(
                    [
                        'ownership_register_version_id',
                        'partner_id',
                        'share_class_id',
                    ],
                    'ownership_register_positions_partner_class_unique',
                );
            },
        );

        Schema::create(
            'ownership_register_contribution_sources',
            function (Blueprint $table): void {
                $table->uuid('id')->primary();
                $table->uuid('business_id');
                $table->uuid('ownership_register_version_id');
                $table->uuid('contribution_id');
                $table->uuid('partner_id');
                $table->unsignedInteger('contribution_revision');
                $table->string('currency', 3);
                $table->unsignedBigInteger('accepted_value_minor_units');

                $table->foreign('business_id')
                    ->references('id')
                    ->on('businesses');

                $table->foreign('ownership_register_version_id')
                    ->references('id')
                    ->on('ownership_register_versions');

                $table->foreign('contribution_id')
                    ->references('id')
                    ->on('contributions');

                $table->foreign('partner_id')
                    ->references('id')
                    ->on('partners');

                $table->unique(
                    ['ownership_register_version_id', 'contribution_id'],
                    'ownership_register_contribution_unique',
                );
            },
        );

        /*
         * Scenario tenant integrity.
         */
        DB::statement(<<<'SQL'
CREATE OR REPLACE FUNCTION thepbr_f5_ownership_scenario_guard()
RETURNS trigger
LANGUAGE plpgsql
AS $$
BEGIN
    IF TG_OP = 'UPDATE'
       AND OLD.business_id IS DISTINCT FROM NEW.business_id THEN
        RAISE EXCEPTION 'Ownership Scenario cannot move between Businesses';
    END IF;

    IF TG_OP = 'UPDATE'
       AND NEW.revision <= OLD.revision THEN
        RAISE EXCEPTION 'Ownership Scenario revision must increase';
    END IF;

    IF TG_OP = 'UPDATE'
       AND OLD.status <> 'draft' THEN
        RAISE EXCEPTION 'Frozen Ownership Scenario is immutable';
    END IF;

    IF TG_OP = 'DELETE'
       AND OLD.status <> 'draft' THEN
        RAISE EXCEPTION 'Frozen Ownership Scenario is immutable';
    END IF;

    RETURN COALESCE(NEW, OLD);
END;
$$
SQL);

        DB::statement(<<<'SQL'
CREATE TRIGGER ownership_scenarios_guard
BEFORE UPDATE OR DELETE ON ownership_scenarios
FOR EACH ROW EXECUTE FUNCTION thepbr_f5_ownership_scenario_guard()
SQL);

        /*
         * Scenario children may only mutate while their parent is Draft and
         * must share the same Business.
         */
        foreach ([
            'ownership_scenario_share_classes',
            'ownership_scenario_positions',
            'ownership_scenario_contribution_sources',
        ] as $table) {
            DB::statement(sprintf(<<<'SQL'
CREATE OR REPLACE FUNCTION thepbr_%1$s_guard()
RETURNS trigger
LANGUAGE plpgsql
AS $$
DECLARE
    parent_business uuid;
    parent_status text;
BEGIN
    SELECT business_id, status
      INTO parent_business, parent_status
      FROM ownership_scenarios
     WHERE id = COALESCE(NEW.ownership_scenario_id, OLD.ownership_scenario_id);

    IF parent_business IS NULL THEN
        RAISE EXCEPTION 'Ownership Scenario parent does not exist';
    END IF;

    IF COALESCE(NEW.business_id, OLD.business_id) <> parent_business THEN
        RAISE EXCEPTION 'Ownership Scenario child Business mismatch';
    END IF;

    IF parent_status <> 'draft' THEN
        RAISE EXCEPTION 'Frozen Ownership Scenario content is immutable';
    END IF;

    RETURN COALESCE(NEW, OLD);
END;
$$
SQL, $table));

            DB::statement(sprintf(
                'CREATE TRIGGER %1$s_guard
                 BEFORE INSERT OR UPDATE OR DELETE ON %1$s
                 FOR EACH ROW EXECUTE FUNCTION thepbr_%1$s_guard()',
                $table,
            ));
        }

        /*
         * Effective/past register snapshots are append-only.
         * Pending versions may be prepared by the later F2/F3 integration.
         */
        DB::statement(<<<'SQL'
CREATE OR REPLACE FUNCTION thepbr_f5_ownership_register_version_guard()
RETURNS trigger
LANGUAGE plpgsql
AS $$
BEGIN
    IF TG_OP = 'DELETE' THEN
        RAISE EXCEPTION 'Ownership Register Version history cannot be deleted';
    END IF;

    IF OLD.business_id IS DISTINCT FROM NEW.business_id
       OR OLD.ownership_register_id IS DISTINCT FROM NEW.ownership_register_id
       OR OLD.version_number IS DISTINCT FROM NEW.version_number
       OR OLD.source_ownership_scenario_id IS DISTINCT FROM NEW.source_ownership_scenario_id
       OR OLD.currency IS DISTINCT FROM NEW.currency
       OR OLD.share_value_minor_units IS DISTINCT FROM NEW.share_value_minor_units
       OR OLD.authorized_shares IS DISTINCT FROM NEW.authorized_shares
       OR OLD.issued_shares IS DISTINCT FROM NEW.issued_shares
       OR OLD.reserved_unissued_shares IS DISTINCT FROM NEW.reserved_unissued_shares
       OR OLD.available_shares IS DISTINCT FROM NEW.available_shares THEN
        RAISE EXCEPTION 'Ownership Register Version snapshot is immutable';
    END IF;

    IF OLD.status IN ('effective', 'superseded', 'archived')
       AND (
           OLD.proposal_version_id IS DISTINCT FROM NEW.proposal_version_id
           OR OLD.governance_decision_id IS DISTINCT FROM NEW.governance_decision_id
           OR OLD.authority_snapshot_id IS DISTINCT FROM NEW.authority_snapshot_id
           OR OLD.approved_at IS DISTINCT FROM NEW.approved_at
           OR OLD.signed_at IS DISTINCT FROM NEW.signed_at
           OR OLD.effective_from IS DISTINCT FROM NEW.effective_from
       ) THEN
        RAISE EXCEPTION 'Effective Ownership Register Version is immutable';
    END IF;

    IF OLD.status = 'effective'
       AND NEW.status NOT IN ('effective', 'superseded') THEN
        RAISE EXCEPTION 'Effective Ownership Register Version may only become Superseded';
    END IF;

    RETURN NEW;
END;
$$
SQL);

        DB::statement(<<<'SQL'
CREATE TRIGGER ownership_register_versions_guard
BEFORE UPDATE OR DELETE ON ownership_register_versions
FOR EACH ROW EXECUTE FUNCTION thepbr_f5_ownership_register_version_guard()
SQL);

        foreach ([
            'ownership_register_share_classes',
            'ownership_register_positions',
            'ownership_register_contribution_sources',
        ] as $table) {
            DB::statement(sprintf(<<<'SQL'
CREATE OR REPLACE FUNCTION thepbr_%1$s_immutable()
RETURNS trigger
LANGUAGE plpgsql
AS $$
BEGIN
    RAISE EXCEPTION 'Ownership Register snapshot content is immutable';
END;
$$
SQL, $table));

            DB::statement(sprintf(
                'CREATE TRIGGER %1$s_immutable
                 BEFORE UPDATE OR DELETE ON %1$s
                 FOR EACH ROW EXECUTE FUNCTION thepbr_%1$s_immutable()',
                $table,
            ));
        }

        /*
         * Only Accepted Contribution records may be snapshotted as ownership
         * sources. This protects against application bypass.
         */
        DB::statement(<<<'SQL'
CREATE OR REPLACE FUNCTION thepbr_f5_ownership_contribution_source_guard()
RETURNS trigger
LANGUAGE plpgsql
AS $$
DECLARE
    contribution_business uuid;
    contribution_partner uuid;
    contribution_status text;
    contribution_revision integer;
BEGIN
    SELECT business_id, partner_id, status, revision
      INTO contribution_business,
           contribution_partner,
           contribution_status,
           contribution_revision
      FROM contributions
     WHERE id = NEW.contribution_id;

    IF contribution_business IS NULL THEN
        RAISE EXCEPTION 'Contribution does not exist';
    END IF;

    IF contribution_status <> 'accepted' THEN
        RAISE EXCEPTION 'Only Accepted Contribution may feed Ownership';
    END IF;

    IF contribution_business <> NEW.business_id
       OR contribution_partner <> NEW.partner_id THEN
        RAISE EXCEPTION 'Ownership Contribution source tenant/Partner mismatch';
    END IF;

    IF contribution_revision <> NEW.contribution_revision THEN
        RAISE EXCEPTION 'Ownership Contribution source revision mismatch';
    END IF;

    IF NOT EXISTS (
        SELECT 1
          FROM contributions c
         WHERE c.id = NEW.contribution_id
           AND c.accepted_value IS NOT NULL
           AND CAST(
               ROUND(c.accepted_value * 100, 0)
               AS bigint
           ) = NEW.accepted_value_minor_units
           AND c.currency = NEW.currency
    ) THEN
        RAISE EXCEPTION 'Ownership Contribution source value/currency mismatch';
    END IF;

    RETURN NEW;
END;
$$
SQL);

        DB::statement(<<<'SQL'
CREATE TRIGGER ownership_scenario_contribution_source_guard
BEFORE INSERT ON ownership_scenario_contribution_sources
FOR EACH ROW EXECUTE FUNCTION thepbr_f5_ownership_contribution_source_guard()
SQL);

        DB::statement(<<<'SQL'
CREATE TRIGGER ownership_register_contribution_source_guard
BEFORE INSERT ON ownership_register_contribution_sources
FOR EACH ROW EXECUTE FUNCTION thepbr_f5_ownership_contribution_source_guard()
SQL);

        /*
         * One current Effective register version per Business.
         * Future-dated pending versions are allowed.
         */
        DB::statement(<<<'SQL'
CREATE UNIQUE INDEX ownership_one_effective_version_per_business
ON ownership_register_versions (business_id)
WHERE status = 'effective'
SQL);
    }

    private function backfillOwnershipCapabilities(): void
    {
        $now = now();

        $capabilities = [
            'ownership.view',
            'ownership.manage',
        ];

        $permissionIds = [];

        foreach ($capabilities as $key) {
            $existing = DB::table('permissions')
                ->where('key', $key)
                ->value('id');

            if ($existing === null) {
                $existing = (string) Str::uuid7();

                DB::table('permissions')->insert([
                    'id' => $existing,
                    'key' => $key,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
            }

            $permissionIds[$key] = (string) $existing;
        }

        $matrix = [
            'Workspace Owner' => [
                'ownership.view',
                'ownership.manage',
            ],
            'Partner' => [
                'ownership.view',
            ],
            'Managing Partner / CEO' => [
                'ownership.view',
                'ownership.manage',
            ],
            'Finance Owner' => [
                'ownership.view',
            ],
            'Governance Secretary / PBR Administrator' => [
                'ownership.view',
                'ownership.manage',
            ],
            'Advisor / Consultant' => [
                'ownership.view',
            ],
            'Auditor / Viewer' => [
                'ownership.view',
            ],
            'External Accountant / Legal Advisor' => [
                'ownership.view',
            ],
        ];

        $profiles = DB::table('permission_profiles')
            ->whereIn('name', array_keys($matrix))
            ->get([
                'id',
                'business_id',
                'name',
            ]);

        foreach ($profiles as $profile) {
            foreach ($matrix[$profile->name] as $capability) {
                DB::table('permission_profile_permissions')
                    ->insertOrIgnore([
                        'business_id' => $profile->business_id,
                        'permission_profile_id' => $profile->id,
                        'permission_id' => $permissionIds[$capability],
                        'created_at' => $now,
                        'updated_at' => $now,
                    ]);
            }
        }
    }

    public function down(): void
    {
        DB::statement(
            'DROP INDEX IF EXISTS ownership_one_effective_version_per_business',
        );

        DB::statement(
            'DROP TRIGGER IF EXISTS ownership_register_contribution_source_guard
             ON ownership_register_contribution_sources',
        );

        DB::statement(
            'DROP TRIGGER IF EXISTS ownership_scenario_contribution_source_guard
             ON ownership_scenario_contribution_sources',
        );

        DB::statement(
            'DROP FUNCTION IF EXISTS thepbr_f5_ownership_contribution_source_guard()',
        );

        foreach ([
            'ownership_register_contribution_sources',
            'ownership_register_positions',
            'ownership_register_share_classes',
        ] as $table) {
            DB::statement(sprintf(
                'DROP TRIGGER IF EXISTS %1$s_immutable ON %1$s',
                $table,
            ));

            DB::statement(sprintf(
                'DROP FUNCTION IF EXISTS thepbr_%1$s_immutable()',
                $table,
            ));
        }

        DB::statement(
            'DROP TRIGGER IF EXISTS ownership_register_versions_guard
             ON ownership_register_versions',
        );

        DB::statement(
            'DROP FUNCTION IF EXISTS thepbr_f5_ownership_register_version_guard()',
        );

        foreach ([
            'ownership_scenario_contribution_sources',
            'ownership_scenario_positions',
            'ownership_scenario_share_classes',
        ] as $table) {
            DB::statement(sprintf(
                'DROP TRIGGER IF EXISTS %1$s_guard ON %1$s',
                $table,
            ));

            DB::statement(sprintf(
                'DROP FUNCTION IF EXISTS thepbr_%1$s_guard()',
                $table,
            ));
        }

        DB::statement(
            'DROP TRIGGER IF EXISTS ownership_scenarios_guard
             ON ownership_scenarios',
        );

        DB::statement(
            'DROP FUNCTION IF EXISTS thepbr_f5_ownership_scenario_guard()',
        );

        Schema::dropIfExists('ownership_register_contribution_sources');
        Schema::dropIfExists('ownership_register_positions');
        Schema::dropIfExists('ownership_register_share_classes');
        Schema::dropIfExists('ownership_register_versions');
        Schema::dropIfExists('ownership_registers');

        Schema::dropIfExists('ownership_scenario_contribution_sources');
        Schema::dropIfExists('ownership_scenario_positions');
        Schema::dropIfExists('ownership_scenario_share_classes');
        Schema::dropIfExists('ownership_scenarios');
    }
};
