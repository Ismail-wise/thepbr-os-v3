# F6E Conflict — Technical Scope Freeze

Status: FROZEN before implementation
Date: 2026-09-27
Baseline branch: feature/f6-governance-operations
Baseline HEAD: 84e7b0142b3f0b33b567944474df4b86610e2d50
Baseline tree: 072ee7b400880cc2024228438ff5f7e6d7bc1983
Baseline CI: #54 / 36327605541 SUCCESS
Human G5: DEFERRED / NOT PASS

## Source reconciliation

Authority precedence was applied exactly:

1. 01_thePBR_OS_MASTER_SPEC_v1.1.md
2. PBR_MASTER_SOURCE_COMPLETE_2026.pdf
3. 02_SOURCE_TRACEABILITY_MAP.md
4. 03_DEVELOPMENT_ROADMAP_AND_GATES.md
5. accepted F2/F3/F6A/F6B/F6D implementation contracts

No genuine source conflict was found.

The Master Spec defines Conflict as formal case management rather than only an escalation ladder: classification, evidence, direct discussion, mediation, formal decision, deadlock/misconduct/urgent-risk paths, settlement and exit/legal escalation. It also requires restricted derived systems to avoid leaking existence, title, count, snippets, notification text or health signals.

The PBR domain source defines:
- Conflict types: Ordinary Disagreement, Governance Dispute, Financial Dispute, Role/Performance Conflict, Conflict of Interest, Misconduct, Agreement Breach, Urgent Risk, 50/50 Deadlock, Relationship Breakdown, Other.
- Conflict Case Record with raised date/by, parties, description, business impact, urgency, related rule/evidence and status.
- Direct Discussion.
- Internal/external Mediation with neutrality/conflict check and party responses.
- Formal Decision linked to Governance authority/voting/COI exclusion.
- Escalation Ladder as one tool, not the whole module.
- separate Deadlock, Misconduct/Breach and Urgent Risk paths.
- Settlement Agreement and follow-up obligations.
- Exit / legal remedy handoff where unresolved.
- Resolution Register.
- connected Governance, Operations, Finance, Exit and Transfer references.

The Traceability Map confirms Conflict/Deadlock/Resolution is governed by the conflict source and that V3 tenancy, permissions, record-level access, Proposal/Frozen Proposal, derived-data privacy and technical architecture come from the Master Spec.

Historical source instructions to preserve the old chapter-page visual design do not override the Master Spec. F6E uses the private Business Command Center architecture.

## Purpose

F6E provides a confidential, auditable Partner Conflict Resolution operating capability that:
- records a restricted Conflict Case without leaking its existence;
- preserves an exact Conflict Procedure, Governance and Operations context;
- supports evidence, direct discussion and mediation without turning either into Governance Approval;
- reuses the centralized Governance Decision / Approval / Vote / Signature engines when formal authority is required;
- handles deadlock, misconduct/breach and urgent-risk paths without manufacturing module-local authority;
- creates versioned, governed Settlement Agreements where settlement is required;
- records exit/legal escalation as a handoff only and does not mutate F7 lifecycle/Ownership truth;
- creates operational follow-up Actions through Current Effective Operations responsibility;
- preserves append-only history and safe Timeline/Audit visibility.

## Explicit boundaries

F6E DOES:
- manage restricted Conflict Cases;
- manage an approved/versioned Conflict Resolution Procedure;
- link evidence through the existing Evidence/Vault foundation;
- record direct discussion and mediation;
- create exact frozen conflict decision submissions that feed the existing Governance engine;
- record deadlock/misconduct/urgent-risk case paths;
- create governed/versioned Settlement Agreement records;
- bind a required settlement signature to an exact Document Version/hash;
- record exit/legal referrals without executing exit/transfer;
- reuse Operations Actions for delivery;
- record reviews and safe activity events.

F6E DOES NOT:
- create a second Decision, Vote, Approval, Signature or authority engine;
- treat mediation acceptance as Approval;
- infer Governance authority from Ownership, Workspace Owner, System Permission or PartnerDynamics;
- execute share transfer, buyout, exit, closure, payment or Ownership mutation;
- auto-apply temporary system restrictions from a misconduct case;
- grant permanent or unrestricted authority through an urgent-risk path;
- expose restricted case existence through derived surfaces;
- redesign PartnerDynamics or Public Website;
- deploy Preview/Production;
- claim Human G5 PASS.

## Dependency map

