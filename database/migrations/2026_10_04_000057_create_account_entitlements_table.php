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
        Schema::create('account_entitlements', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('user_id');
            $table->string('entitlement_key', 64);
            $table->uuid('pbr_access_code_id');
            $table->timestampTz('granted_at');

            $table->foreign('user_id')
                ->references('id')
                ->on('users')
                ->restrictOnDelete();

            $table->foreign('pbr_access_code_id')
                ->references('id')
                ->on('pbr_access_codes')
                ->restrictOnDelete();

            $table->unique(
                ['user_id', 'entitlement_key'],
                'account_entitlements_user_key_unique',
            );

            $table->unique(
                'pbr_access_code_id',
                'account_entitlements_access_code_unique',
            );
        });

        DB::statement(
            "ALTER TABLE account_entitlements
             ADD CONSTRAINT account_entitlements_key_check
             CHECK (entitlement_key IN ('business.create'))",
        );

        DB::unprepared(<<<'SQL'
CREATE OR REPLACE FUNCTION pbr_protect_account_entitlement_history()
RETURNS trigger
LANGUAGE plpgsql
AS $$
BEGIN
    RAISE EXCEPTION 'account entitlement history is immutable (% not allowed)', TG_OP;
END;
$$;

CREATE TRIGGER account_entitlements_prevent_update_delete
BEFORE UPDATE OR DELETE
ON account_entitlements
FOR EACH ROW
EXECUTE FUNCTION pbr_protect_account_entitlement_history();

CREATE TRIGGER account_entitlements_prevent_truncate
BEFORE TRUNCATE
ON account_entitlements
FOR EACH STATEMENT
EXECUTE FUNCTION pbr_protect_account_entitlement_history();
SQL);
    }

    public function down(): void
    {
        Schema::dropIfExists('account_entitlements');

        DB::unprepared(
            'DROP FUNCTION IF EXISTS pbr_protect_account_entitlement_history();',
        );
    }
};
