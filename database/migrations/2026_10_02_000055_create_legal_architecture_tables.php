<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('legal_structure_versions', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('business_id');
            $table->uuid('formal_record_version_id');
            $table->string('legal_form', 120);
            $table->string('entity_name', 240)->nullable();
            $table->string('primary_jurisdiction_code', 24);
            $table->string('governing_law_reference', 240)->nullable();
            $table->text('registered_address')->nullable();
            $table->string('confidentiality', 24)->default('standard');
            $table->text('notes')->nullable();
            $table->uuid('created_by_membership_id');
            $table->timestampTz('created_at')->useCurrent();

            $table->unique(['id', 'business_id'], 'legal_structure_id_business_uq');
            $table->unique('formal_record_version_id', 'legal_structure_record_uq');
            $table->foreign('business_id')->references('id')->on('businesses')->restrictOnDelete();
            $table->foreign(['formal_record_version_id', 'business_id'], 'legal_structure_record_fk')
                ->references(['id', 'business_id'])->on('formal_record_versions')->restrictOnDelete();
            $table->foreign(['created_by_membership_id', 'business_id'], 'legal_structure_creator_fk')
                ->references(['id', 'business_id'])->on('memberships')->restrictOnDelete();
        });

        Schema::create('legal_jurisdiction_applicabilities', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('business_id');
            $table->uuid('formal_record_version_id');
            $table->string('jurisdiction_code', 24);
            $table->string('scope_type', 40);
            $table->string('scope_reference', 240)->nullable();
            $table->string('applicability', 24);
            $table->text('rationale')->nullable();
            $table->boolean('legal_review_required')->default(false);
            $table->timestampTz('created_at')->useCurrent();

            $table->unique(['id', 'business_id'], 'legal_jurisdiction_id_business_uq');
            $table->index(['business_id', 'formal_record_version_id'], 'legal_jurisdiction_record_idx');
            $table->foreign(['formal_record_version_id', 'business_id'], 'legal_jurisdiction_record_fk')
                ->references(['id', 'business_id'])->on('formal_record_versions')->restrictOnDelete();
        });

        Schema::create('legal_registrations', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('business_id');
            $table->uuid('formal_record_version_id');
            $table->string('registration_type', 120);
            $table->string('authority', 240);
            $table->string('reference_number', 240)->nullable();
            $table->string('jurisdiction_code', 24);
            $table->date('registration_date')->nullable();
            $table->string('status', 24);
            $table->text('evidence_reference')->nullable();
            $table->timestampTz('created_at')->useCurrent();

            $table->unique(['id', 'business_id'], 'legal_registration_id_business_uq');
            $table->index(['business_id', 'formal_record_version_id'], 'legal_registration_record_idx');
            $table->foreign(['formal_record_version_id', 'business_id'], 'legal_registration_record_fk')
                ->references(['id', 'business_id'])->on('formal_record_versions')->restrictOnDelete();
        });

        Schema::create('legal_license_permits', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('business_id');
            $table->uuid('formal_record_version_id');
            $table->string('name', 240);
            $table->string('authority', 240);
            $table->string('reference_number', 240)->nullable();
            $table->string('jurisdiction_code', 24);
            $table->date('start_date')->nullable();
            $table->date('expiry_date')->nullable();
            $table->date('review_date')->nullable();
            $table->string('status', 24);
            $table->text('evidence_reference')->nullable();
            $table->timestampTz('created_at')->useCurrent();

            $table->unique(['id', 'business_id'], 'legal_license_id_business_uq');
            $table->index(['business_id', 'formal_record_version_id'], 'legal_license_record_idx');
            $table->foreign(['formal_record_version_id', 'business_id'], 'legal_license_record_fk')
                ->references(['id', 'business_id'])->on('formal_record_versions')->restrictOnDelete();
        });

        Schema::create('legal_requirements', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('business_id');
            $table->uuid('formal_record_version_id');
            $table->string('requirement_key', 120);
            $table->string('title', 240);
            $table->string('category', 80);
            $table->text('description');
            $table->string('jurisdiction_code', 24);
            $table->string('source_authority', 240)->nullable();
            $table->date('applicable_from')->nullable();
            $table->date('applicable_until')->nullable();
            $table->string('status', 24);
            $table->boolean('legal_review_required')->default(false);
            $table->text('evidence_reference')->nullable();
            $table->timestampTz('created_at')->useCurrent();

            $table->unique(['id', 'business_id'], 'legal_requirement_id_business_uq');
            $table->unique(['formal_record_version_id', 'requirement_key'], 'legal_requirement_version_key_uq');
            $table->foreign(['formal_record_version_id', 'business_id'], 'legal_requirement_record_fk')
                ->references(['id', 'business_id'])->on('formal_record_versions')->restrictOnDelete();
        });

        Schema::create('legal_reviews', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('business_id');
            $table->uuid('formal_record_version_id');
            $table->uuid('legal_requirement_id')->nullable();
            $table->string('review_type', 80);
            $table->string('reviewer_name', 240);
            $table->string('reviewer_capacity', 160);
            $table->string('reviewer_organization', 240)->nullable();
            $table->date('review_date');
            $table->string('outcome', 24);
            $table->text('notes')->nullable();
            $table->text('evidence_reference')->nullable();
            $table->timestampTz('created_at')->useCurrent();

            $table->unique(['id', 'business_id'], 'legal_review_id_business_uq');
            $table->index(['business_id', 'formal_record_version_id'], 'legal_review_record_idx');
            $table->foreign(['formal_record_version_id', 'business_id'], 'legal_review_record_fk')
                ->references(['id', 'business_id'])->on('formal_record_versions')->restrictOnDelete();
            $table->foreign(['legal_requirement_id', 'business_id'], 'legal_review_requirement_fk')
                ->references(['id', 'business_id'])->on('legal_requirements')->restrictOnDelete();
        });

        DB::statement("ALTER TABLE legal_structure_versions
            ADD CONSTRAINT legal_structure_confidentiality_check
            CHECK (confidentiality IN ('standard','restricted'))");

        DB::statement("ALTER TABLE legal_jurisdiction_applicabilities
            ADD CONSTRAINT legal_jurisdiction_applicability_check
            CHECK (applicability IN ('applicable','not_applicable','needs_review'))");

        DB::statement("ALTER TABLE legal_jurisdiction_applicabilities
            ADD CONSTRAINT legal_jurisdiction_scope_check
            CHECK (scope_type IN ('entity','registration','license_permit','tax','employment','contract','data','other'))");

        DB::statement("ALTER TABLE legal_registrations
            ADD CONSTRAINT legal_registration_status_check
            CHECK (status IN ('planned','pending','active','suspended','expired','cancelled','closed'))");

        DB::statement("ALTER TABLE legal_license_permits
            ADD CONSTRAINT legal_license_status_check
            CHECK (status IN ('planned','pending','active','suspended','expired','cancelled','closed'))");

        DB::statement("ALTER TABLE legal_license_permits
            ADD CONSTRAINT legal_license_dates_check
            CHECK (expiry_date IS NULL OR start_date IS NULL OR expiry_date >= start_date)");

        DB::statement("ALTER TABLE legal_requirements
            ADD CONSTRAINT legal_requirement_status_check
            CHECK (status IN ('identified','met','warning','blocked','not_applicable'))");

        DB::statement("ALTER TABLE legal_requirements
            ADD CONSTRAINT legal_requirement_dates_check
            CHECK (applicable_until IS NULL OR applicable_from IS NULL OR applicable_until >= applicable_from)");

        DB::statement("ALTER TABLE legal_reviews
            ADD CONSTRAINT legal_review_outcome_check
            CHECK (outcome IN ('pending','passed','qualified','issues_found','rejected'))");

        DB::unprepared(<<<'SQL'
CREATE OR REPLACE FUNCTION pbr_legal_snapshot_mutable()
RETURNS trigger LANGUAGE plpgsql AS $$
DECLARE
    record_id uuid;
    frozen_at_value timestamptz;
BEGIN
    record_id := CASE
        WHEN TG_OP = 'DELETE' THEN OLD.formal_record_version_id
        ELSE NEW.formal_record_version_id
    END;

    SELECT frozen_at INTO frozen_at_value
      FROM formal_record_versions
     WHERE id = record_id;

    IF frozen_at_value IS NOT NULL THEN
        RAISE EXCEPTION 'Frozen Legal Architecture snapshot is immutable';
    END IF;

    RETURN CASE WHEN TG_OP = 'DELETE' THEN OLD ELSE NEW END;
END;
$$;
SQL);

        foreach ([
            'legal_structure_versions',
            'legal_jurisdiction_applicabilities',
            'legal_registrations',
            'legal_license_permits',
            'legal_requirements',
            'legal_reviews',
        ] as $table) {
            DB::statement(
                "CREATE TRIGGER {$table}_mutable
                 BEFORE INSERT OR UPDATE OR DELETE ON {$table}
                 FOR EACH ROW EXECUTE FUNCTION pbr_legal_snapshot_mutable()",
            );
        }
    }

    public function down(): void
    {
        foreach ([
            'legal_reviews',
            'legal_requirements',
            'legal_license_permits',
            'legal_registrations',
            'legal_jurisdiction_applicabilities',
            'legal_structure_versions',
        ] as $table) {
            DB::statement("DROP TRIGGER IF EXISTS {$table}_mutable ON {$table}");
        }

        Schema::dropIfExists('legal_reviews');
        Schema::dropIfExists('legal_requirements');
        Schema::dropIfExists('legal_license_permits');
        Schema::dropIfExists('legal_registrations');
        Schema::dropIfExists('legal_jurisdiction_applicabilities');
        Schema::dropIfExists('legal_structure_versions');

        DB::statement('DROP FUNCTION IF EXISTS pbr_legal_snapshot_mutable()');
    }
};