F2:
- AuthorizeBusinessCapability
- RecordAccessRule / AccessPolicy
- Formal Record Family / Version / Effective Head
- Proposal / Frozen Proposal Version
- Evidence / Document / Document Version
- Audit / Business Events
- optimistic revision semantics

F3:
- Proposal Review
- Decision
- Vote
- Approval
- Authority Snapshot
- COI / recusal
- Signature Request / exact Document Version/hash
- Action / Review machinery

F6A:
- Current Effective Governance Charter / Authority Matrix
- exact Decision Types
- Deadlock rule authority
- Delegation / Emergency Authority

F6B:
- Current Effective Operations Register
- Roles / assignments / RACI
- Operations Action responsibility

F6C:
- Finance remains canonical for payment/control truth.
- a financial settlement amount in F6E is an agreement term only; it never constitutes payment or Finance verification.

F6D:
- restricted-record visibility pattern
- restricted Evidence-link authorization pattern
- safe derived count filtering
- exact Emergency Authority binding pattern

F7:
- Exit/Buyout and Transfer/New Partner are not implemented by F6E.
- F6E records a handoff/referral only; F7 will consume that history without rewriting it.

## Canonical source ownership

| Business fact | Canonical source |
|---|---|
| Conflict procedure currently in force | Current Effective conflict_resolution_policy Formal Record Version |
| Governance authority / thresholds / COI / deadlock authority | exact Effective Governance Charter + F3/F6A Authority Snapshot |
| Operational role/responsibility | exact Effective Operations Register / Current Effective role assignment |
| Conflict Case identity and original allegation/context | conflict_cases immutable source fields |
| Case stage/status history | append-only conflict_case_updates + current case projection |
| Parties / mediator / investigator participation | Conflict participant/mediation/investigation records |
| Who may see a restricted case | F2 record-level AccessPolicy / RecordAccessRule, not participant status |
| Evidence | F2 Evidence + EvidenceLink; Document access separately authorized |
| Direct discussion result | immutable Direct Discussion record |
| Mediation | Mediation record + per-party mediation responses |
| Governance decision | F3 Decision + exact Proposal Version + Authority Snapshot |
| Deadlock authority | captured exact Governance Charter; F6E Deadlock record is case state only |
| Misconduct finding/remedy | Conflict Investigation + immutable Finding record |
| Urgent temporary authority | F6A governed Emergency Authority Grant; F6E never creates authority |
| Settlement terms | exact Conflict Settlement Formal Record Version |
| Signed settlement artifact | exact bound Document Version/hash + F3 Signature Request/Evidence |
| Follow-up work | existing Operations Action + Conflict Action Link |
| Exit/legal escalation | Conflict Referral/Handoff record; not F7 Exit truth |
| History | immutable records + append-only updates/events/audit |

## Relational data model

### Conflict Procedure

conflict_policy_versions:
- id
- business_id
- formal_record_version_id unique
- governance_formal_record_version_id
- operations_formal_record_version_id
- conflict_owner_membership_id
- formal_decision_type
- deadlock_decision_type
- misconduct_decision_type
- urgent_risk_decision_type
- settlement_decision_type
- review_frequency
- notes
- created_at

The captured Governance/Operations versions are exact historical references. F6E does not copy Governance authority thresholds or Operations assignments.

conflict_escalation_rules:
- exact policy version
- sequence
- step_key
- entry_condition
- optional exact Operations role from the captured Operations version
- max_days
- required_evidence
- optional decision_type
- resolution_exit_condition
- optional next_step_key
- status

conflict_special_path_rules:
- exact policy version
- path_type = deadlock|misconduct|urgent_risk
- entry_condition
- procedure_summary
- optional Operations role
- review_deadline_days
- decision_type
- external_handoff_rule

These rules describe case procedure. They do not create Governance authority. Decision Types must exist in the captured Governance version before the Conflict Procedure may become Effective.

### Restricted Conflict Case

conflict_cases:
- id, business_id
- immutable case_number
- exact conflict_policy_formal_record_version_id
- raised_at
- raised_by_membership_id
- conflict_owner_membership_id
- conflict_type
- description
- business_impact
- urgency
- optional related_rule_reference
- confidentiality = restricted
- current stage
- current status
- optional review_due_at
- optional resolved_at
- optional resolution_source_type / resolution_source_id
- optimistic revision
- timestamps

Core case identity/classification/context is immutable after creation. Stage/status/review/resolution are controlled transitions with append-only history.

