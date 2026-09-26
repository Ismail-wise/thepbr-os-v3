<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

return new class extends Migration
{
    /**
     * @var list<string>
     */
    private array $f5Capabilities = [
        'partners.view',
        'partners.manage',
        'due_diligence.view',
        'due_diligence.manage',
        'contributions.view',
        'contributions.manage',
    ];

    public function up(): void
    {
        $this->backfillF5Capabilities();

        Schema::create('partners', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('business_id');
            $table->string('display_name', 160);
            $table->string('legal_name', 200)->nullable();
            $table->string('email', 254)->nullable();
            $table->string('status', 24)->default('prospective');
            $table->text('notes')->nullable();
            $table->unsignedBigInteger('revision')->default(1);
            $table->timestampsTz();

            $table->unique(
                ['id', 'business_id'],
                'partners_id_business_unique',
            );

            $table->index(
                ['business_id', 'status'],
                'partners_business_status_index',
            );

            $table->foreign('business_id')
                ->references('id')
                ->on('businesses')
                ->restrictOnDelete();
        });

        Schema::create(
            'partner_membership_links',
            function (Blueprint $table): void {
                $table->uuid('id')->primary();
                $table->uuid('business_id');
                $table->uuid('partner_id');
                $table->uuid('membership_id');
                $table->uuid('linked_by_membership_id');
                $table->timestampTz('linked_at');

                $table->unique(
                    ['partner_id'],
                    'partner_membership_links_partner_unique',
                );

                $table->unique(
                    ['business_id', 'membership_id'],
                    'partner_membership_links_membership_unique',
                );

                $table->foreign(
                    ['partner_id', 'business_id'],
                    'partner_membership_links_partner_business_fk',
                )
                    ->references(['id', 'business_id'])
                    ->on('partners')
                    ->restrictOnDelete();

                $table->foreign(
                    ['membership_id', 'business_id'],
                    'partner_membership_links_membership_business_fk',
                )
                    ->references(['id', 'business_id'])
                    ->on('memberships')
                    ->restrictOnDelete();

                $table->foreign(
                    ['linked_by_membership_id', 'business_id'],
                    'partner_membership_links_linker_business_fk',
                )
                    ->references(['id', 'business_id'])
                    ->on('memberships')
                    ->restrictOnDelete();
            },
        );

        Schema::create(
            'partner_access_invitation_links',
            function (Blueprint $table): void {
                $table->uuid('id')->primary();
                $table->uuid('business_id');
                $table->uuid('partner_id');
                $table->uuid('business_access_invitation_id');
                $table->uuid('linked_by_membership_id');
                $table->timestampTz('created_at')->useCurrent();

                $table->unique(
                    ['business_access_invitation_id'],
                    'partner_invitation_links_invitation_unique',
                );

                $table->foreign(
                    ['partner_id', 'business_id'],
                    'partner_invitation_links_partner_business_fk',
                )
                    ->references(['id', 'business_id'])
                    ->on('partners')
                    ->restrictOnDelete();

                $table->foreign(
                    ['business_access_invitation_id', 'business_id'],
                    'partner_invitation_links_invitation_business_fk',
                )
                    ->references(['id', 'business_id'])
                    ->on('business_access_invitations')
                    ->restrictOnDelete();

                $table->foreign(
                    ['linked_by_membership_id', 'business_id'],
                    'partner_invitation_links_linker_business_fk',
                )
                    ->references(['id', 'business_id'])
                    ->on('memberships')
                    ->restrictOnDelete();
            },
        );

        Schema::create(
            'partner_due_diligence_cases',
            function (Blueprint $table): void {
                $table->uuid('id')->primary();
                $table->uuid('business_id');
                $table->uuid('partner_id');
                $table->string('status', 24)->default('draft');
                $table->string('risk_rating', 24)->nullable();

                $table->text('identity_legal_info')->nullable();
                $table->text('background_summary')->nullable();
                $table->text('business_experience')->nullable();
                $table->text('financial_capacity')->nullable();
                $table->text('reputation')->nullable();
                $table->text('existing_business_interests')->nullable();
                $table->text('conflict_of_interest')->nullable();
                $table->text('time_commitment')->nullable();
                $table->text('legal_regulatory_check')->nullable();
                $table->text('notes')->nullable();

                $table->uuid('reviewed_by_membership_id')->nullable();
                $table->timestampTz('reviewed_at')->nullable();

                $table->unsignedBigInteger('revision')->default(1);
                $table->timestampsTz();

                $table->unique(
                    ['id', 'business_id'],
                    'partner_dd_id_business_unique',
                );

                $table->index(
                    ['business_id', 'partner_id', 'created_at'],
                    'partner_dd_business_partner_index',
                );

                $table->foreign(
                    ['partner_id', 'business_id'],
                    'partner_dd_partner_business_fk',
                )
                    ->references(['id', 'business_id'])
                    ->on('partners')
                    ->restrictOnDelete();

                $table->foreign(
                    ['reviewed_by_membership_id', 'business_id'],
                    'partner_dd_reviewer_business_fk',
                )
                    ->references(['id', 'business_id'])
                    ->on('memberships')
                    ->restrictOnDelete();
            },
        );

        Schema::create(
            'partner_dynamics_assessment_references',
            function (Blueprint $table): void {
                $table->uuid('id')->primary();
                $table->uuid('business_id');
                $table->uuid('partner_id');
                $table->string('source_system', 80)
                    ->default('partner_dynamics');
                $table->string('source_assessment_id', 120);
                $table->string('source_url', 1000)->nullable();
                $table->string('assessment_version', 80);
                $table->string('primary_profile', 40);
                $table->string('secondary_profile', 40)->nullable();
                $table->timestampTz('completed_at');
                $table->uuid('referenced_by_membership_id');
                $table->timestampTz('created_at')->useCurrent();

                $table->unique(
                    ['id', 'business_id'],
                    'partner_dynamics_refs_id_business_unique',
                );

                $table->unique(
                    [
                        'business_id',
                        'source_system',
                        'source_assessment_id',
                    ],
                    'partner_dynamics_refs_source_unique',
                );

                $table->foreign(
                    ['partner_id', 'business_id'],
                    'partner_dynamics_refs_partner_business_fk',
                )
                    ->references(['id', 'business_id'])
                    ->on('partners')
                    ->restrictOnDelete();

                $table->foreign(
                    ['referenced_by_membership_id', 'business_id'],
                    'partner_dynamics_refs_member_business_fk',
                )
                    ->references(['id', 'business_id'])
                    ->on('memberships')
                    ->restrictOnDelete();
            },
        );

        DB::statement(
            "ALTER TABLE partners
             ADD CONSTRAINT partners_display_name_check
             CHECK (
                 btrim(display_name) <> ''
                 AND display_name = btrim(display_name)
             )",
        );

        DB::statement(
            "ALTER TABLE partners
             ADD CONSTRAINT partners_email_canonical_check
             CHECK (
                 email IS NULL
                 OR (
                     email = lower(btrim(email))
                     AND email <> ''
                 )
             )",
        );

        DB::statement(
            "ALTER TABLE partners
             ADD CONSTRAINT partners_status_check
             CHECK (status IN ('prospective','active','inactive'))",
        );

        DB::statement(
            'ALTER TABLE partners
             ADD CONSTRAINT partners_revision_positive
             CHECK (revision > 0)',
        );

        DB::statement(
            "ALTER TABLE partner_due_diligence_cases
             ADD CONSTRAINT partner_dd_status_check
             CHECK (
                 status IN (
                     'draft',
                     'in_review',
                     'completed',
                     'blocked'
                 )
             )",
        );

        DB::statement(
            "ALTER TABLE partner_due_diligence_cases
             ADD CONSTRAINT partner_dd_risk_rating_check
             CHECK (
                 risk_rating IS NULL
                 OR risk_rating IN (
                     'low',
                     'moderate',
                     'high',
                     'critical'
                 )
             )",
        );

        DB::statement(
            'ALTER TABLE partner_due_diligence_cases
             ADD CONSTRAINT partner_dd_revision_positive
             CHECK (revision > 0)',
        );

        DB::statement(
            "ALTER TABLE partner_due_diligence_cases
             ADD CONSTRAINT partner_dd_terminal_review_check
             CHECK (
                 (
                     status IN ('draft','in_review')
                     AND reviewed_by_membership_id IS NULL
                     AND reviewed_at IS NULL
                 )
                 OR
                 (
                     status IN ('completed','blocked')
                     AND risk_rating IS NOT NULL
                     AND reviewed_by_membership_id IS NOT NULL
                     AND reviewed_at IS NOT NULL
                 )
             )",
        );

        DB::statement(
            "ALTER TABLE partner_dynamics_assessment_references
             ADD CONSTRAINT partner_dynamics_refs_primary_check
             CHECK (
                 primary_profile IN (
                     'visionary',
                     'builder',
                     'connector',
                     'analyst',
                     'operator',
                     'guardian',
                     'negotiator',
                     'optimizer'
                 )
             )",
        );

        DB::statement(
            "ALTER TABLE partner_dynamics_assessment_references
             ADD CONSTRAINT partner_dynamics_refs_secondary_check
             CHECK (
                 secondary_profile IS NULL
                 OR secondary_profile IN (
                     'visionary',
                     'builder',
                     'connector',
                     'analyst',
                     'operator',
                     'guardian',
                     'negotiator',
                     'optimizer'
                 )
             )",
        );

        DB::unprepared(<<<'SQL'
CREATE OR REPLACE FUNCTION pbr_protect_partner_business_identity()
RETURNS trigger
LANGUAGE plpgsql
AS $$
BEGIN
    IF NEW.business_id IS DISTINCT FROM OLD.business_id THEN
        RAISE EXCEPTION 'Partner cannot move between Businesses';
    END IF;

    IF NEW.revision <= OLD.revision THEN
        RAISE EXCEPTION 'Partner revision must increase';
    END IF;

    RETURN NEW;
END;
$$;

CREATE TRIGGER partners_protect_business_identity
BEFORE UPDATE ON partners
FOR EACH ROW
EXECUTE FUNCTION pbr_protect_partner_business_identity();

CREATE OR REPLACE FUNCTION pbr_protect_partner_link_history()
RETURNS trigger
LANGUAGE plpgsql
AS $$
BEGIN
    RAISE EXCEPTION 'Partner identity/access link history is append-only';
END;
$$;

CREATE TRIGGER partner_membership_links_immutable
BEFORE UPDATE OR DELETE ON partner_membership_links
FOR EACH ROW
EXECUTE FUNCTION pbr_protect_partner_link_history();

CREATE TRIGGER partner_invitation_links_immutable
BEFORE UPDATE OR DELETE ON partner_access_invitation_links
FOR EACH ROW
EXECUTE FUNCTION pbr_protect_partner_link_history();

CREATE OR REPLACE FUNCTION pbr_protect_partner_dd_history()
RETURNS trigger
LANGUAGE plpgsql
AS $$
BEGIN
    IF TG_OP = 'DELETE' THEN
        IF OLD.status IN ('completed','blocked') THEN
            RAISE EXCEPTION 'Completed Due Diligence history is immutable';
        END IF;

        RETURN OLD;
    END IF;

    IF NEW.business_id IS DISTINCT FROM OLD.business_id
       OR NEW.partner_id IS DISTINCT FROM OLD.partner_id
    THEN
        RAISE EXCEPTION 'Due Diligence case identity cannot be rewritten';
    END IF;

    IF OLD.status IN ('completed','blocked') THEN
        RAISE EXCEPTION 'Completed Due Diligence history is immutable';
    END IF;

    IF NEW.revision <= OLD.revision THEN
        RAISE EXCEPTION 'Due Diligence revision must increase';
    END IF;

    RETURN NEW;
END;
$$;

CREATE TRIGGER partner_dd_protect_history
BEFORE UPDATE OR DELETE ON partner_due_diligence_cases
FOR EACH ROW
EXECUTE FUNCTION pbr_protect_partner_dd_history();

CREATE OR REPLACE FUNCTION pbr_protect_partner_dynamics_reference()
RETURNS trigger
LANGUAGE plpgsql
AS $$
BEGIN
    RAISE EXCEPTION 'PartnerDynamics assessment reference history is immutable';
END;
$$;

CREATE TRIGGER partner_dynamics_reference_immutable
BEFORE UPDATE OR DELETE ON partner_dynamics_assessment_references
FOR EACH ROW
EXECUTE FUNCTION pbr_protect_partner_dynamics_reference();
SQL);
    }

    public function down(): void
    {
        /*
         * Drop tables before shared trigger functions.
         *
         * PostgreSQL triggers on Partner, Partner links, Due Diligence and
         * PartnerDynamics references depend on these functions. Explicit
         * table teardown removes the triggers before the functions are
         * removed, without CASCADE.
         */
        Schema::dropIfExists('partner_dynamics_assessment_references');
        Schema::dropIfExists('partner_due_diligence_cases');
        Schema::dropIfExists('partner_access_invitation_links');
        Schema::dropIfExists('partner_membership_links');
        Schema::dropIfExists('partners');

        DB::unprepared(
            'DROP FUNCTION IF EXISTS pbr_protect_partner_dynamics_reference();',
        );

        DB::unprepared(
            'DROP FUNCTION IF EXISTS pbr_protect_partner_dd_history();',
        );

        DB::unprepared(
            'DROP FUNCTION IF EXISTS pbr_protect_partner_link_history();',
        );

        DB::unprepared(
            'DROP FUNCTION IF EXISTS pbr_protect_partner_business_identity();',
        );

        $permissionIds = DB::table('permissions')
            ->whereIn('key', $this->f5Capabilities)
            ->pluck('id');

        DB::table('permission_profile_permissions')
            ->whereIn('permission_id', $permissionIds)
            ->delete();

        DB::table('permissions')
            ->whereIn('id', $permissionIds)
            ->whereNotExists(function ($query): void {
                $query->selectRaw('1')
                    ->from('permission_grants')
                    ->whereColumn(
                        'permission_grants.permission_id',
                        'permissions.id',
                    );
            })
            ->whereNotExists(function ($query): void {
                $query->selectRaw('1')
                    ->from('access_policies')
                    ->whereColumn(
                        'access_policies.permission_id',
                        'permissions.id',
                    );
            })
            ->delete();
    }

    private function backfillF5Capabilities(): void
    {
        $now = now();
        $permissionIds = [];

        foreach ($this->f5Capabilities as $key) {
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
            'Workspace Owner' => $this->f5Capabilities,

            'Partner' => [
                'partners.view',
                'contributions.view',
            ],

            'Managing Partner / CEO' => $this->f5Capabilities,

            'Finance Owner' => [
                'partners.view',
                'contributions.view',
                'contributions.manage',
            ],

            'Governance Secretary / PBR Administrator' => $this->f5Capabilities,

            'Advisor / Consultant' => [
                'partners.view',
                'due_diligence.view',
                'contributions.view',
            ],

            'Auditor / Viewer' => [
                'partners.view',
                'contributions.view',
            ],

            'External Accountant / Legal Advisor' => [
                'partners.view',
                'due_diligence.view',
                'contributions.view',
            ],
        ];

        $profiles = DB::table('permission_profiles')
            ->whereIn('name', array_keys($matrix))
            ->get(['id', 'business_id', 'name']);

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
};
