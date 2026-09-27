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
    private array $conflictCapabilities = [
        'conflict.view',
        'conflict.manage',
    ];

    public function up(): void
    {
        $this->backfillConflictCapabilities();
        $this->createPolicyTables();
        $this->createCaseTables();
        $this->createResolutionTables();
        $this->createSettlementAndDeliveryTables();
        $this->addChecks();
        $this->addGuards();
    }

    private function createPolicyTables(): void
    {
        Schema::create('conflict_policy_versions', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('business_id');
            $table->uuid('formal_record_version_id');
            $table->uuid('governance_formal_record_version_id');
            $table->uuid('operations_formal_record_version_id');
            $table->uuid('conflict_owner_membership_id');
            $table->string('formal_decision_type', 160);
            $table->string('deadlock_decision_type', 160);
            $table->string('misconduct_decision_type', 160);
            $table->string('urgent_risk_decision_type', 160);
            $table->string('settlement_decision_type', 160);
            $table->string('review_frequency', 80);
            $table->text('notes')->nullable();
            $table->timestampTz('created_at')->useCurrent();

            $table->unique(['id', 'business_id'], 'conflict_policy_id_biz_uq');
            $table->unique('formal_record_version_id', 'conflict_policy_record_uq');
            $table->unique(
                ['formal_record_version_id', 'business_id'],
                'conflict_policy_record_biz_uq',
            );

            foreach ([
                ['formal_record_version_id', 'conflict_policy_record_fk'],
                ['governance_formal_record_version_id', 'conflict_policy_gov_record_fk'],
                ['operations_formal_record_version_id', 'conflict_policy_ops_record_fk'],
            ] as [$column, $name]) {
                $table->foreign([$column, 'business_id'], $name)
                    ->references(['id', 'business_id'])
                    ->on('formal_record_versions')
                    ->restrictOnDelete();
            }

            $table->foreign(
                ['conflict_owner_membership_id', 'business_id'],
                'conflict_policy_owner_fk',
            )->references(['id', 'business_id'])
                ->on('memberships')
                ->restrictOnDelete();
        });

        Schema::create('conflict_escalation_rules', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('business_id');
            $table->uuid('formal_record_version_id');
            $table->unsignedInteger('sequence');
            $table->string('step_key', 80);
            $table->text('entry_condition');
            $table->uuid('operations_role_id')->nullable();
            $table->unsignedSmallInteger('max_days')->nullable();
            $table->text('required_evidence')->nullable();
            $table->string('decision_type', 160)->nullable();
            $table->text('resolution_exit_condition');
            $table->string('next_step_key', 80)->nullable();
            $table->string('status', 16)->default('active');
            $table->timestampTz('created_at')->useCurrent();

            $table->unique(['id', 'business_id'], 'conflict_esc_rule_id_biz_uq');
            $table->unique(
                ['formal_record_version_id', 'sequence'],
                'conflict_esc_rule_seq_uq',
            );
            $table->unique(
                ['formal_record_version_id', 'step_key'],
                'conflict_esc_rule_step_uq',
            );

            $table->foreign(
                ['formal_record_version_id', 'business_id'],
                'conflict_esc_rule_policy_fk',
            )->references(['formal_record_version_id', 'business_id'])
                ->on('conflict_policy_versions')
                ->restrictOnDelete();

            $table->foreign(
                ['operations_role_id', 'business_id'],
                'conflict_esc_rule_role_fk',
            )->references(['id', 'business_id'])
                ->on('operations_roles')
                ->restrictOnDelete();
        });

        Schema::create('conflict_special_path_rules', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('business_id');
            $table->uuid('formal_record_version_id');
            $table->string('path_type', 24);
            $table->text('entry_condition');
            $table->text('procedure_summary');
            $table->uuid('operations_role_id')->nullable();
            $table->unsignedSmallInteger('review_deadline_days');
            $table->string('decision_type', 160);
            $table->text('external_handoff_rule')->nullable();
            $table->timestampTz('created_at')->useCurrent();

            $table->unique(['id', 'business_id'], 'conflict_special_id_biz_uq');
            $table->unique(
                ['formal_record_version_id', 'path_type'],
                'conflict_special_path_uq',
            );

            $table->foreign(
                ['formal_record_version_id', 'business_id'],
                'conflict_special_policy_fk',
            )->references(['formal_record_version_id', 'business_id'])
                ->on('conflict_policy_versions')
                ->restrictOnDelete();

            $table->foreign(
                ['operations_role_id', 'business_id'],
                'conflict_special_role_fk',
            )->references(['id', 'business_id'])
                ->on('operations_roles')
                ->restrictOnDelete();
        });
    }

    private function createCaseTables(): void
    {
        Schema::create('conflict_cases', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('business_id');
            $table->string('case_number', 64);
            $table->uuid('conflict_policy_formal_record_version_id');
            $table->timestampTz('raised_at');
            $table->uuid('raised_by_membership_id');
            $table->uuid('conflict_owner_membership_id');
            $table->string('conflict_type', 48);
            $table->text('description');
            $table->text('business_impact');
            $table->string('urgency', 16);
            $table->text('related_rule_reference')->nullable();
            $table->string('confidentiality', 16)->default('restricted');
            $table->string('stage', 32)->default('intake');
            $table->string('status', 24)->default('open');
            $table->timestampTz('review_due_at')->nullable();
            $table->timestampTz('resolved_at')->nullable();
            $table->string('resolution_source_type', 48)->nullable();
            $table->uuid('resolution_source_id')->nullable();
            $table->unsignedBigInteger('revision')->default(1);
            $table->timestampsTz();

            $table->unique(['id', 'business_id'], 'conflict_cases_id_biz_uq');
            $table->unique(['business_id', 'case_number'], 'conflict_cases_number_uq');

            $table->foreign(
                ['conflict_policy_formal_record_version_id', 'business_id'],
                'conflict_cases_policy_fk',
            )->references(['formal_record_version_id', 'business_id'])
                ->on('conflict_policy_versions')
                ->restrictOnDelete();

            foreach ([
                ['raised_by_membership_id', 'conflict_cases_raiser_fk'],
                ['conflict_owner_membership_id', 'conflict_cases_owner_fk'],
            ] as [$column, $name]) {
                $table->foreign([$column, 'business_id'], $name)
                    ->references(['id', 'business_id'])
                    ->on('memberships')
                    ->restrictOnDelete();
            }
        });

        Schema::create('conflict_case_participants', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('business_id');
            $table->uuid('conflict_case_id');
            $table->uuid('membership_id')->nullable();
            $table->string('external_reference', 240)->nullable();
            $table->string('participant_role', 32);
            $table->string('status', 16)->default('active');
            $table->timestampTz('added_at');
            $table->timestampTz('removed_at')->nullable();

            $table->unique(['id', 'business_id'], 'conflict_participant_id_biz_uq');

            $table->foreign(
                ['conflict_case_id', 'business_id'],
                'conflict_participant_case_fk',
            )->references(['id', 'business_id'])
                ->on('conflict_cases')
                ->restrictOnDelete();

            $table->foreign(
                ['membership_id', 'business_id'],
                'conflict_participant_member_fk',
            )->references(['id', 'business_id'])
                ->on('memberships')
                ->restrictOnDelete();
        });

        Schema::create('conflict_case_updates', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('business_id');
            $table->uuid('conflict_case_id');
            $table->unsignedBigInteger('sequence');
            $table->string('from_stage', 32);
            $table->string('to_stage', 32);
            $table->string('from_status', 24);
            $table->string('to_status', 24);
            $table->string('note_code', 120)->nullable();
            $table->uuid('actor_membership_id');
            $table->timestampTz('occurred_at');
            $table->timestampTz('created_at')->useCurrent();

            $table->unique(
                ['conflict_case_id', 'sequence'],
                'conflict_case_updates_seq_uq',
            );

            $table->foreign(
                ['conflict_case_id', 'business_id'],
                'conflict_case_updates_case_fk',
            )->references(['id', 'business_id'])
                ->on('conflict_cases')
                ->restrictOnDelete();

            $table->foreign(
                ['actor_membership_id', 'business_id'],
                'conflict_case_updates_actor_fk',
            )->references(['id', 'business_id'])
                ->on('memberships')
                ->restrictOnDelete();
        });

        Schema::create('conflict_direct_discussions', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('business_id');
            $table->uuid('conflict_case_id');
            $table->timestampTz('meeting_at');
            $table->text('issues_discussed');
            $table->text('party_position_summary');
            $table->text('proposed_solutions');
            $table->string('outcome', 40);
            $table->timestampTz('follow_up_at')->nullable();
            $table->uuid('recorded_by_membership_id');
            $table->timestampTz('created_at')->useCurrent();

            $table->unique(['id', 'business_id'], 'conflict_discussion_id_biz_uq');

            $table->foreign(
                ['conflict_case_id', 'business_id'],
                'conflict_discussion_case_fk',
            )->references(['id', 'business_id'])
                ->on('conflict_cases')
                ->restrictOnDelete();

            $table->foreign(
                ['recorded_by_membership_id', 'business_id'],
                'conflict_discussion_recorder_fk',
            )->references(['id', 'business_id'])
                ->on('memberships')
                ->restrictOnDelete();
        });

        Schema::create('conflict_direct_discussion_participants', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('business_id');
            $table->uuid('conflict_direct_discussion_id');
            $table->uuid('membership_id');
            $table->timestampTz('created_at')->useCurrent();

            $table->unique(
                ['conflict_direct_discussion_id', 'membership_id'],
                'conflict_discussion_member_uq',
            );

            $table->foreign(
                ['conflict_direct_discussion_id', 'business_id'],
                'conflict_discussion_member_discussion_fk',
            )->references(['id', 'business_id'])
                ->on('conflict_direct_discussions')
                ->restrictOnDelete();

            $table->foreign(
                ['membership_id', 'business_id'],
                'conflict_discussion_member_fk',
            )->references(['id', 'business_id'])
                ->on('memberships')
                ->restrictOnDelete();
        });
    }

    private function createResolutionTables(): void
    {
        Schema::create('conflict_mediations', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('business_id');
            $table->uuid('conflict_case_id');
            $table->string('mediator_type', 16);
            $table->uuid('mediator_membership_id')->nullable();
            $table->string('external_mediator_reference', 240)->nullable();
            $table->timestampTz('mediation_at');
            $table->text('neutrality_check');
            $table->text('summary')->nullable();
            $table->text('proposed_settlement')->nullable();
            $table->timestampTz('response_deadline')->nullable();
            $table->string('status', 16)->default('planned');
            $table->unsignedBigInteger('revision')->default(1);
            $table->uuid('created_by_membership_id');
            $table->timestampTz('completed_at')->nullable();
            $table->timestampsTz();

            $table->unique(['id', 'business_id'], 'conflict_mediation_id_biz_uq');

            $table->foreign(
                ['conflict_case_id', 'business_id'],
                'conflict_mediation_case_fk',
            )->references(['id', 'business_id'])
                ->on('conflict_cases')
                ->restrictOnDelete();

            foreach ([
                ['mediator_membership_id', 'conflict_mediation_mediator_fk'],
                ['created_by_membership_id', 'conflict_mediation_creator_fk'],
            ] as [$column, $name]) {
                $table->foreign([$column, 'business_id'], $name)
                    ->references(['id', 'business_id'])
                    ->on('memberships')
                    ->restrictOnDelete();
            }
        });

        Schema::create('conflict_mediation_responses', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('business_id');
            $table->uuid('conflict_mediation_id');
            $table->uuid('membership_id');
            $table->string('response', 24);
            $table->text('response_note')->nullable();
            $table->timestampTz('responded_at');
            $table->timestampTz('created_at')->useCurrent();

            $table->unique(
                ['conflict_mediation_id', 'membership_id'],
                'conflict_mediation_response_member_uq',
            );

            $table->foreign(
                ['conflict_mediation_id', 'business_id'],
                'conflict_mediation_response_fk',
            )->references(['id', 'business_id'])
                ->on('conflict_mediations')
                ->restrictOnDelete();

            $table->foreign(
                ['membership_id', 'business_id'],
                'conflict_mediation_response_member_fk',
            )->references(['id', 'business_id'])
                ->on('memberships')
                ->restrictOnDelete();
        });

        Schema::create('conflict_decision_submissions', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('business_id');
            $table->uuid('conflict_case_id');
            $table->unsignedBigInteger('case_revision');
            $table->char('package_hash', 64);
            $table->string('decision_type', 160);
            $table->text('proposed_decision_summary');
            $table->text('conditions')->nullable();
            $table->text('appeal_reference')->nullable();
            $table->uuid('proposal_version_id');
            $table->uuid('decision_id')->nullable();
            $table->uuid('created_by_membership_id');
            $table->timestampTz('created_at')->useCurrent();

            $table->unique(['id', 'business_id'], 'conflict_decision_sub_id_biz_uq');
            $table->unique('proposal_version_id', 'conflict_decision_sub_proposal_uq');

            $table->foreign(
                ['conflict_case_id', 'business_id'],
                'conflict_decision_sub_case_fk',
            )->references(['id', 'business_id'])
                ->on('conflict_cases')
                ->restrictOnDelete();

            $table->foreign(
                ['proposal_version_id', 'business_id'],
                'conflict_decision_sub_proposal_fk',
            )->references(['id', 'business_id'])
                ->on('proposal_versions')
                ->restrictOnDelete();

            $table->foreign(
                ['decision_id', 'business_id'],
                'conflict_decision_sub_decision_fk',
            )->references(['id', 'business_id'])
                ->on('decisions')
                ->restrictOnDelete();

            $table->foreign(
                ['created_by_membership_id', 'business_id'],
                'conflict_decision_sub_creator_fk',
            )->references(['id', 'business_id'])
                ->on('memberships')
                ->restrictOnDelete();
        });

        Schema::create('conflict_escalations', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('business_id');
            $table->uuid('conflict_case_id');
            $table->uuid('conflict_escalation_rule_id');
            $table->timestampTz('entered_at');
            $table->timestampTz('due_at')->nullable();
            $table->uuid('operations_role_id')->nullable();
            $table->string('status', 24)->default('active');
            $table->string('outcome', 48)->nullable();
            $table->uuid('decision_submission_id')->nullable();
            $table->timestampTz('created_at')->useCurrent();

            $table->unique(['id', 'business_id'], 'conflict_escalation_id_biz_uq');

            $table->foreign(
                ['conflict_case_id', 'business_id'],
                'conflict_escalation_case_fk',
            )->references(['id', 'business_id'])
                ->on('conflict_cases')
                ->restrictOnDelete();

            $table->foreign(
                ['conflict_escalation_rule_id', 'business_id'],
                'conflict_escalation_rule_fk',
            )->references(['id', 'business_id'])
                ->on('conflict_escalation_rules')
                ->restrictOnDelete();

            $table->foreign(
                ['operations_role_id', 'business_id'],
                'conflict_escalation_role_fk',
            )->references(['id', 'business_id'])
                ->on('operations_roles')
                ->restrictOnDelete();

            $table->foreign(
                ['decision_submission_id', 'business_id'],
                'conflict_escalation_decision_sub_fk',
            )->references(['id', 'business_id'])
                ->on('conflict_decision_submissions')
                ->restrictOnDelete();
        });

        Schema::create('conflict_deadlock_records', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('business_id');
            $table->uuid('conflict_case_id');
            $table->unsignedSmallInteger('failed_vote_count')->default(0);
            $table->timestampTz('cooling_off_until')->nullable();
            $table->string('neutral_reference', 240)->nullable();
            $table->uuid('decision_id')->nullable();
            $table->string('status', 24)->default('open');
            $table->unsignedBigInteger('revision')->default(1);
            $table->text('outcome')->nullable();
            $table->uuid('created_by_membership_id');
            $table->timestampTz('created_at')->useCurrent();

            $table->unique(['id', 'business_id'], 'conflict_deadlock_id_biz_uq');
            $table->unique('conflict_case_id', 'conflict_deadlock_case_uq');

            $table->foreign(
                ['conflict_case_id', 'business_id'],
                'conflict_deadlock_case_fk',
            )->references(['id', 'business_id'])
                ->on('conflict_cases')
                ->restrictOnDelete();

            $table->foreign(
                ['decision_id', 'business_id'],
                'conflict_deadlock_decision_fk',
            )->references(['id', 'business_id'])
                ->on('decisions')
                ->restrictOnDelete();

            $table->foreign(
                ['created_by_membership_id', 'business_id'],
                'conflict_deadlock_creator_fk',
            )->references(['id', 'business_id'])
                ->on('memberships')
                ->restrictOnDelete();
        });

        Schema::create('conflict_investigations', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('business_id');
            $table->uuid('conflict_case_id');
            $table->text('allegation');
            $table->uuid('investigation_owner_membership_id');
            $table->text('temporary_restriction_proposal')->nullable();
            $table->string('status', 24)->default('open');
            $table->unsignedBigInteger('revision')->default(1);
            $table->timestampTz('opened_at');
            $table->timestampTz('completed_at')->nullable();
            $table->uuid('created_by_membership_id');
            $table->timestampTz('created_at')->useCurrent();

            $table->unique(['id', 'business_id'], 'conflict_investigation_id_biz_uq');

            $table->foreign(
                ['conflict_case_id', 'business_id'],
                'conflict_investigation_case_fk',
            )->references(['id', 'business_id'])
                ->on('conflict_cases')
                ->restrictOnDelete();

            foreach ([
                ['investigation_owner_membership_id', 'conflict_investigation_owner_fk'],
                ['created_by_membership_id', 'conflict_investigation_creator_fk'],
            ] as [$column, $name]) {
                $table->foreign([$column, 'business_id'], $name)
                    ->references(['id', 'business_id'])
                    ->on('memberships')
                    ->restrictOnDelete();
            }
        });

        Schema::create('conflict_investigation_findings', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('business_id');
            $table->uuid('conflict_investigation_id');
            $table->text('finding');
            $table->text('sanction_remedy_recommendation')->nullable();
            $table->text('appeal_reference')->nullable();
            $table->boolean('exit_trigger_recommended')->default(false);
            $table->uuid('recorded_by_membership_id');
            $table->timestampTz('recorded_at');
            $table->timestampTz('created_at')->useCurrent();

            $table->foreign(
                ['conflict_investigation_id', 'business_id'],
                'conflict_finding_investigation_fk',
            )->references(['id', 'business_id'])
                ->on('conflict_investigations')
                ->restrictOnDelete();

            $table->foreign(
                ['recorded_by_membership_id', 'business_id'],
                'conflict_finding_recorder_fk',
            )->references(['id', 'business_id'])
                ->on('memberships')
                ->restrictOnDelete();
        });

        Schema::create('conflict_urgent_risk_records', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('business_id');
            $table->uuid('conflict_case_id');
            $table->text('urgent_risk');
            $table->text('immediate_action');
            $table->text('informed_parties');
            $table->timestampTz('review_deadline');
            $table->uuid('emergency_authority_grant_id')->nullable();
            $table->timestampTz('authority_starts_at')->nullable();
            $table->timestampTz('authority_expires_at')->nullable();
            $table->uuid('final_decision_id')->nullable();
            $table->string('status', 24)->default('open');
            $table->unsignedBigInteger('revision')->default(1);
            $table->uuid('created_by_membership_id');
            $table->timestampTz('completed_at')->nullable();
            $table->timestampTz('created_at')->useCurrent();

            $table->unique(['id', 'business_id'], 'conflict_urgent_id_biz_uq');

            $table->foreign(
                ['conflict_case_id', 'business_id'],
                'conflict_urgent_case_fk',
            )->references(['id', 'business_id'])
                ->on('conflict_cases')
                ->restrictOnDelete();

            $table->foreign(
                ['emergency_authority_grant_id', 'business_id'],
                'conflict_urgent_grant_fk',
            )->references(['id', 'business_id'])
                ->on('emergency_authority_grants')
                ->restrictOnDelete();

            $table->foreign(
                ['final_decision_id', 'business_id'],
                'conflict_urgent_decision_fk',
            )->references(['id', 'business_id'])
                ->on('decisions')
                ->restrictOnDelete();

            $table->foreign(
                ['created_by_membership_id', 'business_id'],
                'conflict_urgent_creator_fk',
            )->references(['id', 'business_id'])
                ->on('memberships')
                ->restrictOnDelete();
        });
    }

    private function createSettlementAndDeliveryTables(): void
    {
        Schema::create('conflict_settlement_versions', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('business_id');
            $table->uuid('formal_record_version_id');
            $table->uuid('conflict_case_id');
            $table->uuid('source_mediation_id')->nullable();
            $table->uuid('source_decision_id')->nullable();
            $table->text('settlement_terms');
            $table->text('required_actions_summary')->nullable();
            $table->uuid('responsible_owner_membership_id');
            $table->timestampTz('due_at')->nullable();
            $table->bigInteger('financial_settlement_minor_units')->nullable();
            $table->string('currency', 3)->nullable();
            $table->text('confidentiality_terms')->nullable();
            $table->text('future_conduct_terms')->nullable();
            $table->date('review_date')->nullable();
            $table->string('settlement_decision_type', 160);
            $table->timestampTz('created_at')->useCurrent();

            $table->unique(['id', 'business_id'], 'conflict_settlement_id_biz_uq');
            $table->unique('formal_record_version_id', 'conflict_settlement_record_uq');
            $table->unique(
                ['formal_record_version_id', 'business_id'],
                'conflict_settlement_record_biz_uq',
            );

            $table->foreign(
                ['formal_record_version_id', 'business_id'],
                'conflict_settlement_record_fk',
            )->references(['id', 'business_id'])
                ->on('formal_record_versions')
                ->restrictOnDelete();

            $table->foreign(
                ['conflict_case_id', 'business_id'],
                'conflict_settlement_case_fk',
            )->references(['id', 'business_id'])
                ->on('conflict_cases')
                ->restrictOnDelete();

            $table->foreign(
                ['source_mediation_id', 'business_id'],
                'conflict_settlement_mediation_fk',
            )->references(['id', 'business_id'])
                ->on('conflict_mediations')
                ->restrictOnDelete();

            $table->foreign(
                ['source_decision_id', 'business_id'],
                'conflict_settlement_decision_fk',
            )->references(['id', 'business_id'])
                ->on('decisions')
                ->restrictOnDelete();

            $table->foreign(
                ['responsible_owner_membership_id', 'business_id'],
                'conflict_settlement_owner_fk',
            )->references(['id', 'business_id'])
                ->on('memberships')
                ->restrictOnDelete();
        });

        Schema::create('conflict_settlement_document_bindings', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('business_id');
            $table->uuid('formal_record_version_id');
            $table->uuid('document_version_id');
            $table->char('captured_settlement_content_hash', 64);
            $table->uuid('bound_by_membership_id');
            $table->timestampTz('bound_at');

            $table->unique(
                'formal_record_version_id',
                'conflict_settlement_doc_record_uq',
            );

            $table->foreign(
                ['formal_record_version_id', 'business_id'],
                'conflict_settlement_doc_settlement_fk',
            )->references(['formal_record_version_id', 'business_id'])
                ->on('conflict_settlement_versions')
                ->restrictOnDelete();

            $table->foreign(
                ['document_version_id', 'business_id'],
                'conflict_settlement_doc_document_fk',
            )->references(['id', 'business_id'])
                ->on('document_versions')
                ->restrictOnDelete();

            $table->foreign(
                ['bound_by_membership_id', 'business_id'],
                'conflict_settlement_doc_binder_fk',
            )->references(['id', 'business_id'])
                ->on('memberships')
                ->restrictOnDelete();
        });

        Schema::create('conflict_case_settlement_links', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('business_id');
            $table->uuid('conflict_case_id');
            $table->uuid('settlement_formal_record_version_id');
            $table->uuid('decision_id');
            $table->timestampTz('linked_at');

            $table->unique(
                ['conflict_case_id', 'settlement_formal_record_version_id'],
                'conflict_case_settlement_link_uq',
            );

            $table->foreign(
                ['conflict_case_id', 'business_id'],
                'conflict_case_settlement_case_fk',
            )->references(['id', 'business_id'])
                ->on('conflict_cases')
                ->restrictOnDelete();

            $table->foreign(
                ['settlement_formal_record_version_id', 'business_id'],
                'conflict_case_settlement_record_fk',
            )->references(['formal_record_version_id', 'business_id'])
                ->on('conflict_settlement_versions')
                ->restrictOnDelete();

            $table->foreign(
                ['decision_id', 'business_id'],
                'conflict_case_settlement_decision_fk',
            )->references(['id', 'business_id'])
                ->on('decisions')
                ->restrictOnDelete();
        });

        Schema::create('conflict_exit_legal_referrals', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('business_id');
            $table->uuid('conflict_case_id');
            $table->string('referral_type', 32);
            $table->text('trigger_reason');
            $table->string('external_reference', 240)->nullable();
            $table->string('status', 24)->default('referred');
            $table->uuid('referred_by_membership_id');
            $table->timestampTz('referred_at');
            $table->timestampTz('completed_at')->nullable();
            $table->timestampTz('created_at')->useCurrent();

            $table->foreign(
                ['conflict_case_id', 'business_id'],
                'conflict_referral_case_fk',
            )->references(['id', 'business_id'])
                ->on('conflict_cases')
                ->restrictOnDelete();

            $table->foreign(
                ['referred_by_membership_id', 'business_id'],
                'conflict_referral_actor_fk',
            )->references(['id', 'business_id'])
                ->on('memberships')
                ->restrictOnDelete();
        });

        Schema::create('conflict_action_links', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('business_id');
            $table->uuid('conflict_case_id');
            $table->uuid('action_id');
            $table->uuid('operations_formal_record_version_id');
            $table->uuid('operations_role_id');
            $table->string('source_type', 48);
            $table->uuid('source_id');
            $table->timestampTz('created_at')->useCurrent();

            $table->unique('action_id', 'conflict_action_link_action_uq');

            $table->foreign(
                ['conflict_case_id', 'business_id'],
                'conflict_action_link_case_fk',
            )->references(['id', 'business_id'])
                ->on('conflict_cases')
                ->restrictOnDelete();

            $table->foreign(
                ['action_id', 'business_id'],
                'conflict_action_link_action_fk',
            )->references(['id', 'business_id'])
                ->on('actions')
                ->restrictOnDelete();

            $table->foreign(
                ['operations_formal_record_version_id', 'business_id'],
                'conflict_action_link_ops_record_fk',
            )->references(['id', 'business_id'])
                ->on('formal_record_versions')
                ->restrictOnDelete();

            $table->foreign(
                ['operations_role_id', 'business_id'],
                'conflict_action_link_role_fk',
            )->references(['id', 'business_id'])
                ->on('operations_roles')
                ->restrictOnDelete();
        });

        Schema::create('conflict_case_reviews', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('business_id');
            $table->uuid('conflict_case_id');
            $table->uuid('reviewer_membership_id');
            $table->uuid('created_by_membership_id');
            $table->timestampTz('due_at')->nullable();
            $table->string('status', 16)->default('open');
            $table->string('outcome', 24)->nullable();
            $table->text('notes')->nullable();
            $table->timestampTz('resolved_at')->nullable();
            $table->unsignedBigInteger('revision')->default(1);
            $table->timestampsTz();

            $table->unique(['id', 'business_id'], 'conflict_review_id_biz_uq');

            $table->foreign(
                ['conflict_case_id', 'business_id'],
                'conflict_review_case_fk',
            )->references(['id', 'business_id'])
                ->on('conflict_cases')
                ->restrictOnDelete();

            foreach ([
                ['reviewer_membership_id', 'conflict_review_reviewer_fk'],
                ['created_by_membership_id', 'conflict_review_creator_fk'],
            ] as [$column, $name]) {
                $table->foreign([$column, 'business_id'], $name)
                    ->references(['id', 'business_id'])
                    ->on('memberships')
                    ->restrictOnDelete();
            }
        });
    }

    private function addChecks(): void
    {
        DB::statement('ALTER TABLE conflict_escalation_rules
            ADD CONSTRAINT conflict_esc_seq_ck CHECK (sequence > 0)');
        DB::statement("ALTER TABLE conflict_escalation_rules
            ADD CONSTRAINT conflict_esc_status_ck
            CHECK (status IN ('active','inactive'))");
        DB::statement("ALTER TABLE conflict_special_path_rules
            ADD CONSTRAINT conflict_special_type_ck
            CHECK (path_type IN ('deadlock','misconduct','urgent_risk'))");
        DB::statement('ALTER TABLE conflict_special_path_rules
            ADD CONSTRAINT conflict_special_deadline_ck CHECK (review_deadline_days > 0)');

        DB::statement("ALTER TABLE conflict_cases
            ADD CONSTRAINT conflict_cases_type_ck
            CHECK (conflict_type IN (
                'ordinary_disagreement','governance_dispute','financial_dispute',
                'role_performance','conflict_of_interest','misconduct',
                'agreement_breach','urgent_risk','deadlock_50_50',
                'relationship_breakdown','other'
            ))");
        DB::statement("ALTER TABLE conflict_cases
            ADD CONSTRAINT conflict_cases_urgency_ck
            CHECK (urgency IN ('low','normal','high','critical'))");
        DB::statement("ALTER TABLE conflict_cases
            ADD CONSTRAINT conflict_cases_confidentiality_ck
            CHECK (confidentiality = 'restricted')");
        DB::statement("ALTER TABLE conflict_cases
            ADD CONSTRAINT conflict_cases_stage_ck
            CHECK (stage IN (
                'intake','direct_discussion','mediation','formal_decision',
                'escalation','deadlock','misconduct_investigation','urgent_risk',
                'settlement','exit_legal','resolved'
            ))");
        DB::statement("ALTER TABLE conflict_cases
            ADD CONSTRAINT conflict_cases_status_ck
            CHECK (status IN ('open','in_progress','resolved','closed'))");
        DB::statement('ALTER TABLE conflict_cases
            ADD CONSTRAINT conflict_cases_revision_ck CHECK (revision > 0)');
        DB::statement('ALTER TABLE conflict_cases
            ADD CONSTRAINT conflict_cases_resolution_pair_ck
            CHECK (
                (resolution_source_type IS NULL AND resolution_source_id IS NULL)
                OR (resolution_source_type IS NOT NULL AND resolution_source_id IS NOT NULL)
            )');

        DB::statement("ALTER TABLE conflict_case_participants
            ADD CONSTRAINT conflict_participant_role_ck
            CHECK (participant_role IN (
                'party','conflict_owner','mediator','investigator',
                'advisor','legal','observer'
            ))");
        DB::statement("ALTER TABLE conflict_case_participants
            ADD CONSTRAINT conflict_participant_status_ck
            CHECK (status IN ('active','removed'))");
        DB::statement("ALTER TABLE conflict_case_participants
            ADD CONSTRAINT conflict_participant_identity_ck
            CHECK (
                (membership_id IS NOT NULL AND external_reference IS NULL)
                OR (membership_id IS NULL AND btrim(COALESCE(external_reference, '')) <> '')
            )");
        DB::statement('ALTER TABLE conflict_case_updates
            ADD CONSTRAINT conflict_case_updates_seq_ck CHECK (sequence > 0)');

        DB::statement("ALTER TABLE conflict_direct_discussions
            ADD CONSTRAINT conflict_discussion_outcome_ck
            CHECK (outcome IN (
                'resolved','continue_mediation','continue_formal_decision'
            ))");

        DB::statement("ALTER TABLE conflict_mediations
            ADD CONSTRAINT conflict_mediation_type_ck
            CHECK (mediator_type IN ('internal','external'))");
        DB::statement("ALTER TABLE conflict_mediations
            ADD CONSTRAINT conflict_mediation_identity_ck
            CHECK (
                (mediator_type = 'internal' AND mediator_membership_id IS NOT NULL
                    AND external_mediator_reference IS NULL)
                OR
                (mediator_type = 'external' AND mediator_membership_id IS NULL
                    AND btrim(COALESCE(external_mediator_reference, '')) <> '')
            )");
        DB::statement("ALTER TABLE conflict_mediations
            ADD CONSTRAINT conflict_mediation_status_ck
            CHECK (status IN ('planned','completed','cancelled'))");
        DB::statement('ALTER TABLE conflict_mediations
            ADD CONSTRAINT conflict_mediation_revision_ck CHECK (revision > 0)');
        DB::statement("ALTER TABLE conflict_mediation_responses
            ADD CONSTRAINT conflict_mediation_response_ck
            CHECK (response IN ('accepted','rejected','needs_changes'))");

        DB::statement('ALTER TABLE conflict_decision_submissions
            ADD CONSTRAINT conflict_decision_revision_ck CHECK (case_revision > 0)');
        DB::statement("ALTER TABLE conflict_decision_submissions
            ADD CONSTRAINT conflict_decision_hash_ck
            CHECK (package_hash ~ '^[0-9a-f]{64}$')");

        DB::statement("ALTER TABLE conflict_escalations
            ADD CONSTRAINT conflict_escalation_status_ck
            CHECK (status IN ('active','resolved','referred','cancelled'))");
        DB::statement("ALTER TABLE conflict_deadlock_records
            ADD CONSTRAINT conflict_deadlock_status_ck
            CHECK (status IN (
                'open','cooling_off','mediation','decision','resolved','referred'
            ))");
        DB::statement('ALTER TABLE conflict_deadlock_records
            ADD CONSTRAINT conflict_deadlock_revision_ck CHECK (revision > 0)');

        DB::statement("ALTER TABLE conflict_investigations
            ADD CONSTRAINT conflict_investigation_status_ck
            CHECK (status IN (
                'open','investigating','finding_recorded','resolved','referred'
            ))");
        DB::statement('ALTER TABLE conflict_investigations
            ADD CONSTRAINT conflict_investigation_revision_ck CHECK (revision > 0)');

        DB::statement("ALTER TABLE conflict_urgent_risk_records
            ADD CONSTRAINT conflict_urgent_status_ck
            CHECK (status IN ('open','active','review_due','resolved','referred'))");
        DB::statement('ALTER TABLE conflict_urgent_risk_records
            ADD CONSTRAINT conflict_urgent_revision_ck CHECK (revision > 0)');
        DB::statement('ALTER TABLE conflict_urgent_risk_records
            ADD CONSTRAINT conflict_urgent_authority_pair_ck
            CHECK (
                (emergency_authority_grant_id IS NULL
                    AND authority_starts_at IS NULL
                    AND authority_expires_at IS NULL)
                OR
                (emergency_authority_grant_id IS NOT NULL
                    AND authority_starts_at IS NOT NULL
                    AND authority_expires_at IS NOT NULL
                    AND authority_expires_at > authority_starts_at)
            )');

        DB::statement("ALTER TABLE conflict_settlement_versions
            ADD CONSTRAINT conflict_settlement_money_ck
            CHECK (
                (financial_settlement_minor_units IS NULL AND currency IS NULL)
                OR
                (financial_settlement_minor_units IS NOT NULL
                    AND financial_settlement_minor_units >= 0
                    AND currency ~ '^[A-Z]{3}$')
            )");
        DB::statement("ALTER TABLE conflict_settlement_document_bindings
            ADD CONSTRAINT conflict_settlement_doc_hash_ck
            CHECK (captured_settlement_content_hash ~ '^[0-9a-f]{64}$')");

        DB::statement("ALTER TABLE conflict_exit_legal_referrals
            ADD CONSTRAINT conflict_referral_type_ck
            CHECK (referral_type IN (
                'exit_buyout','share_transfer','external_mediation',
                'arbitration','court','legal_counsel'
            ))");
        DB::statement("ALTER TABLE conflict_exit_legal_referrals
            ADD CONSTRAINT conflict_referral_status_ck
            CHECK (status IN ('referred','accepted','completed','closed'))");

        DB::statement("ALTER TABLE conflict_case_reviews
            ADD CONSTRAINT conflict_review_status_ck
            CHECK (status IN ('open','completed'))");
        DB::statement("ALTER TABLE conflict_case_reviews
            ADD CONSTRAINT conflict_review_outcome_ck
            CHECK (outcome IS NULL OR outcome IN (
                'continue','resolved','escalate','amend_policy'
            ))");
        DB::statement('ALTER TABLE conflict_case_reviews
            ADD CONSTRAINT conflict_review_revision_ck CHECK (revision > 0)');
    }

    private function addGuards(): void
    {
        DB::unprepared(<<<'SQL'
CREATE OR REPLACE FUNCTION pbr_f6e_validate_policy_sources()
RETURNS trigger LANGUAGE plpgsql AS $$
DECLARE
    v_gov_current uuid;
    v_ops_current uuid;
    v_missing integer;
BEGIN
    SELECT h.formal_record_version_id
      INTO v_gov_current
      FROM record_family_effective_heads h
      JOIN formal_record_families f
        ON f.id = h.formal_record_family_id
       AND f.business_id = h.business_id
     WHERE h.business_id = NEW.business_id
       AND f.record_type = 'governance_charter';

    SELECT h.formal_record_version_id
      INTO v_ops_current
      FROM record_family_effective_heads h
      JOIN formal_record_families f
        ON f.id = h.formal_record_family_id
       AND f.business_id = h.business_id
     WHERE h.business_id = NEW.business_id
       AND f.record_type = 'operations_register';

    IF v_gov_current IS DISTINCT FROM NEW.governance_formal_record_version_id THEN
        RAISE EXCEPTION 'Conflict Procedure must capture Current Effective Governance Charter';
    END IF;

    IF v_ops_current IS DISTINCT FROM NEW.operations_formal_record_version_id THEN
        RAISE EXCEPTION 'Conflict Procedure must capture Current Effective Operations Register';
    END IF;

    SELECT count(*) INTO v_missing
      FROM (VALUES
        (NEW.formal_decision_type),
        (NEW.deadlock_decision_type),
        (NEW.misconduct_decision_type),
        (NEW.urgent_risk_decision_type),
        (NEW.settlement_decision_type)
      ) AS required(decision_type)
     WHERE NOT EXISTS (
        SELECT 1
          FROM governance_charter_rules r
         WHERE r.business_id = NEW.business_id
           AND r.formal_record_version_id = NEW.governance_formal_record_version_id
           AND r.decision_type = required.decision_type
     );

    IF v_missing <> 0 THEN
        RAISE EXCEPTION 'Conflict Procedure Decision Types must exist in captured Governance Charter';
    END IF;

    RETURN NEW;
END;
$$;

CREATE TRIGGER conflict_policy_sources_validate
BEFORE INSERT OR UPDATE ON conflict_policy_versions
FOR EACH ROW EXECUTE FUNCTION pbr_f6e_validate_policy_sources();
SQL);

        DB::unprepared(<<<'SQL'
CREATE OR REPLACE FUNCTION pbr_f6e_policy_snapshot_mutable()
RETURNS trigger LANGUAGE plpgsql AS $$
DECLARE
    v_version uuid;
    v_frozen timestamptz;
BEGIN
    v_version := CASE
        WHEN TG_OP = 'DELETE' THEN OLD.formal_record_version_id
        ELSE NEW.formal_record_version_id
    END;

    SELECT frozen_at INTO v_frozen
      FROM formal_record_versions
     WHERE id = v_version;

    IF v_frozen IS NOT NULL THEN
        RAISE EXCEPTION 'Frozen Conflict Procedure snapshot is immutable';
    END IF;

    RETURN CASE WHEN TG_OP = 'DELETE' THEN OLD ELSE NEW END;
END;
$$;

CREATE TRIGGER conflict_policy_header_mutable
BEFORE UPDATE OR DELETE ON conflict_policy_versions
FOR EACH ROW EXECUTE FUNCTION pbr_f6e_policy_snapshot_mutable();

CREATE TRIGGER conflict_escalation_rules_mutable
BEFORE INSERT OR UPDATE OR DELETE ON conflict_escalation_rules
FOR EACH ROW EXECUTE FUNCTION pbr_f6e_policy_snapshot_mutable();

CREATE TRIGGER conflict_special_rules_mutable
BEFORE INSERT OR UPDATE OR DELETE ON conflict_special_path_rules
FOR EACH ROW EXECUTE FUNCTION pbr_f6e_policy_snapshot_mutable();
SQL);

        DB::unprepared(<<<'SQL'
CREATE OR REPLACE FUNCTION pbr_f6e_validate_policy_role()
RETURNS trigger LANGUAGE plpgsql AS $$
DECLARE
    v_ops_version uuid;
    v_role_version uuid;
BEGIN
    IF NEW.operations_role_id IS NULL THEN
        RETURN NEW;
    END IF;

    SELECT operations_formal_record_version_id
      INTO v_ops_version
      FROM conflict_policy_versions
     WHERE business_id = NEW.business_id
       AND formal_record_version_id = NEW.formal_record_version_id;

    SELECT formal_record_version_id
      INTO v_role_version
      FROM operations_roles
     WHERE business_id = NEW.business_id
       AND id = NEW.operations_role_id;

    IF v_ops_version IS NULL OR v_role_version IS DISTINCT FROM v_ops_version THEN
        RAISE EXCEPTION 'Conflict Procedure role must bind captured Operations Version';
    END IF;

    RETURN NEW;
END;
$$;

CREATE TRIGGER conflict_escalation_rule_role_validate
BEFORE INSERT OR UPDATE ON conflict_escalation_rules
FOR EACH ROW EXECUTE FUNCTION pbr_f6e_validate_policy_role();

CREATE TRIGGER conflict_special_rule_role_validate
BEFORE INSERT OR UPDATE ON conflict_special_path_rules
FOR EACH ROW EXECUTE FUNCTION pbr_f6e_validate_policy_role();
SQL);

        DB::unprepared(<<<'SQL'
CREATE OR REPLACE FUNCTION pbr_f6e_protect_case_source()
RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN
    IF TG_OP = 'DELETE' THEN
        RAISE EXCEPTION 'Conflict Case history cannot be deleted';
    END IF;

    IF NEW.business_id IS DISTINCT FROM OLD.business_id
       OR NEW.case_number IS DISTINCT FROM OLD.case_number
       OR NEW.conflict_policy_formal_record_version_id IS DISTINCT FROM OLD.conflict_policy_formal_record_version_id
       OR NEW.raised_at IS DISTINCT FROM OLD.raised_at
       OR NEW.raised_by_membership_id IS DISTINCT FROM OLD.raised_by_membership_id
       OR NEW.conflict_owner_membership_id IS DISTINCT FROM OLD.conflict_owner_membership_id
       OR NEW.conflict_type IS DISTINCT FROM OLD.conflict_type
       OR NEW.description IS DISTINCT FROM OLD.description
       OR NEW.business_impact IS DISTINCT FROM OLD.business_impact
       OR NEW.urgency IS DISTINCT FROM OLD.urgency
       OR NEW.related_rule_reference IS DISTINCT FROM OLD.related_rule_reference
       OR NEW.confidentiality IS DISTINCT FROM OLD.confidentiality
       OR NEW.created_at IS DISTINCT FROM OLD.created_at THEN
        RAISE EXCEPTION 'Conflict Case source identity is immutable';
    END IF;

    RETURN NEW;
END;
$$;

CREATE TRIGGER conflict_case_source_history
BEFORE UPDATE OR DELETE ON conflict_cases
FOR EACH ROW EXECUTE FUNCTION pbr_f6e_protect_case_source();

CREATE OR REPLACE FUNCTION pbr_f6e_append_only()
RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN
    RAISE EXCEPTION 'F6E historical row is append-only';
END;
$$;

CREATE TRIGGER conflict_case_updates_append_only
BEFORE UPDATE OR DELETE ON conflict_case_updates
FOR EACH ROW EXECUTE FUNCTION pbr_f6e_append_only();

CREATE TRIGGER conflict_direct_discussion_append_only
BEFORE UPDATE OR DELETE ON conflict_direct_discussions
FOR EACH ROW EXECUTE FUNCTION pbr_f6e_append_only();

CREATE TRIGGER conflict_direct_participant_append_only
BEFORE UPDATE OR DELETE ON conflict_direct_discussion_participants
FOR EACH ROW EXECUTE FUNCTION pbr_f6e_append_only();

CREATE TRIGGER conflict_mediation_response_append_only
BEFORE UPDATE OR DELETE ON conflict_mediation_responses
FOR EACH ROW EXECUTE FUNCTION pbr_f6e_append_only();

CREATE TRIGGER conflict_investigation_finding_append_only
BEFORE UPDATE OR DELETE ON conflict_investigation_findings
FOR EACH ROW EXECUTE FUNCTION pbr_f6e_append_only();

CREATE TRIGGER conflict_settlement_doc_append_only
BEFORE UPDATE OR DELETE ON conflict_settlement_document_bindings
FOR EACH ROW EXECUTE FUNCTION pbr_f6e_append_only();

CREATE TRIGGER conflict_case_settlement_link_append_only
BEFORE UPDATE OR DELETE ON conflict_case_settlement_links
FOR EACH ROW EXECUTE FUNCTION pbr_f6e_append_only();

CREATE TRIGGER conflict_referral_append_only
BEFORE UPDATE OR DELETE ON conflict_exit_legal_referrals
FOR EACH ROW EXECUTE FUNCTION pbr_f6e_append_only();

CREATE TRIGGER conflict_action_link_append_only
BEFORE UPDATE OR DELETE ON conflict_action_links
FOR EACH ROW EXECUTE FUNCTION pbr_f6e_append_only();
SQL);

        DB::unprepared(<<<'SQL'
CREATE OR REPLACE FUNCTION pbr_f6e_protect_completed_mediation()
RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN
    IF TG_OP = 'DELETE' THEN
        IF OLD.status IN ('completed','cancelled') THEN
            RAISE EXCEPTION 'Completed or cancelled Mediation is immutable';
        END IF;
        RETURN OLD;
    END IF;

    IF OLD.status IN ('completed','cancelled') THEN
        RAISE EXCEPTION 'Completed or cancelled Mediation is immutable';
    END IF;

    IF NEW.business_id IS DISTINCT FROM OLD.business_id
       OR NEW.conflict_case_id IS DISTINCT FROM OLD.conflict_case_id
       OR NEW.mediator_type IS DISTINCT FROM OLD.mediator_type
       OR NEW.mediator_membership_id IS DISTINCT FROM OLD.mediator_membership_id
       OR NEW.external_mediator_reference IS DISTINCT FROM OLD.external_mediator_reference
       OR NEW.mediation_at IS DISTINCT FROM OLD.mediation_at
       OR NEW.neutrality_check IS DISTINCT FROM OLD.neutrality_check
       OR NEW.created_by_membership_id IS DISTINCT FROM OLD.created_by_membership_id
       OR NEW.created_at IS DISTINCT FROM OLD.created_at THEN
        RAISE EXCEPTION 'Mediation source identity is immutable';
    END IF;

    RETURN NEW;
END;
$$;

CREATE TRIGGER conflict_mediation_history
BEFORE UPDATE OR DELETE ON conflict_mediations
FOR EACH ROW EXECUTE FUNCTION pbr_f6e_protect_completed_mediation();
SQL);

        DB::unprepared(<<<'SQL'
CREATE OR REPLACE FUNCTION pbr_f6e_protect_decision_submission()
RETURNS trigger LANGUAGE plpgsql AS $$
DECLARE
    v_proposal uuid;
BEGIN
    IF TG_OP = 'DELETE' THEN
        RAISE EXCEPTION 'Conflict Decision Submission is immutable';
    END IF;

    IF NEW.business_id IS DISTINCT FROM OLD.business_id
       OR NEW.conflict_case_id IS DISTINCT FROM OLD.conflict_case_id
       OR NEW.case_revision IS DISTINCT FROM OLD.case_revision
       OR NEW.package_hash IS DISTINCT FROM OLD.package_hash
       OR NEW.decision_type IS DISTINCT FROM OLD.decision_type
       OR NEW.proposed_decision_summary IS DISTINCT FROM OLD.proposed_decision_summary
       OR NEW.conditions IS DISTINCT FROM OLD.conditions
       OR NEW.appeal_reference IS DISTINCT FROM OLD.appeal_reference
       OR NEW.proposal_version_id IS DISTINCT FROM OLD.proposal_version_id
       OR NEW.created_by_membership_id IS DISTINCT FROM OLD.created_by_membership_id
       OR NEW.created_at IS DISTINCT FROM OLD.created_at THEN
        RAISE EXCEPTION 'Conflict Decision Submission frozen package is immutable';
    END IF;

    IF OLD.decision_id IS NOT NULL
       AND NEW.decision_id IS DISTINCT FROM OLD.decision_id THEN
        RAISE EXCEPTION 'Conflict Decision binding is immutable once set';
    END IF;

    IF NEW.decision_id IS NOT NULL THEN
        SELECT proposal_version_id INTO v_proposal
          FROM decisions
         WHERE business_id = NEW.business_id
           AND id = NEW.decision_id;

        IF v_proposal IS DISTINCT FROM NEW.proposal_version_id THEN
            RAISE EXCEPTION 'Conflict Decision must bind exact Frozen Proposal Version';
        END IF;
    END IF;

    RETURN NEW;
END;
$$;

CREATE TRIGGER conflict_decision_submission_history
BEFORE UPDATE OR DELETE ON conflict_decision_submissions
FOR EACH ROW EXECUTE FUNCTION pbr_f6e_protect_decision_submission();
SQL);

        DB::unprepared(<<<'SQL'
CREATE OR REPLACE FUNCTION pbr_f6e_protect_investigation()
RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN
    IF TG_OP = 'DELETE' THEN
        RAISE EXCEPTION 'Conflict Investigation history cannot be deleted';
    END IF;

    IF NEW.business_id IS DISTINCT FROM OLD.business_id
       OR NEW.conflict_case_id IS DISTINCT FROM OLD.conflict_case_id
       OR NEW.allegation IS DISTINCT FROM OLD.allegation
       OR NEW.investigation_owner_membership_id IS DISTINCT FROM OLD.investigation_owner_membership_id
       OR NEW.temporary_restriction_proposal IS DISTINCT FROM OLD.temporary_restriction_proposal
       OR NEW.opened_at IS DISTINCT FROM OLD.opened_at
       OR NEW.created_by_membership_id IS DISTINCT FROM OLD.created_by_membership_id
       OR NEW.created_at IS DISTINCT FROM OLD.created_at THEN
        RAISE EXCEPTION 'Conflict Investigation source identity is immutable';
    END IF;

    RETURN NEW;
END;
$$;

CREATE TRIGGER conflict_investigation_history
BEFORE UPDATE OR DELETE ON conflict_investigations
FOR EACH ROW EXECUTE FUNCTION pbr_f6e_protect_investigation();
SQL);

        DB::unprepared(<<<'SQL'
CREATE OR REPLACE FUNCTION pbr_f6e_validate_urgent_authority()
RETURNS trigger LANGUAGE plpgsql AS $$
DECLARE
    v_decision_type text;
    v_grantee uuid;
    v_scope text;
    v_status text;
    v_effective timestamptz;
    v_expires timestamptz;
    v_authorized timestamptz;
BEGIN
    IF NEW.emergency_authority_grant_id IS NULL THEN
        RETURN NEW;
    END IF;

    SELECT p.urgent_risk_decision_type
      INTO v_decision_type
      FROM conflict_cases c
      JOIN conflict_policy_versions p
        ON p.business_id = c.business_id
       AND p.formal_record_version_id =
           c.conflict_policy_formal_record_version_id
     WHERE c.business_id = NEW.business_id
       AND c.id = NEW.conflict_case_id;

    IF v_decision_type IS NULL THEN
        RAISE EXCEPTION 'Urgent Risk requires same-Business Conflict Case';
    END IF;

    SELECT grantee_membership_id, scope, status, effective_from, expires_at
      INTO v_grantee, v_scope, v_status, v_effective, v_expires
      FROM emergency_authority_grants
     WHERE business_id = NEW.business_id
       AND id = NEW.emergency_authority_grant_id
       AND decision_type = v_decision_type;

    IF NOT FOUND
       OR v_grantee IS DISTINCT FROM NEW.created_by_membership_id
       OR v_scope IS DISTINCT FROM
            ('conflict_case:' || NEW.conflict_case_id::text)
       OR v_status IS DISTINCT FROM 'active'
       OR v_effective IS DISTINCT FROM NEW.authority_starts_at
       OR v_expires IS DISTINCT FROM NEW.authority_expires_at
       OR CURRENT_TIMESTAMP < v_effective
       OR CURRENT_TIMESTAMP >= v_expires THEN
        RAISE EXCEPTION 'Urgent Risk authority must match exact governed Emergency Authority Grant';
    END IF;

    SELECT authorized_at INTO v_authorized
      FROM governance_authority_change_submissions
     WHERE business_id = NEW.business_id
       AND subject_type = 'emergency_authority'
       AND subject_id = NEW.emergency_authority_grant_id
       AND action = 'grant';

    IF v_authorized IS NULL THEN
        RAISE EXCEPTION 'Emergency Authority Grant is not governed/authorized';
    END IF;

    RETURN NEW;
END;
$$;

CREATE TRIGGER conflict_urgent_authority_validate
BEFORE INSERT OR UPDATE ON conflict_urgent_risk_records
FOR EACH ROW EXECUTE FUNCTION pbr_f6e_validate_urgent_authority();

CREATE OR REPLACE FUNCTION pbr_f6e_protect_urgent_source()
RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN
    IF TG_OP = 'DELETE' THEN
        RAISE EXCEPTION 'Conflict Urgent Risk history cannot be deleted';
    END IF;

    IF NEW.business_id IS DISTINCT FROM OLD.business_id
       OR NEW.conflict_case_id IS DISTINCT FROM OLD.conflict_case_id
       OR NEW.urgent_risk IS DISTINCT FROM OLD.urgent_risk
       OR NEW.immediate_action IS DISTINCT FROM OLD.immediate_action
       OR NEW.informed_parties IS DISTINCT FROM OLD.informed_parties
       OR NEW.review_deadline IS DISTINCT FROM OLD.review_deadline
       OR NEW.emergency_authority_grant_id IS DISTINCT FROM OLD.emergency_authority_grant_id
       OR NEW.authority_starts_at IS DISTINCT FROM OLD.authority_starts_at
       OR NEW.authority_expires_at IS DISTINCT FROM OLD.authority_expires_at
       OR NEW.created_by_membership_id IS DISTINCT FROM OLD.created_by_membership_id
       OR NEW.created_at IS DISTINCT FROM OLD.created_at THEN
        RAISE EXCEPTION 'Conflict Urgent Risk source/authority binding is immutable';
    END IF;

    RETURN NEW;
END;
$$;

CREATE TRIGGER conflict_urgent_history
BEFORE UPDATE OR DELETE ON conflict_urgent_risk_records
FOR EACH ROW EXECUTE FUNCTION pbr_f6e_protect_urgent_source();
SQL);

        DB::unprepared(<<<'SQL'
CREATE OR REPLACE FUNCTION pbr_f6e_settlement_snapshot_mutable()
RETURNS trigger LANGUAGE plpgsql AS $$
DECLARE
    v_version uuid;
    v_frozen timestamptz;
BEGIN
    v_version := CASE
        WHEN TG_OP = 'DELETE' THEN OLD.formal_record_version_id
        ELSE NEW.formal_record_version_id
    END;

    SELECT frozen_at INTO v_frozen
      FROM formal_record_versions
     WHERE id = v_version;

    IF v_frozen IS NOT NULL THEN
        RAISE EXCEPTION 'Frozen Conflict Settlement snapshot is immutable';
    END IF;

    RETURN CASE WHEN TG_OP = 'DELETE' THEN OLD ELSE NEW END;
END;
$$;

CREATE TRIGGER conflict_settlement_mutable
BEFORE UPDATE OR DELETE ON conflict_settlement_versions
FOR EACH ROW EXECUTE FUNCTION pbr_f6e_settlement_snapshot_mutable();

CREATE OR REPLACE FUNCTION pbr_f6e_validate_settlement_binding()
RETURNS trigger LANGUAGE plpgsql AS $$
DECLARE
    v_hash text;
BEGIN
    SELECT content_hash INTO v_hash
      FROM formal_record_versions
     WHERE business_id = NEW.business_id
       AND id = NEW.formal_record_version_id;

    IF v_hash IS NULL
       OR v_hash IS DISTINCT FROM NEW.captured_settlement_content_hash THEN
        RAISE EXCEPTION 'Settlement Document binding must capture exact Settlement content hash';
    END IF;

    RETURN NEW;
END;
$$;

CREATE TRIGGER conflict_settlement_doc_validate
BEFORE INSERT ON conflict_settlement_document_bindings
FOR EACH ROW EXECUTE FUNCTION pbr_f6e_validate_settlement_binding();
SQL);

        DB::unprepared(<<<'SQL'
CREATE OR REPLACE FUNCTION pbr_f6e_validate_action_link()
RETURNS trigger LANGUAGE plpgsql AS $$
DECLARE
    v_ops_record uuid;
    v_ops_role uuid;
BEGIN
    SELECT formal_record_version_id, operations_role_id
      INTO v_ops_record, v_ops_role
      FROM operations_action_links
     WHERE business_id = NEW.business_id
       AND action_id = NEW.action_id;

    IF NOT FOUND
       OR v_ops_record IS DISTINCT FROM NEW.operations_formal_record_version_id
       OR v_ops_role IS DISTINCT FROM NEW.operations_role_id THEN
        RAISE EXCEPTION 'Conflict follow-up Action must preserve exact Operations binding';
    END IF;

    RETURN NEW;
END;
$$;

CREATE TRIGGER conflict_action_link_validate
BEFORE INSERT ON conflict_action_links
FOR EACH ROW EXECUTE FUNCTION pbr_f6e_validate_action_link();
SQL);
    }

    private function backfillConflictCapabilities(): void
    {
        $now = now();
        $permissionIds = [];

        foreach ($this->conflictCapabilities as $key) {
            $id = DB::table('permissions')->where('key', $key)->value('id');

            if ($id === null) {
                $id = (string) Str::uuid7();
                DB::table('permissions')->insert([
                    'id' => $id,
                    'key' => $key,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
            }

            $permissionIds[$key] = (string) $id;
        }

        $matrix = [
            'Workspace Owner' => $this->conflictCapabilities,
            'Partner' => ['conflict.view'],
            'Managing Partner / CEO' => $this->conflictCapabilities,
            'Finance Owner' => ['conflict.view'],
            'Governance Secretary / PBR Administrator' => $this->conflictCapabilities,
            'Advisor / Consultant' => ['conflict.view'],
            'Auditor / Viewer' => ['conflict.view'],
            'External Accountant / Legal Advisor' => ['conflict.view'],
        ];

        $profiles = DB::table('permission_profiles')
            ->whereIn('name', array_keys($matrix))
            ->get(['id', 'business_id', 'name']);

        foreach ($profiles as $profile) {
            foreach ($matrix[$profile->name] as $capability) {
                DB::table('permission_profile_permissions')->insertOrIgnore([
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
        foreach ([
            ['conflict_action_links', 'conflict_action_link_validate'],
            ['conflict_action_links', 'conflict_action_link_append_only'],
            ['conflict_settlement_document_bindings', 'conflict_settlement_doc_validate'],
            ['conflict_settlement_document_bindings', 'conflict_settlement_doc_append_only'],
            ['conflict_settlement_versions', 'conflict_settlement_mutable'],
            ['conflict_exit_legal_referrals', 'conflict_referral_append_only'],
            ['conflict_case_settlement_links', 'conflict_case_settlement_link_append_only'],
            ['conflict_urgent_risk_records', 'conflict_urgent_history'],
            ['conflict_urgent_risk_records', 'conflict_urgent_authority_validate'],
            ['conflict_investigation_findings', 'conflict_investigation_finding_append_only'],
            ['conflict_investigations', 'conflict_investigation_history'],
            ['conflict_decision_submissions', 'conflict_decision_submission_history'],
            ['conflict_mediation_responses', 'conflict_mediation_response_append_only'],
            ['conflict_mediations', 'conflict_mediation_history'],
            ['conflict_direct_discussion_participants', 'conflict_direct_participant_append_only'],
            ['conflict_direct_discussions', 'conflict_direct_discussion_append_only'],
            ['conflict_case_updates', 'conflict_case_updates_append_only'],
            ['conflict_cases', 'conflict_case_source_history'],
            ['conflict_special_path_rules', 'conflict_special_rule_role_validate'],
            ['conflict_escalation_rules', 'conflict_escalation_rule_role_validate'],
            ['conflict_special_path_rules', 'conflict_special_rules_mutable'],
            ['conflict_escalation_rules', 'conflict_escalation_rules_mutable'],
            ['conflict_policy_versions', 'conflict_policy_header_mutable'],
            ['conflict_policy_versions', 'conflict_policy_sources_validate'],
        ] as [$table, $trigger]) {
            DB::statement("DROP TRIGGER IF EXISTS {$trigger} ON {$table}");
        }

        foreach ([
            'pbr_f6e_validate_action_link',
            'pbr_f6e_validate_settlement_binding',
            'pbr_f6e_settlement_snapshot_mutable',
            'pbr_f6e_protect_urgent_source',
            'pbr_f6e_validate_urgent_authority',
            'pbr_f6e_protect_investigation',
            'pbr_f6e_protect_decision_submission',
            'pbr_f6e_protect_completed_mediation',
            'pbr_f6e_append_only',
            'pbr_f6e_protect_case_source',
            'pbr_f6e_validate_policy_role',
            'pbr_f6e_policy_snapshot_mutable',
            'pbr_f6e_validate_policy_sources',
        ] as $function) {
            DB::unprepared("DROP FUNCTION IF EXISTS {$function}()");
        }

        foreach ([
            'conflict_case_reviews',
            'conflict_action_links',
            'conflict_exit_legal_referrals',
            'conflict_case_settlement_links',
            'conflict_settlement_document_bindings',
            'conflict_settlement_versions',
            'conflict_urgent_risk_records',
            'conflict_investigation_findings',
            'conflict_investigations',
            'conflict_deadlock_records',
            'conflict_escalations',
            'conflict_decision_submissions',
            'conflict_mediation_responses',
            'conflict_mediations',
            'conflict_direct_discussion_participants',
            'conflict_direct_discussions',
            'conflict_case_updates',
            'conflict_case_participants',
            'conflict_cases',
            'conflict_special_path_rules',
            'conflict_escalation_rules',
            'conflict_policy_versions',
        ] as $table) {
            Schema::dropIfExists($table);
        }
    }
};
