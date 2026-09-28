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

        Schema::create('import_batches', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('business_id');
            $table->uuid('created_by_membership_id');
            $table->string('source_type', 16);
            $table->string('source_system', 120);
            $table->string('source_filename', 255);
            $table->char('source_fingerprint', 64);
            $table->string('parser_identity', 120);
            $table->string('parser_version', 40);
            $table->string('schema_version', 80);
            $table->string('intended_target', 80);
            $table->text('source_payload');
            $table->string('status', 32)->default('staged');
            $table->timestampTz('staged_at');
            $table->timestampTz('parsed_at')->nullable();
            $table->timestampTz('validated_at')->nullable();
            $table->timestampTz('review_ready_at')->nullable();
            $table->timestampTz('completed_at')->nullable();
            $table->timestampsTz();

            $table->unique(
                ['id', 'business_id'],
                'import_batches_id_business_uq',
            );

            $table->index(
                ['business_id', 'created_by_membership_id', 'created_at'],
                'import_batches_requester_idx',
            );

            $table->index(
                [
                    'business_id',
                    'source_system',
                    'source_type',
                    'source_fingerprint',
                    'intended_target',
                ],
                'import_batches_provenance_idx',
            );

            $table->foreign('business_id')
                ->references('id')
                ->on('businesses')
                ->restrictOnDelete();

            $table->foreign('created_by_membership_id')
                ->references('id')
                ->on('memberships')
                ->restrictOnDelete();
        });

        Schema::create('imported_records', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('business_id');
            $table->uuid('import_batch_id');
            $table->string('source_record_key', 200);
            $table->string('intended_target', 80);
            $table->char('idempotency_key', 64);
            $table->jsonb('observed_payload');
            $table->jsonb('normalized_payload')->nullable();
            $table->string('status', 32)->default('observed');
            $table->string('canonical_resource_type', 100)->nullable();
            $table->uuid('canonical_resource_id')->nullable();
            $table->string('confirmation_error_code', 100)->nullable();
            $table->text('confirmation_error_message')->nullable();
            $table->timestampTz('confirmed_at')->nullable();
            $table->timestampsTz();

            $table->unique(
                ['id', 'business_id'],
                'imported_records_id_business_uq',
            );

            $table->index(
                ['business_id', 'import_batch_id', 'status'],
                'imported_records_batch_status_idx',
            );

            $table->index(
                ['business_id', 'idempotency_key', 'status'],
                'imported_records_idempotency_idx',
            );

            $table->foreign(
                ['import_batch_id', 'business_id'],
                'imported_records_batch_fk',
            )->references(['id', 'business_id'])
                ->on('import_batches')
                ->restrictOnDelete();
        });

        Schema::create(
            'import_validation_results',
            function (Blueprint $table): void {
                $table->uuid('id')->primary();
                $table->uuid('business_id');
                $table->uuid('imported_record_id');
                $table->string('severity', 16);
                $table->string('code', 100);
                $table->string('field', 120)->nullable();
                $table->text('message');
                $table->timestampTz('created_at')->useCurrent();

                $table->unique(
                    ['id', 'business_id'],
                    'import_validation_id_business_uq',
                );

                $table->index(
                    ['business_id', 'imported_record_id', 'severity'],
                    'import_validation_record_idx',
                );

                $table->foreign(
                    ['imported_record_id', 'business_id'],
                    'import_validation_record_fk',
                )->references(['id', 'business_id'])
                    ->on('imported_records')
                    ->restrictOnDelete();
            },
        );

        DB::unprepared(<<<'SQL'
ALTER TABLE import_batches
ADD CONSTRAINT import_batches_source_type_check
CHECK (source_type IN ('csv','json')),
ADD CONSTRAINT import_batches_target_check
CHECK (intended_target IN ('partner','formal_record_amendment')),
ADD CONSTRAINT import_batches_status_check
CHECK (status IN (
    'staged','parsed','review_ready','confirming',
    'completed','completed_with_errors','failed'
)),
ADD CONSTRAINT import_batches_fingerprint_check
CHECK (source_fingerprint ~ '^[a-f0-9]{64}$'),
ADD CONSTRAINT import_batches_source_size_check
CHECK (octet_length(source_payload) BETWEEN 1 AND 2097152);

ALTER TABLE imported_records
ADD CONSTRAINT imported_records_target_check
CHECK (intended_target IN ('partner','formal_record_amendment')),
ADD CONSTRAINT imported_records_status_check
CHECK (status IN (
    'observed','valid','invalid','conflict',
    'confirmed','confirmation_failed'
)),
ADD CONSTRAINT imported_records_idempotency_check
CHECK (idempotency_key ~ '^[a-f0-9]{64}$'),
ADD CONSTRAINT imported_records_observed_object_check
CHECK (jsonb_typeof(observed_payload) = 'object'),
ADD CONSTRAINT imported_records_normalized_object_check
CHECK (
    normalized_payload IS NULL
    OR jsonb_typeof(normalized_payload) = 'object'
),
ADD CONSTRAINT imported_records_confirmed_shape_check
CHECK (
    status <> 'confirmed'
    OR (
        canonical_resource_type IS NOT NULL
        AND canonical_resource_id IS NOT NULL
        AND confirmed_at IS NOT NULL
        AND confirmation_error_code IS NULL
        AND confirmation_error_message IS NULL
    )
);

ALTER TABLE import_validation_results
ADD CONSTRAINT import_validation_severity_check
CHECK (severity IN ('info','warning','error'));
SQL);

        DB::statement(<<<'SQL'
CREATE UNIQUE INDEX imported_records_confirmed_identity_uq
ON imported_records (business_id, idempotency_key)
WHERE status = 'confirmed'
SQL);

        $this->addTenantGuards();
        $this->addHistoryGuards();
    }

    private function addTenantGuards(): void
    {
        DB::unprepared(<<<'SQL'
CREATE OR REPLACE FUNCTION pbr_f7_validate_import_batch_tenant()
RETURNS trigger LANGUAGE plpgsql AS $$
DECLARE
    v_membership_business uuid;
BEGIN
    SELECT business_id
      INTO v_membership_business
      FROM memberships
     WHERE id = NEW.created_by_membership_id;

    IF v_membership_business IS NULL
       OR v_membership_business <> NEW.business_id
    THEN
        RAISE EXCEPTION
            'Import Batch requester Membership must belong to the same Business';
    END IF;

    RETURN NEW;
END;
$$;

CREATE TRIGGER import_batches_tenant_guard
BEFORE INSERT OR UPDATE ON import_batches
FOR EACH ROW EXECUTE FUNCTION pbr_f7_validate_import_batch_tenant();

CREATE OR REPLACE FUNCTION pbr_f7_validate_imported_record_tenant()
RETURNS trigger LANGUAGE plpgsql AS $$
DECLARE
    v_batch_business uuid;
BEGIN
    SELECT business_id
      INTO v_batch_business
      FROM import_batches
     WHERE id = NEW.import_batch_id;

    IF v_batch_business IS NULL
       OR v_batch_business <> NEW.business_id
    THEN
        RAISE EXCEPTION
            'Imported Record must belong to the same Business as its Import Batch';
    END IF;

    RETURN NEW;
END;
$$;

CREATE TRIGGER imported_records_tenant_guard
BEFORE INSERT OR UPDATE ON imported_records
FOR EACH ROW EXECUTE FUNCTION pbr_f7_validate_imported_record_tenant();

CREATE OR REPLACE FUNCTION pbr_f7_validate_import_result_tenant()
RETURNS trigger LANGUAGE plpgsql AS $$
DECLARE
    v_record_business uuid;
BEGIN
    SELECT business_id
      INTO v_record_business
      FROM imported_records
     WHERE id = NEW.imported_record_id;

    IF v_record_business IS NULL
       OR v_record_business <> NEW.business_id
    THEN
        RAISE EXCEPTION
            'Import Validation Result must belong to the same Business as its Imported Record';
    END IF;

    RETURN NEW;
END;
$$;

CREATE TRIGGER import_validation_results_tenant_guard
BEFORE INSERT ON import_validation_results
FOR EACH ROW EXECUTE FUNCTION pbr_f7_validate_import_result_tenant();
SQL);
    }

    private function addHistoryGuards(): void
    {
        DB::unprepared(<<<'SQL'
CREATE OR REPLACE FUNCTION pbr_f7_protect_import_batch()
RETURNS trigger LANGUAGE plpgsql AS $$
DECLARE
    v_valid_transition boolean := false;
BEGIN
    IF TG_OP = 'DELETE' THEN
        RAISE EXCEPTION 'Import Batch history cannot be deleted';
    END IF;

    IF NEW.business_id IS DISTINCT FROM OLD.business_id
       OR NEW.created_by_membership_id
            IS DISTINCT FROM OLD.created_by_membership_id
       OR NEW.source_type IS DISTINCT FROM OLD.source_type
       OR NEW.source_system IS DISTINCT FROM OLD.source_system
       OR NEW.source_filename IS DISTINCT FROM OLD.source_filename
       OR NEW.source_fingerprint IS DISTINCT FROM OLD.source_fingerprint
       OR NEW.parser_identity IS DISTINCT FROM OLD.parser_identity
       OR NEW.parser_version IS DISTINCT FROM OLD.parser_version
       OR NEW.schema_version IS DISTINCT FROM OLD.schema_version
       OR NEW.intended_target IS DISTINCT FROM OLD.intended_target
       OR NEW.source_payload IS DISTINCT FROM OLD.source_payload
       OR NEW.staged_at IS DISTINCT FROM OLD.staged_at
       OR NEW.created_at IS DISTINCT FROM OLD.created_at
    THEN
        RAISE EXCEPTION 'Import Batch provenance is immutable';
    END IF;

    IF OLD.status IN ('completed','completed_with_errors','failed') THEN
        RAISE EXCEPTION 'Terminal Import Batch is immutable';
    END IF;

    IF NEW.status IS DISTINCT FROM OLD.status THEN
        v_valid_transition := CASE OLD.status
            WHEN 'staged' THEN NEW.status IN ('parsed','failed')
            WHEN 'parsed' THEN NEW.status IN ('review_ready','failed')
            WHEN 'review_ready' THEN NEW.status IN ('confirming','failed')
            WHEN 'confirming' THEN NEW.status IN (
                'review_ready',
                'completed',
                'completed_with_errors',
                'failed'
            )
            ELSE false
        END;

        IF NOT v_valid_transition THEN
            RAISE EXCEPTION 'Invalid Import Batch status transition';
        END IF;
    END IF;

    RETURN NEW;
END;
$$;

CREATE TRIGGER import_batches_history_guard
BEFORE UPDATE OR DELETE ON import_batches
FOR EACH ROW EXECUTE FUNCTION pbr_f7_protect_import_batch();

CREATE OR REPLACE FUNCTION pbr_f7_protect_imported_record()
RETURNS trigger LANGUAGE plpgsql AS $$
DECLARE
    v_valid_transition boolean := false;
BEGIN
    IF TG_OP = 'DELETE' THEN
        RAISE EXCEPTION 'Imported Record history cannot be deleted';
    END IF;

    IF NEW.business_id IS DISTINCT FROM OLD.business_id
       OR NEW.import_batch_id IS DISTINCT FROM OLD.import_batch_id
       OR NEW.source_record_key IS DISTINCT FROM OLD.source_record_key
       OR NEW.intended_target IS DISTINCT FROM OLD.intended_target
       OR NEW.idempotency_key IS DISTINCT FROM OLD.idempotency_key
       OR NEW.observed_payload IS DISTINCT FROM OLD.observed_payload
       OR NEW.created_at IS DISTINCT FROM OLD.created_at
    THEN
        RAISE EXCEPTION 'Imported Record provenance is immutable';
    END IF;

    IF OLD.normalized_payload IS NOT NULL
       AND NEW.normalized_payload IS DISTINCT FROM OLD.normalized_payload
    THEN
        RAISE EXCEPTION 'Validated Imported Record payload is immutable';
    END IF;

    IF OLD.canonical_resource_type IS NOT NULL
       AND NEW.canonical_resource_type
            IS DISTINCT FROM OLD.canonical_resource_type
    THEN
        RAISE EXCEPTION 'Imported Record canonical result is immutable';
    END IF;

    IF OLD.canonical_resource_id IS NOT NULL
       AND NEW.canonical_resource_id
            IS DISTINCT FROM OLD.canonical_resource_id
    THEN
        RAISE EXCEPTION 'Imported Record canonical result is immutable';
    END IF;

    IF OLD.status IN ('confirmed','invalid','conflict') THEN
        RAISE EXCEPTION 'Terminal Imported Record is immutable';
    END IF;

    IF NEW.status IS DISTINCT FROM OLD.status THEN
        v_valid_transition := CASE OLD.status
            WHEN 'observed' THEN NEW.status IN (
                'valid','invalid','conflict'
            )
            WHEN 'valid' THEN NEW.status IN (
                'confirmed','confirmation_failed','conflict'
            )
            WHEN 'confirmation_failed' THEN NEW.status IN (
                'valid','conflict'
            )
            ELSE false
        END;

        IF NOT v_valid_transition THEN
            RAISE EXCEPTION 'Invalid Imported Record status transition';
        END IF;
    END IF;

    RETURN NEW;
END;
$$;

CREATE TRIGGER imported_records_history_guard
BEFORE UPDATE OR DELETE ON imported_records
FOR EACH ROW EXECUTE FUNCTION pbr_f7_protect_imported_record();

CREATE OR REPLACE FUNCTION pbr_f7_import_validation_append_only()
RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN
    RAISE EXCEPTION 'Import Validation Result history is append-only';
END;
$$;

CREATE TRIGGER import_validation_results_append_only
BEFORE UPDATE OR DELETE ON import_validation_results
FOR EACH ROW EXECUTE FUNCTION pbr_f7_import_validation_append_only();
SQL);
    }

    private function backfillCapabilities(): void
    {
        $profiles = DB::table('permission_profiles')
            ->whereIn('name', $this->managerProfiles)
            ->get(['id', 'business_id']);

        foreach (['import.view', 'import.manage'] as $capability) {
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
        Schema::dropIfExists('import_validation_results');
        Schema::dropIfExists('imported_records');
        Schema::dropIfExists('import_batches');

        foreach ([
            'pbr_f7_import_validation_append_only',
            'pbr_f7_protect_imported_record',
            'pbr_f7_protect_import_batch',
            'pbr_f7_validate_import_result_tenant',
            'pbr_f7_validate_imported_record_tenant',
            'pbr_f7_validate_import_batch_tenant',
        ] as $function) {
            DB::unprepared(
                'DROP FUNCTION IF EXISTS '.$function.'()',
            );
        }
    }
};
