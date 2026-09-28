<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

return new class extends Migration
{
    /** @var array<string,list<string>> */
    private array $capabilityProfiles = [
        'reports.view' => [
            'Workspace Owner',
            'Partner',
            'Managing Partner / CEO',
            'Finance Owner',
            'Governance Secretary / PBR Administrator',
            'Advisor / Consultant',
            'Auditor / Viewer',
            'External Accountant / Legal Advisor',
        ],
        'reports.manage' => [
            'Workspace Owner',
            'Managing Partner / CEO',
            'Governance Secretary / PBR Administrator',
        ],
    ];

    public function up(): void
    {
        $this->backfillCapabilities();

        Schema::create(
            'business_pack_exports',
            function (Blueprint $table): void {
                $table->uuid('id')->primary();
                $table->uuid('business_id');
                $table->uuid('requested_by_membership_id');
                $table->string('output_language', 16);
                $table->jsonb('requested_scope');
                $table->timestampTz('as_of_at');
                $table->jsonb('frozen_manifest')->nullable();
                $table->char('manifest_hash', 64)->nullable();
                $table->jsonb('explicit_exclusions')->nullable();
                $table->string('status', 32)->default('requested');
                $table->text('storage_key')->nullable();
                $table->string('output_filename', 255)->nullable();
                $table->string('mime_type', 160)->nullable();
                $table->unsignedBigInteger('size_bytes')->nullable();
                $table->char('content_sha256', 64)->nullable();
                $table->timestampTz('generated_at')->nullable();
                $table->timestampTz('verified_at')->nullable();
                $table->timestampTz('available_at')->nullable();
                $table->timestampsTz();

                $table->unique(
                    ['id', 'business_id'],
                    'business_pack_exports_id_business_uq',
                );
                $table->index(
                    ['business_id', 'requested_by_membership_id', 'created_at'],
                    'business_pack_exports_requester_idx',
                );
                $table->index(
                    ['business_id', 'status', 'created_at'],
                    'business_pack_exports_status_idx',
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
            'business_pack_export_transitions',
            function (Blueprint $table): void {
                $table->uuid('id')->primary();
                $table->uuid('business_id');
                $table->uuid('business_pack_export_id');
                $table->string('from_status', 32)->nullable();
                $table->string('to_status', 32);
                $table->uuid('actor_membership_id')->nullable();
                $table->timestampTz('occurred_at');
                $table->timestampTz('created_at')->useCurrent();

                $table->unique(
                    ['id', 'business_id'],
                    'business_pack_transitions_id_business_uq',
                );
                $table->index(
                    ['business_id', 'business_pack_export_id', 'occurred_at'],
                    'business_pack_transitions_export_idx',
                );

                $table->foreign(
                    ['business_pack_export_id', 'business_id'],
                    'business_pack_transition_export_fk',
                )->references(['id', 'business_id'])
                    ->on('business_pack_exports')
                    ->restrictOnDelete();

                $table->foreign('actor_membership_id')
                    ->references('id')
                    ->on('memberships')
                    ->restrictOnDelete();
            },
        );

        $this->addChecks();
        $this->addGuards();
    }

    private function addChecks(): void
    {
        DB::unprepared(<<<'SQL'
ALTER TABLE business_pack_exports
ADD CONSTRAINT business_pack_exports_language_check
CHECK (output_language IN ('en','my','mixed')),
ADD CONSTRAINT business_pack_exports_status_check
CHECK (status IN (
    'requested','manifest_frozen','generating',
    'verifying','available','failed'
)),
ADD CONSTRAINT business_pack_exports_scope_array_check
CHECK (jsonb_typeof(requested_scope) = 'array'),
ADD CONSTRAINT business_pack_exports_manifest_object_check
CHECK (
    frozen_manifest IS NULL
    OR jsonb_typeof(frozen_manifest) = 'object'
),
ADD CONSTRAINT business_pack_exports_exclusions_array_check
CHECK (
    explicit_exclusions IS NULL
    OR jsonb_typeof(explicit_exclusions) = 'array'
);

ALTER TABLE business_pack_export_transitions
ADD CONSTRAINT business_pack_transition_from_check
CHECK (
    from_status IS NULL
    OR from_status IN (
        'requested','manifest_frozen','generating',
        'verifying','available','failed'
    )
),
ADD CONSTRAINT business_pack_transition_to_check
CHECK (to_status IN (
    'requested','manifest_frozen','generating',
    'verifying','available','failed'
));
SQL);
    }

    private function addGuards(): void
    {
        DB::unprepared(<<<'SQL'
CREATE OR REPLACE FUNCTION pbr_f7_validate_business_pack_tenant()
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
            'Business Pack requester Membership must belong to the same Business';
    END IF;

    RETURN NEW;
END;
$$;

CREATE TRIGGER business_pack_exports_tenant_guard
BEFORE INSERT OR UPDATE ON business_pack_exports
FOR EACH ROW EXECUTE FUNCTION pbr_f7_validate_business_pack_tenant();
SQL);

        DB::unprepared(<<<'SQL'
CREATE OR REPLACE FUNCTION pbr_f7_validate_business_pack_transition_tenant()
RETURNS trigger LANGUAGE plpgsql AS $$
DECLARE
    v_export_business uuid;
    v_actor_business uuid;
BEGIN
    SELECT business_id
      INTO v_export_business
      FROM business_pack_exports
     WHERE id = NEW.business_pack_export_id;

    IF v_export_business IS NULL
       OR v_export_business <> NEW.business_id
    THEN
        RAISE EXCEPTION
            'Business Pack transition must match export Business';
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
                'Business Pack transition actor must belong to the same Business';
        END IF;
    END IF;

    RETURN NEW;
END;
$$;

CREATE TRIGGER business_pack_transitions_tenant_guard
BEFORE INSERT ON business_pack_export_transitions
FOR EACH ROW EXECUTE FUNCTION pbr_f7_validate_business_pack_transition_tenant();
SQL);

        DB::unprepared(<<<'SQL'
CREATE OR REPLACE FUNCTION pbr_f7_business_pack_transition_append_only()
RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN
    RAISE EXCEPTION 'Business Pack transition history is append-only';
END;
$$;

CREATE TRIGGER business_pack_transitions_append_only
BEFORE UPDATE OR DELETE ON business_pack_export_transitions
FOR EACH ROW EXECUTE FUNCTION pbr_f7_business_pack_transition_append_only();
SQL);

        DB::unprepared(<<<'SQL'
CREATE OR REPLACE FUNCTION pbr_f7_protect_business_pack_export()
RETURNS trigger LANGUAGE plpgsql AS $$
DECLARE
    v_valid_transition boolean := false;
BEGIN
    IF TG_OP = 'DELETE' THEN
        RAISE EXCEPTION 'Business Pack export history cannot be deleted';
    END IF;

    IF NEW.business_id IS DISTINCT FROM OLD.business_id
       OR NEW.requested_by_membership_id
            IS DISTINCT FROM OLD.requested_by_membership_id
       OR NEW.output_language IS DISTINCT FROM OLD.output_language
       OR NEW.requested_scope IS DISTINCT FROM OLD.requested_scope
       OR NEW.as_of_at IS DISTINCT FROM OLD.as_of_at
       OR NEW.created_at IS DISTINCT FROM OLD.created_at
    THEN
        RAISE EXCEPTION 'Business Pack request identity is immutable';
    END IF;

    IF OLD.frozen_manifest IS NOT NULL
       AND NEW.frozen_manifest IS DISTINCT FROM OLD.frozen_manifest
    THEN
        RAISE EXCEPTION 'Frozen Business Pack manifest is immutable';
    END IF;

    IF OLD.manifest_hash IS NOT NULL
       AND NEW.manifest_hash IS DISTINCT FROM OLD.manifest_hash
    THEN
        RAISE EXCEPTION 'Business Pack manifest hash is immutable';
    END IF;

    IF OLD.explicit_exclusions IS NOT NULL
       AND NEW.explicit_exclusions
            IS DISTINCT FROM OLD.explicit_exclusions
    THEN
        RAISE EXCEPTION 'Business Pack exclusions are immutable once frozen';
    END IF;

    IF OLD.status = 'available' THEN
        RAISE EXCEPTION 'Available Business Pack export is immutable';
    END IF;

    IF OLD.status = 'failed' THEN
        RAISE EXCEPTION 'Failed Business Pack export is terminal';
    END IF;

    IF NEW.status IS DISTINCT FROM OLD.status THEN
        v_valid_transition := CASE OLD.status
            WHEN 'requested' THEN NEW.status IN (
                'manifest_frozen','failed'
            )
            WHEN 'manifest_frozen' THEN NEW.status IN (
                'generating','failed'
            )
            WHEN 'generating' THEN NEW.status IN (
                'verifying','failed'
            )
            WHEN 'verifying' THEN NEW.status IN (
                'available','failed'
            )
            ELSE false
        END;

        IF NOT v_valid_transition THEN
            RAISE EXCEPTION 'Invalid Business Pack status transition';
        END IF;
    END IF;

    IF NEW.status IN (
        'manifest_frozen','generating','verifying','available'
    ) THEN
        IF NEW.frozen_manifest IS NULL
           OR NEW.manifest_hash IS NULL
           OR NEW.explicit_exclusions IS NULL
        THEN
            RAISE EXCEPTION
                'Frozen Business Pack manifest, hash and exclusions are required';
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
                'Available Business Pack requires verified private output metadata';
        END IF;
    END IF;

    IF OLD.storage_key IS NOT NULL
       AND NEW.storage_key IS DISTINCT FROM OLD.storage_key
    THEN
        RAISE EXCEPTION 'Business Pack storage key is immutable once assigned';
    END IF;

    IF OLD.content_sha256 IS NOT NULL
       AND NEW.content_sha256 IS DISTINCT FROM OLD.content_sha256
    THEN
        RAISE EXCEPTION 'Business Pack output hash is immutable once assigned';
    END IF;

    RETURN NEW;
END;
$$;

CREATE TRIGGER business_pack_exports_history_guard
BEFORE UPDATE OR DELETE ON business_pack_exports
FOR EACH ROW EXECUTE FUNCTION pbr_f7_protect_business_pack_export();
SQL);
    }

    private function backfillCapabilities(): void
    {
        $profiles = DB::table('permission_profiles')
            ->whereIn(
                'name',
                array_values(
                    array_unique(
                        array_merge(...array_values($this->capabilityProfiles)),
                    ),
                ),
            )
            ->get(['id', 'business_id', 'name']);

        foreach ($this->capabilityProfiles as $capability => $profileNames) {
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

            foreach (
                $profiles->whereIn('name', $profileNames) as $profile
            ) {
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
        DB::statement(
            'DROP TRIGGER IF EXISTS business_pack_exports_history_guard '
            .'ON business_pack_exports',
        );
        DB::statement(
            'DROP TRIGGER IF EXISTS business_pack_exports_tenant_guard '
            .'ON business_pack_exports',
        );
        DB::statement(
            'DROP TRIGGER IF EXISTS business_pack_transitions_append_only '
            .'ON business_pack_export_transitions',
        );
        DB::statement(
            'DROP TRIGGER IF EXISTS business_pack_transitions_tenant_guard '
            .'ON business_pack_export_transitions',
        );

        foreach ([
            'pbr_f7_protect_business_pack_export',
            'pbr_f7_business_pack_transition_append_only',
            'pbr_f7_validate_business_pack_transition_tenant',
            'pbr_f7_validate_business_pack_tenant',
        ] as $function) {
            DB::unprepared(
                'DROP FUNCTION IF EXISTS '.$function.'()',
            );
        }

        Schema::dropIfExists('business_pack_export_transitions');
        Schema::dropIfExists('business_pack_exports');
    }
};
