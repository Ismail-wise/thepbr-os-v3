<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('evidence', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('business_id');
            $table->uuid('document_version_id');
            $table->string('confidentiality', 32);
            $table->date('source_date');
            $table->uuid('submitted_by_membership_id');
            $table->timestampTz('verified_at')->nullable();
            $table->uuid('verified_by_membership_id')->nullable();
            $table->string('verification_method', 120)->nullable();
            $table->text('verification_note')->nullable();
            $table->timestampsTz();

            $table
                ->foreign('business_id')
                ->references('id')
                ->on('businesses')
                ->restrictOnDelete();

            $table
                ->foreign(
                    ['document_version_id', 'business_id'],
                    'evidence_document_version_business_fk',
                )
                ->references(['id', 'business_id'])
                ->on('document_versions')
                ->restrictOnDelete();

            $table
                ->foreign(
                    ['submitted_by_membership_id', 'business_id'],
                    'evidence_submitter_membership_business_fk',
                )
                ->references(['id', 'business_id'])
                ->on('memberships')
                ->restrictOnDelete();

            $table
                ->foreign(
                    ['verified_by_membership_id', 'business_id'],
                    'evidence_verifier_membership_business_fk',
                )
                ->references(['id', 'business_id'])
                ->on('memberships')
                ->restrictOnDelete();

            $table->unique(
                ['id', 'business_id'],
                'evidence_id_business_id_unique',
            );

            $table->index(
                ['business_id', 'document_version_id'],
                'evidence_business_document_version_index',
            );
        });

        DB::statement(
            "ALTER TABLE evidence
             ADD CONSTRAINT evidence_confidentiality_check
             CHECK (confidentiality IN ('standard', 'restricted'))"
        );

        DB::statement(
            "ALTER TABLE evidence
             ADD CONSTRAINT evidence_verification_provenance_check
             CHECK (
                 (
                     verified_at IS NULL
                     AND verified_by_membership_id IS NULL
                     AND verification_method IS NULL
                 )
                 OR
                 (
                     verified_at IS NOT NULL
                     AND verified_by_membership_id IS NOT NULL
                     AND verification_method IS NOT NULL
                     AND btrim(verification_method) <> ''
                 )
             )"
        );

        DB::unprepared(<<<'SQL'
CREATE OR REPLACE FUNCTION public.thepbr_protect_evidence_verified_provenance()
RETURNS trigger
LANGUAGE plpgsql
AS $$
BEGIN
    IF NEW.business_id IS DISTINCT FROM OLD.business_id THEN
        RAISE EXCEPTION 'evidence cannot move between businesses';
    END IF;

    IF OLD.verified_at IS NOT NULL
       AND (
           NEW.document_version_id IS DISTINCT FROM OLD.document_version_id
           OR NEW.source_date IS DISTINCT FROM OLD.source_date
           OR NEW.submitted_by_membership_id IS DISTINCT FROM OLD.submitted_by_membership_id
           OR NEW.verified_at IS DISTINCT FROM OLD.verified_at
           OR NEW.verified_by_membership_id IS DISTINCT FROM OLD.verified_by_membership_id
           OR NEW.verification_method IS DISTINCT FROM OLD.verification_method
           OR NEW.verification_note IS DISTINCT FROM OLD.verification_note
       )
    THEN
        RAISE EXCEPTION 'verified evidence provenance is immutable';
    END IF;

    RETURN NEW;
END;
$$
SQL);

        DB::unprepared(<<<'SQL'
CREATE TRIGGER evidence_protect_verified_provenance
BEFORE UPDATE ON evidence
FOR EACH ROW
EXECUTE FUNCTION public.thepbr_protect_evidence_verified_provenance()
SQL);
    }

    public function down(): void
    {
        Schema::dropIfExists('evidence');

        DB::unprepared(
            'DROP FUNCTION IF EXISTS public.thepbr_protect_evidence_verified_provenance()'
        );
    }
};