conflict_case_participants:
- case
- same-Business Membership where applicable
- participant_role = party|conflict_owner|mediator|investigator|advisor|legal|observer
- status = active|removed
- added_at, optional removed_at

Participant status never grants case access, Governance authority or Document access.

conflict_case_updates:
- append-only case stage/status transition history
- from/to stage and status
- opaque/non-sensitive transition note code where possible
- actor Membership
- occurred_at

### Direct Discussion

conflict_direct_discussions:
- case
- meeting date/time
- issues discussed
- each-side position summary
- proposed solutions
- outcome = resolved|continue_mediation|continue_formal_decision
- optional follow-up date
- recorded by
- immutable after recording

conflict_direct_discussion_participants:
- discussion
- case participant Membership
- immutable

A direct-resolution outcome may resolve an ordinary case but is not Governance Approval.

### Mediation

conflict_mediations:
- case
- mediator_type = internal|external
- optional internal mediator Membership
- optional external mediator reference
- mediation date
- neutrality/conflict-check record
- summary
- proposed settlement
- response deadline
- status = planned|completed|cancelled
- revision / completed_at

conflict_mediation_responses:
- mediation
- case-party Membership
- response = pending|accepted|rejected|needs_changes
- response note
- responded_at

Mediation responses are acknowledgements/settlement responses, never F3 Approval.

### Formal Decision

conflict_decision_submissions:
- case
- exact case_revision
- immutable package_hash
- exact decision_type from Conflict Procedure
- proposed decision summary
- optional conditions
- appeal mode/reference
- exact Frozen proposal_version_id
- optional F3 decision_id
- created by / created at

Opening a formal decision:
1. Conflict package is hashed at exact case revision.
2. Existing Proposal + Frozen Proposal Version are used.
3. Existing Proposal Review is required.
4. Existing OpenGovernanceDecision resolves authority from F6A.
5. Existing Vote/Approval/recusal/Authority Snapshot machinery governs outcome.
6. F6E only links the resulting exact Decision.

No module approval boolean exists.

### Escalation / special paths

conflict_escalations:
- case
- exact policy escalation rule
- entered_at / due_at
- responsible exact Operations role
- status/outcome
- optional decision submission reference
- immutable source binding

conflict_deadlock_records:
- case
- failed-vote count
- cooling-off until
- neutral-advisor/mediator reference
- optional exact Governance Decision
- status/revision
- outcome
- no duplicated casting-vote/approval authority

Deadlock authority remains canonical in the captured Governance Charter.

conflict_investigations:
- case
- allegation
- investigation owner Membership
- temporary-restriction proposal/reference only
- status/revision
- opened/completed timestamps

conflict_investigation_findings:
- investigation
- finding
- sanction/remedy recommendation
- appeal/reference
- exit-trigger recommendation
- recorded by / at
- append-only

A restriction proposal never directly mutates System Permissions, bank access, Ownership or Governance authority.

conflict_urgent_risk_records:
- case
- urgent risk
- immediate action
- who must be informed
- review deadline
- optional exact F6A Emergency Authority Grant
- optional final Governance Decision
- status/revision

If an urgent action requires Governance authority, the exact authorized F6A Emergency Authority Grant must match:
- Business
- grantee
- Conflict Procedure decision type
- scope = conflict_case:<case-id>
- effective/expiry window
- governed authorization submission

F6E never creates the authority grant.

### Settlement

A Settlement Agreement is a Formal Record family/version:
- record_type = conflict_settlement
- subject_type = conflict_case
- subject_id = case id

conflict_settlement_versions:
- exact Formal Record Version
- case
- optional source Mediation
- optional source Governance Decision
- settlement terms
- required actions summary
- responsible owner Membership
- due date
- optional financial settlement amount in minor units + ISO currency
- confidentiality terms
- future-conduct terms
- review date
- exact settlement_decision_type
- created_at

Financial terms are agreement data only and never issue payment.

conflict_settlement_document_bindings:
- exact Settlement Formal Record Version
- exact Document Version
- captured Settlement content hash
- bound by / bound at
- append-only

When the authority snapshot requires signature:
- a binding is mandatory;
- existing F3 Signature Request must be completed for the exact bound Document Version/hash and exact Decision;
- changing Settlement content creates a new Formal Record Version and requires a new binding/signature.

conflict_case_settlement_links:
- case
- exact Effective Settlement Formal Record Version
- exact approved Governance Decision
- linked at
- append-only

