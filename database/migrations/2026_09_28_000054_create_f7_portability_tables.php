<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

return new class extends Migration
{
    /** @var list<string> */
    private array $managerProfiles = [
        'Workspace Owner',
        'Managing Partner / CEO',
        'Governance Secretary / PBR Administrator',
    ];

    public function up(): void
    {
        $this->backfillCapabilities();

        Schema::create(
            'business_archive_transitions',
            function (Blueprint $table): void {
                $table->uuid('id')->primary();
                $table->uuid('business_id');
                $table->uuid('actor_membership_id');
                $table->string('from_status', 32);
                $table->string('to_status', 32);
                $table->text('reason');
                $table->timestampTz('occurred_at');
                $table->timestampTz('created_at')->useCurrent();

                $table->unique(
                    ['id', 'business_id'],
                    'business_archive_transitions_id_business_uq',
                );

                $table->index(
                    ['business_id', 'occurred_at'],
                    'business_archive_transitions_history_idx',
                );

                $table->foreign('business_id')
                    ->references('id')
                    ->on('businesses')
                    ->restrictOnDelete();

                $table->foreign('actor_membership_id')
                    ->references('id')
                    ->on('memberships')
                    ->restrictOnDelete();
            },
        );

        Schema::create(
            'business_portability_exports',
            function (Blueprint $table): void {
                $table->uuid('id')->primary();
                $table->uuid('business_id');
                $table->uuid('requested_by_membership_id');
                $table->jsonb('requested_categories');
                $table->jsonb('excluded_categories');
                $table->jsonb('frozen_manifest')->nullable();
                $table->char('manifest_hash', 64)->nullable();
                $table->string('status', 32)->default('requested');
                $table->text('storage_key')->nullable();
                $table->string('output_filename', 255)->nullable();
                $table->string('mime_type', 160)->nullable();
                $table->unsignedBigInteger('size_bytes')->nullable();
                $table->char('content_sha256', 64)->nullable();
                $table->timestampTz('requested_at');
                $table->timestampTz('generated_at')->nullable();
                $table->timestampTz('verified_at')->nullable();
                $table->timestampTz('available_at')->nullable();
                $table->timestampsTz();

                $table->unique(
                    ['id', 'business_id'],
                    'business_portability_exports_id_business_uq',
                );

                $table->index(
                    ['business_id', 'requested_by_membership_id', 'created_at'],
                    'business_portability_exports_requester_idx',
                );

                $table->index(
                    ['business_id', 'status', 'created_at'],
                    'business_portability_exports_status_idx',
                );

                $table->foreign('business_id')
                    ->references('id')
                    ->on('businesses')
                    ->restrictOnDelete();

                $table->foreign('requested_by_membership_id')
                    ->references('id')
                    ->on('memberships')
                    ->restrictOnDelete();
            },
        );

        Schema::create(
            'business_portability_export_transitions',
            function (Blueprint $table): void {
                $table->uuid('id')->primary();
                $table->uuid('business_id');
                $table->uuid('business_portability_export_id');
                $table->string('from_status', 32)->nullable();
                $table->string('to_status', 32);
                $table->uuid('actor_membership_id')->nullable();
                $table->timestampTz('occurred_at');
                $table->timestampTz('created_at')->useCurrent();

                $table->unique(
                    ['id', 'business_id'],
                    'business_portability_transition_id_business_uq',
                );

                $table->index(
                    ['business_id', 'business_portability_export_id', 'occurred_at'],
                    'business_portability_transition_export_idx',
                );

                $table->foreign(
                    ['business_portability_export_id', 'business_id'],
                    'business_portability_transition_export_fk',
                )->references(['id', 'business_id'])
                    ->on('business_portability_exports')
                    ->restrictOnDelete();

                $table->foreign('actor_membership_id')
                    ->references('id')
                    ->on('memberships')
                    ->restrictOnDelete();
            },
        );

        DB::unprepared(<<<'SQL'
ALTER TABLE business_archive_transitions
ADD CONSTRAINT business_archive_from_status_check
CHECK (from_status IN ('active','restricted','archived')),
ADD CONSTRAINT business_archive_to_status_check
CHECK (to_status IN ('active','restricted','archived')),
ADD CONSTRAINT business_archive_transition_shape_check
CHECK (
    (from_status IN ('active','restricted') AND to_status = 'archived')
    OR
    (from_status = 'archived' AND to_status IN ('active','restricted'))
);

ALTER TABLE business_portability_exports
ADD CONSTRAINT business_portability_categories_array_check
CHECK (jsonb_typeof(requested_categories) = 'array'),
ADD CONSTRAINT business_portability_exclusions_array_check
CHECK (jsonb_typeof(excluded_categories) = 'array'),
ADD CONSTRAINT business_portability_manifest_object_check
CHECK (
    frozen_manifest IS NULL
    OR jsonb_typeof(frozen_manifest) = 'object'
),
ADD CONSTRAINT business_portability_status_check
CHECK (status IN (
    'requested','manifest_frozen','generating',
    'verifying','available','failed'
));

ALTER TABLE business_portability_export_transitions
ADD CONSTRAINT business_portability_transition_from_check
CHECK (
    from_status IS NULL
    OR from_status IN (
        'requested','manifest_frozen','generating',
        'verifying','available','failed'
    )
),
ADD CONSTRAINT business_portability_transition_to_check
CHECK (to_status IN (
    'requested','manifest_frozen','generating',
    'verifying','available','failed'
));
SQL);

        $this->addTenantGuards();
        $this->addHistoryGuards();
    }

    private function addTenantGuards(): void
    {
        DB::unprepared(<<<'SQL'
CREATE OR REPLACE FUNCTION pbr_f7_validate_archive_transition_tenant()
RETURNS trigger LANGUAGE plpgsql AS $$
DECLARE
    v_actor_business uuid;
    v_workspace_status text;
BEGIN
    SELECT business_id
      INTO v_actor_business
      FROM memberships
     WHERE id = NEW.actor_membership_id;

    IF v_actor_business IS NULL
       OR v_actor_business <> NEW.business_id
    THEN
        RAISE EXCEPTION
            'Archive transition actor Membership must belong to the same Business';
    END IF;

    SELECT workspace_status
      INTO v_workspace_status
      FROM businesses
     WHERE id = NEW.business_id;

    IF v_workspace_status IS NULL
       OR v_workspace_status <> NEW.to_status
    THEN
        RAISE EXCEPTION
            'Archive transition must match the current Business workspace status';
    END IF;

    RETURN NEW;
END;
$$;

CREATE TRIGGER business_archive_transitions_tenant_guard
BEFORE INSERT ON business_archive_transitions
FOR EACH ROW EXECUTE FUNCTION pbr_f7_validate_archive_transition_tenant();

CREATE OR REPLACE FUNCTION pbr_f7_validate_portability_export_tenant()
RETURNS trigger LANGUAGE plpgsql AS $$
DECLARE
    v_membership_business uuid;
BEGIN
    SELECT business_id
      INTO v_membership_business
      FROM memberships
     WHERE id = NEW.requested_by_membership_id;

    IF v_membership_business IS NULL
       OR v_membership_business <> NEW.business_id
    THEN
        RAISE EXCEPTION
            'Portability Export requester Membership must belong to the same Business';
    END IF;

    RETURN NEW;
END;
$$;

CREATE TRIGGER business_portability_exports_tenant_guard
BEFORE INSERT OR UPDATE ON business_portability_exports
FOR EACH ROW EXECUTE FUNCTION pbr_f7_validate_portability_export_tenant();

CREATE OR REPLACE FUNCTION pbr_f7_validate_portability_transition_tenant()
RETURNS trigger LANGUAGE plpgsql AS $$
DECLARE
    v_export_business uuid;
    v_actor_business uuid;
BEGIN
    SELECT business_id
      INTO v_export_business
      FROM business_portability_exports
     WHERE id = NEW.business_portability_export_id;

    IF v_export_business IS NULL
       OR v_export_business <> NEW.business_id
    THEN
        RAISE EXCEPTION
            'Portability Export transition must match export Business';
    END IF;

    IF NEW.actor_membership_id IS NOT NULL THEN
        SELECT business_id
          INTO v_actor_business
          FROM memberships
         WHERE id = NEW.actor_membership_id;

        IF v_actor_business IS NULL
           OR v_actor_business <> NEW.business_id
        THEN
            RAISE EXCEPTION
                'Portability Export transition actor must belong to the same Business';
        END IF;
    END IF;

    RETURN NEW;
END;
$$;

CREATE TRIGGER business_portability_export_transitions_tenant_guard
BEFORE INSERT ON business_portability_export_transitions
FOR EACH ROW EXECUTE FUNCTION pbr_f7_validate_portability_transition_tenant();
SQL);
    }

    private function addHistoryGuards(): void
    {
        DB::unprepared(<<<'SQL'
CREATE OR REPLACE FUNCTION pbr_f7_archive_transition_append_only()
RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN
    RAISE EXCEPTION 'Business Archive transition history is append-only';
END;
$$;

CREATE TRIGGER business_archive_transitions_append_only
BEFORE UPDATE OR DELETE ON business_archive_transitions
FOR EACH ROW EXECUTE FUNCTION pbr_f7_archive_transition_append_only();

CREATE OR REPLACE FUNCTION pbr_f7_portability_transition_append_only()
RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN
    RAISE EXCEPTION 'Portability Export transition history is append-only';
END;
$$;

CREATE TRIGGER business_portability_transitions_append_only
BEFORE UPDATE OR DELETE ON business_portability_export_transitions
FOR EACH ROW EXECUTE FUNCTION pbr_f7_portability_transition_append_only();

CREATE OR REPLACE FUNCTION pbr_f7_protect_portability_export()
RETURNS trigger LANGUAGE plpgsql AS $$
DECLARE
    v_valid_transition boolean := false;
BEGIN
    IF TG_OP = 'DELETE' THEN
        RAISE EXCEPTION 'Portability Export history cannot be deleted';
    END IF;

    IF NEW.business_id IS DISTINCT FROM OLD.business_id
       OR NEW.requested_by_membership_id
            IS DISTINCT FROM OLD.requested_by_membership_id
       OR NEW.requested_categories IS DISTINCT FROM OLD.requested_categories
       OR NEW.requested_at IS DISTINCT FROM OLD.requested_at
       OR NEW.created_at IS DISTINCT FROM OLD.created_at
    THEN
        RAISE EXCEPTION 'Portability Export request identity is immutable';
    END IF;

    IF OLD.frozen_manifest IS NOT NULL
       AND NEW.frozen_manifest IS DISTINCT FROM OLD.frozen_manifest
    THEN
        RAISE EXCEPTION 'Frozen Portability Export manifest is immutable';
    END IF;

    IF OLD.manifest_hash IS NOT NULL
       AND NEW.manifest_hash IS DISTINCT FROM OLD.manifest_hash
    THEN
        RAISE EXCEPTION 'Portability Export manifest hash is immutable';
    END IF;

    IF OLD.frozen_manifest IS NOT NULL
       AND NEW.excluded_categories IS DISTINCT FROM OLD.excluded_categories
    THEN
        RAISE EXCEPTION 'Portability Export exclusions are immutable once frozen';
    END IF;

    IF OLD.status = 'available' THEN
        RAISE EXCEPTION 'Available Portability Export is immutable';
    END IF;

    IF OLD.status = 'failed' THEN
        RAISE EXCEPTION 'Failed Portability Export is terminal';
    END IF;

    IF NEW.status IS DISTINCT FROM OLD.status THEN
        v_valid_transition := CASE OLD.status
            WHEN 'requested' THEN NEW.status IN ('manifest_frozen','failed')
            WHEN 'manifest_frozen' THEN NEW.status IN ('generating','failed')
            WHEN 'generating' THEN NEW.status IN ('verifying','failed')
            WHEN 'verifying' THEN NEW.status IN ('available','failed')
            ELSE false
        END;

        IF NOT v_valid_transition THEN
            RAISE EXCEPTION 'Invalid Portability Export status transition';
        END IF;
    END IF;

    IF NEW.status IN (
        'manifest_frozen','generating','verifying','available'
    ) THEN
        IF NEW.frozen_manifest IS NULL
           OR NEW.manifest_hash IS NULL
        THEN
            RAISE EXCEPTION
                'Frozen Portability Export manifest and hash are required';
        END IF;
    END IF;

    IF NEW.status = 'available' THEN
        IF NEW.storage_key IS NULL
           OR NEW.output_filename IS NULL
           OR NEW.mime_type IS NULL
           OR NEW.size_bytes IS NULL
           OR NEW.content_sha256 IS NULL
           OR NEW.generated_at IS NULL
           OR NEW.verified_at IS NULL
           OR NEW.available_at IS NULL
        THEN
            RAISE EXCEPTION
                'Available Portability Export requires verified private output metadata';
        END IF;
    END IF;

    IF OLD.storage_key IS NOT NULL
       AND NEW.storage_key IS DISTINCT FROM OLD.storage_key
    THEN
        RAISE EXCEPTION
            'Portability Export storage key is immutable once assigned';
    END IF;

    IF OLD.content_sha256 IS NOT NULL
       AND NEW.content_sha256 IS DISTINCT FROM OLD.content_sha256
    THEN
        RAISE EXCEPTION
            'Portability Export output hash is immutable once assigned';
    END IF;

    RETURN NEW;
END;
$$;

CREATE TRIGGER business_portability_exports_history_guard
BEFORE UPDATE OR DELETE ON business_portability_exports
FOR EACH ROW EXECUTE FUNCTION pbr_f7_protect_portability_export();
SQL);
    }

    private function backfillCapabilities(): void
    {
        $profiles = DB::table('permission_profiles')
            ->whereIn('name', $this->managerProfiles)
            ->get(['id', 'business_id']);

        foreach (['portability.view', 'portability.manage'] as $capability) {
            $permissionId = DB::table('permissions')
                ->where('key', $capability)
                ->value('id');

            if ($permissionId === null) {
                $permissionId = (string) Str::uuid7();

                DB::table('permissions')->insert([
                    'id' => $permissionId,
                    'key' => $capability,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }

            foreach ($profiles as $profile) {
                DB::table('permission_profile_permissions')->insertOrIgnore([
                    'business_id' => $profile->business_id,
                    'permission_profile_id' => $profile->id,
                    'permission_id' => $permissionId,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('business_portability_export_transitions');
        Schema::dropIfExists('business_portability_exports');
        Schema::dropIfExists('business_archive_transitions');

        foreach ([
            'pbr_f7_protect_portability_export',
            'pbr_f7_portability_transition_append_only',
            'pbr_f7_archive_transition_append_only',
            'pbr_f7_validate_portability_transition_tenant',
            'pbr_f7_validate_portability_export_tenant',
            'pbr_f7_validate_archive_transition_tenant',
        ] as $function) {
            DB::unprepared(
                'DROP FUNCTION IF EXISTS '.$function.'()',
            );
        }
    }
};
