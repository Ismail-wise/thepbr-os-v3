<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('document_versions', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('business_id');
            $table->uuid('document_id');
            $table->unsignedInteger('version_number');
            $table->string('original_filename', 255);
            $table->string('storage_key', 512);
            $table->unsignedBigInteger('size_bytes');
            $table->string('mime_type', 160);
            $table->char('content_sha256', 64);
            $table->uuid('uploaded_by_membership_id');
            $table->timestampTz('effective_from')->nullable();
            $table->uuid('supersedes_document_version_id')->nullable();
            $table->timestampTz('created_at')->useCurrent();

            $table
                ->foreign('business_id')
                ->references('id')
                ->on('businesses')
                ->restrictOnDelete();

            $table
                ->foreign(
                    ['document_id', 'business_id'],
                    'document_versions_document_business_fk',
                )
                ->references(['id', 'business_id'])
                ->on('documents')
                ->restrictOnDelete();

            $table
                ->foreign(
                    ['uploaded_by_membership_id', 'business_id'],
                    'document_versions_uploader_membership_business_fk',
                )
                ->references(['id', 'business_id'])
                ->on('memberships')
                ->restrictOnDelete();

            $table->unique(
                ['document_id', 'version_number'],
                'document_versions_document_version_unique',
            );

            $table->unique(
                'storage_key',
                'document_versions_storage_key_unique',
            );

            $table->unique(
                ['id', 'business_id'],
                'document_versions_id_business_id_unique',
            );

            $table->unique(
                ['id', 'document_id', 'business_id'],
                'document_versions_id_document_business_unique',
            );

            $table->index(
                ['business_id', 'document_id'],
                'document_versions_business_document_index',
            );
        });

        Schema::table('document_versions', function (Blueprint $table): void {
            $table
                ->foreign(
                    [
                        'supersedes_document_version_id',
                        'document_id',
                        'business_id',
                    ],
                    'document_versions_supersedes_same_document_fk',
                )
                ->references(['id', 'document_id', 'business_id'])
                ->on('document_versions')
                ->restrictOnDelete();
        });

        DB::statement(
            'ALTER TABLE document_versions
             ADD CONSTRAINT document_versions_version_number_check
             CHECK (version_number > 0)'
        );

        DB::statement(
            "ALTER TABLE document_versions
             ADD CONSTRAINT document_versions_original_filename_nonblank_check
             CHECK (btrim(original_filename) <> '')"
        );

        DB::statement(
            "ALTER TABLE document_versions
             ADD CONSTRAINT document_versions_storage_key_nonblank_check
             CHECK (btrim(storage_key) <> '')"
        );

        DB::statement(
            'ALTER TABLE document_versions
             ADD CONSTRAINT document_versions_size_bytes_check
             CHECK (size_bytes >= 0)'
        );

        DB::statement(
            "ALTER TABLE document_versions
             ADD CONSTRAINT document_versions_mime_type_nonblank_check
             CHECK (btrim(mime_type) <> '')"
        );

        DB::statement(
            "ALTER TABLE document_versions
             ADD CONSTRAINT document_versions_content_sha256_check
             CHECK (content_sha256 ~ '^[0-9a-f]{64}$')"
        );

        DB::unprepared(<<<'SQL'
CREATE OR REPLACE FUNCTION public.thepbr_prevent_document_version_mutation()
RETURNS trigger
LANGUAGE plpgsql
AS $$
BEGIN
    RAISE EXCEPTION 'document_versions are immutable';
END;
$$
SQL);

        DB::unprepared(<<<'SQL'
CREATE TRIGGER document_versions_prevent_update_delete
BEFORE UPDATE OR DELETE ON document_versions
FOR EACH ROW
EXECUTE FUNCTION public.thepbr_prevent_document_version_mutation()
SQL);
    }

    public function down(): void
    {
        Schema::dropIfExists('document_versions');

        DB::unprepared(
            'DROP FUNCTION IF EXISTS public.thepbr_prevent_document_version_mutation()'
        );
    }
};