Case settlement/closure never treats signed alone as Effective.

### Exit/legal handoff

conflict_exit_legal_referrals:
- case
- referral_type = exit_buyout|share_transfer|external_mediation|arbitration|court|legal_counsel
- trigger/reason
- external/reference field
- status
- referred_by / referred_at
- optional completed_at

This is a handoff record only. It does not create an Exit Case, Transfer Case, Ownership change or legal conclusion.

### Actions / reviews

conflict_action_links:
- Conflict Case
- existing Operations Action
- exact current Operations role/version link already preserved by F6B
- source type/id
- append-only

conflict_case_reviews:
- case
- reviewer Membership
- due date
- status = open|completed
- outcome = continue|resolved|escalate|amend_policy
- notes
- resolved_at
- revision

Review is not Approval.

## Case workflow

Normal case opening requires a Current Effective Conflict Resolution Procedure. This follows the PBR rule that conflict rules are established before conflict occurs.

Primary path:

Intake -> Direct Discussion -> Mediation -> Formal Decision -> Settlement -> Resolved/Closed

Allowed branches:
- Direct Discussion may resolve an ordinary case.
- Mediation may lead to Settlement or Formal Decision.
- Governance/50-50 Deadlock may enter Deadlock.
- Misconduct/Breach may enter Investigation.
- Urgent Risk may enter Urgent Risk procedure immediately.
- Any unresolved governed path may move through Escalation.
- Exit/Legal referral is a recorded handoff and does not mutate F7 truth.

All transitions:
- require active Membership + Business + conflict.manage + exact case access;
- use optimistic revision;
- append conflict_case_updates;
- preserve source identity;
- emit safe Audit/Business Event metadata without case description/evidence.

## Permission / confidentiality model

New system capabilities:
- conflict.view
- conflict.manage

These are System Permissions only.

Every Conflict Case is restricted by default.

Required checks for sensitive reads/writes:
- authenticated User
- active Membership
- current Business
- resource same-Business
- applicable capability
- exact record-level access
- workflow state
- Governance authority when an actual Governance action is taken
- Document access separately for Evidence/Signature artifacts

ConflictRecordVisibility reuses F2 AuthorizeBusinessCapability / RecordAccessRule.

Case participation does not automatically grant access.
Conflict access does not automatically grant Document access.
Conflict access does not automatically grant Governance authority.
Workspace Owner is not Governance god-mode.
Ownership/PartnerDynamics do not create Conflict/Governance authority.

Explicit scoped deny wins.

Case access grants include record-level conflict.view, conflict.manage as applicable and activity.view for authorized Timeline visibility. Revocation uses explicit scoped deny; history is not deleted.

## Derived-data privacy

Restricted Conflict Case existence itself is sensitive.

Before rendering/calculating:
- list rows
- counts
- attention badges
- Timeline
- derived summaries

must filter by exact case authorization.

F6E registers conflict_case in the existing authorized Activity target registry. Business Event payloads contain only safe stage/status/version identifiers, never case title/description/allegations/evidence/settlement terms.

Evidence linking requires exact Conflict Case record authorization in addition to records.manage, conflict.manage, same-Business target resolution and Document Manage authorization.

Current F7 Search/Reports/Health/AI surfaces are not introduced by F6E. F6E exposes no unrestricted feed for them. F7 must consume Conflict data only through an authorization-filtered retrieval layer.

Conflict-specific generic notifications carrying sensitive case text are not introduced. Existing Governance/Signature notifications remain protected by their existing target authorization.

## History / immutability

- Current Effective Conflict Procedure is official policy truth.
- Effective/Frozen Conflict Procedure child rows are immutable.
- amendments create new Formal Record Versions.
- a Case preserves the exact Conflict Procedure version active when opened.
- original case source fields are immutable.
- stage/status changes are revision-checked and append-only in case updates.
- Direct Discussion records immutable after recorded.
- completed Mediation immutable; responses preserve party/time.
- decision submissions bind exact case revision + package hash + Frozen Proposal Version.
- F3 Decision preserves exact Authority Snapshot.
- completed Investigation finding immutable.
- Urgent Risk source/authority binding immutable.
- Settlement effective versions immutable; amendment creates new version.
- signed Document Version/hash cannot serve a changed Settlement version.
- referrals/action links/history append-only.
- later Governance/Operations/Conflict Policy changes do not rewrite old cases/decisions/settlements.

PostgreSQL constraints/triggers materially protect tenant/version/history binding.

## UX

Route: /conflict

Business Command Center pattern:
- Needs Your Attention
- Current Conflict Procedure
- Restricted Resolution Register
- case stage/status with confidentiality cue
- contextual case work in drawers/details where quick
- structured full sections for procedure/settlement/formal workflows
- Evidence/Vault links
- Governance links
- Operations Action links
- case History / safe Timeline

No chapter-card/public hero layout.
Draft / Review / Effective states remain visually distinct.
EN / မြန်မာ / Mixed use the same UI architecture.
User-entered case content is not auto-translated.

## CREATE manifest

- app/Domain/Conflict/Enums/ConflictType.php
- app/Domain/Conflict/Enums/ConflictCaseStage.php
- app/Domain/Conflict/Enums/ConflictCaseStatus.php
- app/Domain/Conflict/Enums/DirectDiscussionOutcome.php
- app/Domain/Conflict/Enums/MediationResponseOutcome.php
- app/Domain/Conflict/Enums/ConflictSpecialPathStatus.php
- app/Domain/Conflict/Services/ConflictCaseStateMachine.php
- app/Application/Conflict/ConflictRecordVisibility.php
- app/Application/Conflict/RecordConflictOccurrence.php
- app/Application/Conflict/ConflictPolicyWorkflow.php
- app/Application/Conflict/ConflictCaseWorkflow.php
- app/Application/Conflict/ConflictDirectDiscussionWorkflow.php
- app/Application/Conflict/ConflictMediationWorkflow.php
- app/Application/Conflict/ConflictDecisionWorkflow.php
- app/Application/Conflict/ConflictEscalationWorkflow.php
- app/Application/Conflict/ConflictDeadlockWorkflow.php
- app/Application/Conflict/ConflictInvestigationWorkflow.php
- app/Application/Conflict/ConflictUrgentRiskWorkflow.php
- app/Application/Conflict/ConflictSettlementWorkflow.php
- app/Application/Conflict/ConflictReferralWorkflow.php
- app/Application/Conflict/ConflictReviewWorkflow.php
- app/Application/Conflict/CreateConflictAction.php
- app/Application/Conflict/GetConflictWorkspace.php
- app/Infrastructure/Persistence/Eloquent/Conflict/ConflictPolicyVersion.php
- app/Infrastructure/Persistence/Eloquent/Conflict/ConflictCase.php
- app/Infrastructure/Persistence/Eloquent/Conflict/ConflictMediation.php
- app/Infrastructure/Persistence/Eloquent/Conflict/ConflictInvestigation.php
- app/Infrastructure/Persistence/Eloquent/Conflict/ConflictUrgentRisk.php
- app/Infrastructure/Persistence/Eloquent/Conflict/ConflictSettlementVersion.php
- app/Presentation/Http/Controllers/Conflict/ConflictWorkspaceController.php
- database/migrations/2026_09_27_000047_create_f6e_conflict_tables.php
- resources/js/pages/Conflict/Index.vue
- docs/checkpoints/F6E_CONFLICT_TECHNICAL_SCOPE.md
- tests/Unit/Domain/Conflict/ConflictCaseStateMachineTest.php
- tests/Feature/Conflict/F6EConflictPolicyTest.php
- tests/Feature/Conflict/F6EConflictCaseTest.php
- tests/Feature/Conflict/F6EConflictPrivacyTest.php
- tests/Feature/Conflict/F6EMediationDecisionTest.php
- tests/Feature/Conflict/F6ESettlementTest.php
- tests/Feature/Conflict/F6ESpecialPathsTest.php
- tests/Feature/Conflict/F6EConflictDatabaseInvariantTest.php
- tests/Feature/Conflict/F6EConflictActivityPrivacyTest.php
- tests/E2E/f6e-conflict.spec.ts
- tests/E2E/support/prepare-f6e-e2e.php

## MODIFY manifest

- app/Domain/Access/CapabilityCatalog.php
- app/Domain/Access/StandardAccessProfileMatrix.php
- app/Application/Evidence/EvidenceTargetRegistry.php
- app/Application/Evidence/LinkEvidence.php
- app/Application/Activity/ActivityTargetRegistry.php
- resources/js/components/WorkspaceNavigation.vue
- resources/js/i18n/catalog.ts
- routes/web.php
- tests/Feature/Access/F3StandardAccessProfilesTest.php
- tests/Feature/Evidence/EvidencePrivacyTest.php
- tests/Feature/Activity/ActivityPrivacyTest.php
- .github/workflows/ci.yml

## DELETE manifest

None.

This manifest is a hard ceiling. A listed MODIFY file may remain unchanged. Any genuinely necessary tracked path outside CREATE/MODIFY requires STOP at G1 and explicit owner-approved scope addendum.

## Test matrix

Unit:
- Conflict stage/state machine allowed and denied transitions.
- direct resolution / mediation / formal decision separation.

Domain / Feature:
- Conflict Procedure Formal Record lifecycle, amendment and exact history.
- Procedure captures exact Effective Governance + Operations versions.
- procedure Decision Types must exist in captured Governance version before Effect.
- case cannot open without Current Effective Conflict Procedure.
- case captures exact policy version and source fields cannot be rewritten.
- participants do not imply access.
- explicit restricted access / explicit deny precedence.
- unauthorized users cannot infer case existence from URL/list/count/activity/evidence linking.
- cross-Business case/participant/evidence/action IDs fail closed.
- Direct Discussion immutable and outcome drives only allowed stage transitions.
- Mediation neutrality record + per-party responses; mediation acceptance never creates Approval.
- conflict decision submission binds exact case revision/package hash/Frozen Proposal Version.
- formal decision reuses F3/F6A Governance authority, COI/recusal and Authority Snapshot.
- deadlock record references exact Governance source and cannot manufacture casting-vote authority.
- misconduct restriction is proposal/reference only and does not mutate system access.
- urgent-risk action requiring authority needs exact governed F6A Emergency Authority Grant; scope/time/grantee expansion denied.
- Settlement uses exact Formal Record Version.
- signature-required Settlement needs exact bound Document Version/hash and completed F3 Signature Request.
- signed != Effective.
- changed Settlement needs new version/new binding/new signatures.
- financial settlement terms do not create Finance payment truth.
- Exit/Legal referral does not mutate Ownership/Exit/Transfer truth.
- follow-up Action reuses Current Effective Operations role assignment.
- Review != Approval.
- append-only case updates/findings/referrals/action links.
- PostgreSQL tenant/version/frozen/history constraints.

Derived/privacy:
- Conflict counts filtered before aggregation.
- Activity/Timeline hides restricted case existence and safe payload excludes content.
- Evidence target registry remains closed.
- restricted Conflict Evidence link requires case record authorization.
- no unrestricted Search/Report/Health/AI feed introduced.

Permanent regressions:
- Tenant Isolation
- Authorization
- Effective Record Immutability
- Historical Integrity
- F3 Decision/Approval/Vote/Signature
- F6A authority/delegation/emergency authority
- F6B Operations responsibility
- F6D restricted Evidence/Activity privacy

E2E:
- deterministic authorized Conflict command-center journey:
  Current Procedure -> open restricted case -> direct discussion -> mediation -> governed decision/settlement path -> follow-up Action.
- deterministic unauthorized user cannot observe case row/count.
- no local Chromium on the constrained VPS; GitHub CI is authoritative.

## G1 acceptance

PASS requires:
- actual changed paths are all inside this manifest;
- every declared CREATE path exists before commit;
- no undeclared tracked path;
- no DELETE;
- source reconciliation remains unchanged or an explicit addendum is approved.

## G2 acceptance

PASS requires:
- PHP syntax
- Pint
- Vue SFC parse/compile
- route compile
- CI YAML parse
- PostgreSQL migration/focused suites
- exact-head GitHub typecheck/build/full PostgreSQL/E2E green

## G3 acceptance

PASS requires all F6E domain invariants above.

## G4 acceptance

PASS requires:
- default-deny tenant/access behavior
- restricted-existence protection
- Evidence/Document separation
- Governance authority separation
- no system/ownership shortcut
- immutable/versioned official truth
- exact Decision/Authority/Signature bindings
- append-only safe audit/history
- permanent regressions green

## Human-G5 Pending Ledger

- F5 Boss UAT 4: DEFERRED / NOT PASS
- F6A/F6B Boss UAT 5: DEFERRED / NOT PASS
- F6C Boss UAT 6: DEFERRED / NOT PASS
- F6D Boss UAT: DEFERRED / NOT PASS
- F6E Boss UAT 7: DEFERRED / NOT PASS until explicitly completed by human
- no F5/F6 G6 tag/freeze while required G5 remains deferred
- Preview remains accepted F4
- Production untouched
- Public Website unchanged
- PartnerDynamics protected/unchanged
