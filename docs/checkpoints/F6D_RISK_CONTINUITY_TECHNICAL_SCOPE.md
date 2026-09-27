# F6D — Risk & Continuity Technical Scope Freeze

Baseline HEAD: f7aeed22ed07e787c22ad3055ed3d0b471067113
Baseline tree: 4d32fd48133cdae4056ef703df99e8a951cc585d
Branch: feature/f6-governance-operations
Baseline CI: Run #53 / 36316536380 / SUCCESS
Status: architecture/source reconciliation complete; implementation not yet started.

## Source reconciliation

Authority precedence used:

1. `01_thePBR_OS_MASTER_SPEC_v1.1.md`
2. `PBR_MASTER_SOURCE_COMPLETE_2026.pdf`
3. `02_SOURCE_TRACEABILITY_MAP.md`
4. `03_DEVELOPMENT_ROADMAP_AND_GATES.md`
5. Accepted F2/F3/F5/F6A/F6B/F6C implementation contracts at the baseline HEAD.

No genuine source conflict was found.

The Master Spec makes Risk, Incident, Insurance/Protection Record, Continuity Plan, Critical Function, Emergency Access Record, Successor Candidate and Continuity Test explicit domain entities. It establishes Active Risk Register as canonical current Risk truth and Current Effective Continuity Plan as canonical Continuity truth. Governance authority remains Current Effective Governance / Formation Authority plus exact Authority Snapshot; operational delivery remains Current Effective Role Assignment.

The PBR source requires:
- risk category, owner, likelihood 1–5, impact 1–5, warning indicator, mitigation, response and review;
- configurable risk-level thresholds rather than one universal hard-coded threshold;
- insurance/protection, IP/brand/confidentiality/data/system-access protection and misconduct controls;
- incident/loss records and protection/control tests;
- critical functions, maximum downtime, primary/backup owners, recovery priority/resources;
- Backup and Successor to remain separate;
- emergency access to store only secure procedures/references, never plaintext credentials;
- interim authority to connect to Governance and stay within approved decision/spending limits;
- successor readiness, communication, continuity actions and actual continuity tests.

The Source Traceability Map preserves the cross-domain chain:
Risk -> Continuity -> Exit/Conflict.
Business events may trigger downstream reviews but never silently rewrite approved records.

Historical PDF instructions to keep an old chapter UI do not override the Master Spec V3 Business Command Center UX.

## Purpose and boundaries

F6D adds a private Business Risk & Continuity operating surface. It does not create a second Governance, Approval, Evidence, Document, Action, Review, permission, audit or notification engine.

Governance determines who may decide.
Operations determines who must deliver.
Risk identifies/protects/treats threats.
Continuity defines how critical delivery continues during disruption.

F6D does not implement Exit/Buyout, Transfer/New Partner, Conflict cases, Search, Reports, AI or Production backup/restore. It may store references/triggers for those later domains without implementing their lifecycle.

## Canonical source ownership

- Current Risks: current Effective `risk_register` Formal Record Version.
- Risk scoring thresholds: exact Risk Register Version.
- Risk owner/responsibility: Risk row bound to exact Effective Operations Register role/version where operational accountability is used.
- Protection/insurance arrangement: exact Risk Register Version child record.
- Incident occurrence/history: `risk_incidents` + append-only `risk_incident_updates`, bound to exact Risk Register Version when opened.
- Current Continuity setup: current Effective `continuity_plan` Formal Record Version.
- Critical function/backup coverage: exact Continuity Plan Version.
- Backup: temporary replacement only.
- Successor: long-term replacement candidate only.
- Interim Governance authority: existing F6A governed Emergency Authority Grant only; Continuity never manufactures Governance authority.
- Emergency system/business access activation: exact Continuity Plan/Emergency Access Record + actor/expiry + optional exact authorized F6A Emergency Authority Grant.
- Evidence: existing F2 Evidence records/links.
- Documents: existing private Document Vault + Document authorization.
- Follow-up work: existing F3 Action lifecycle, linked to exact F6D source.
- Audit/Business Events: existing append-only occurrence mechanism.

## Relational data model

### Risk

`risk_register_versions`
- one row per Formal Record Version
- configurable score thresholds
- Risk/Protection owner
- review cadence/notes

`risk_items`
- exact Risk Register Version
- category
- title/description
- exact Operations Version/Role where applicable
- owner Membership where applicable
- likelihood 1–5
- impact 1–5
- derived score
- derived level from version thresholds
- warning indicator
- mitigation
- response
- review date
- status
- confidentiality (`standard|restricted`)

`risk_protection_records`
- exact Risk Register Version + optional Risk
- protection type (`insurance|ip_brand|confidentiality_data|system_access|misconduct|other`)
- covered risk/asset
- provider/reference/coverage/deductible/exclusions/premium/date fields where relevant
- owner
- access/protection/confidentiality requirements
- review date/status
- confidentiality
- secret-like payload fields prohibited

`risk_incidents`
- exact Business + Risk Register Version + optional Risk
- incident date/type/description/impact/loss amount
- immediate action
- status/revision
- confidentiality
- opened by Membership
- no silent cross-version/cross-tenant mutation

`risk_incident_updates`
- append-only incident chronology
- status/root cause/corrective action/note
- actor/time
- never rewrites prior update history

`risk_control_tests`
- exact Risk Register Version + optional Risk/Protection
- test/control/scenario/date
- result/gap/corrective action
- responsible owner
- next test/review
- completed rows immutable
- confidentiality

### Continuity

`continuity_plan_versions`
- one row per Formal Record Version
- exact Operations Formal Record Version
- continuity owner
- review/test cadence
- governance decision type
- notes

`continuity_critical_functions`
- exact Continuity Plan Version
- function/process
- maximum downtime
- exact Operations role/version
- primary/first backup/second backup Memberships
- recovery priority/minimum resources
- review/status

`continuity_emergency_access_records`
- exact Continuity Plan Version
- system/asset
- primary/backup Memberships
- access level/procedure/reference/removal trigger
- last tested/review date/status
- restricted by default
- never stores plaintext credential material

`continuity_interim_authority_plans`
- exact Continuity Plan Version
- critical role/interim Membership/trigger
- exact Governance decision type
- spending/decision limits
- maximum interim period/reporting requirement
- planning/control record only; grants no authority

`continuity_successor_candidates`
- exact Continuity Plan Version
- role/current owner/candidate
- readiness/skills gap/development/target ready date/status
- separate from backup
- ownership succession is reference-only and never mutates Ownership

`continuity_communication_steps`
- exact Continuity Plan Version
- event/stakeholder/owner/channel/timing/message reference/approval-required marker
- marker indicates that Governance may be required; it is not an Approval boolean

`continuity_recovery_actions`
- exact Continuity Plan Version
- timeline band (`0_24_hours|1_7_days|7_30_days`)
- action/owner role/required resource/sequence

`continuity_tests`
- exact Continuity Plan Version
- scenario/date/result/failed items/improvements/owner/next test date
- completed rows immutable

`continuity_emergency_access_activations`
- exact Continuity Plan Version + Emergency Access Record
- activated by Membership
- trigger/reason/start/expiry/status
- optional exact existing governed F6A `EmergencyAuthorityGrant`
- activation never creates PermissionGrant, AccessPolicy, Governance Decision, Approval, Vote or Signature
- expiry/revocation is explicit and audited

`risk_continuity_action_links`
- links existing F3 `actions` to exact Risk/Incident/Test/Continuity/Test/Activation source
- action assignment must still satisfy current Effective Operations responsibility.

## Workflows / state machines

### Risk Register official record
Draft
-> Ready for Review
-> Under Review
-> Approved
-> Governed Decision/Approval/Signature when required
-> Ready for Effect
-> Effective
-> later Amendment -> New Version

Effective snapshot rows are immutable.

### Incident
Open
-> Investigating
-> Contained
-> Corrective Action
-> Resolved
-> Closed

Every meaningful change appends `risk_incident_updates`; closed incidents cannot be silently reopened/overwritten.

### Control / Continuity Test
Planned
-> Running
-> Completed

Completed result is immutable. Any later retest is a new Test row.

### Continuity Plan
Same Formal Record lifecycle as Risk Register; amendment creates a new version.

### Emergency Access Activation
Requested
-> Active
-> Expired / Revoked / Closed

Activation must be time-limited. When Governance authority is necessary, an exact currently-authorized F6A Emergency Authority Grant with compatible Business, grantee, scope/decision type and time window is mandatory. System permission alone never creates that authority.

## Authority and permissions

New system capabilities:
- `risk.view`
- `risk.manage`
- `continuity.view`
- `continuity.manage`

These are system capabilities only. They never establish Governance authority, Ownership rights or Document rights.

Default deny:
- active Membership + current Business + capability are required;
- resource Business must match;
- restricted rows additionally require existing resource-level AccessPolicy/RecordAccessRule authorization;
- scoped deny wins;
- restricted rows are excluded before list/count/attention computation.

Governance-required F6D actions reuse F3/F6A Proposal/Decision/Approval/Vote/Signature/Authority Snapshot machinery.

Emergency access does not create permanent or unrestricted authority. Continuity activation may reference a governed Emergency Authority Grant but may not broaden it.

## Restricted/confidentiality rules

- Risk/Protection/Incident/Test rows may be `restricted`.
- Emergency Access Records and Emergency Access Activations are restricted by default.
- Restricted records require explicit record-level authorization.
- Unauthorized users must not receive their title, row, count, attention signal, derived notification text or timeline content.
- Audit/event metadata for restricted records uses opaque IDs/status only; it never embeds sensitive description/content.
- Evidence/Document access remains separately authorized even when the F6D target record is visible.

## History / immutability

- Formal Effective versions are immutable.
- Amendment creates a new version.
- Frozen snapshot children cannot be changed/deleted.
- completed Tests immutable.
- closed incident history remains append-only.
- Emergency activation history remains preserved after expiry/revocation.
- exact Operations/Governance/source-version bindings remain historical and are not re-resolved to new current rows.

PostgreSQL constraints/triggers protect tenant/version binding, history and frozen/effective child mutation.

## UX

Private Business Command Center only:
- `/risk`: Needs Attention, Risk Register, protection/insurance, incidents, control tests, history.
- `/continuity`: Needs Attention, current Effective plan, critical functions, backup coverage, emergency access, interim authority plan, successors, communication/recovery plan, continuity tests, history.
- tables/registers and contextual details; formal record creation/review uses full-page/structured sections.
- Draft/Review/Effective statuses visibly distinct.
- EN / မြန်မာ / Mixed use one architecture.
- no public-site hero/chapter-card layout.

## CREATE manifest

- app/Domain/Risk/Enums/IncidentStatus.php
- app/Domain/Risk/Enums/RiskControlTestResult.php
- app/Domain/Risk/Services/RiskScorer.php
- app/Domain/Continuity/Enums/ContinuityTestResult.php
- app/Domain/Continuity/Enums/EmergencyAccessActivationStatus.php
- app/Application/Risk/RiskRegisterWorkflow.php
- app/Application/Risk/RiskIncidentWorkflow.php
- app/Application/Risk/RiskControlTestWorkflow.php
- app/Application/Risk/GetRiskWorkspace.php
- app/Application/Risk/RiskRecordVisibility.php
- app/Application/Continuity/ContinuityPlanWorkflow.php
- app/Application/Continuity/ContinuityTestWorkflow.php
- app/Application/Continuity/EmergencyAccessWorkflow.php
- app/Application/Continuity/GetContinuityWorkspace.php
- app/Application/Continuity/ContinuityRecordVisibility.php
- app/Application/Continuity/CreateRiskContinuityAction.php
- app/Infrastructure/Persistence/Eloquent/Risk/RiskItem.php
- app/Infrastructure/Persistence/Eloquent/Risk/RiskProtectionRecord.php
- app/Infrastructure/Persistence/Eloquent/Risk/RiskIncident.php
- app/Infrastructure/Persistence/Eloquent/Risk/RiskControlTest.php
- app/Infrastructure/Persistence/Eloquent/Continuity/ContinuityTest.php
- app/Infrastructure/Persistence/Eloquent/Continuity/ContinuityEmergencyAccessActivation.php
- app/Presentation/Http/Controllers/Risk/RiskWorkspaceController.php
- app/Presentation/Http/Controllers/Continuity/ContinuityWorkspaceController.php
- database/migrations/2026_09_27_000045_create_f6d_risk_tables.php
- database/migrations/2026_09_27_000046_create_f6d_continuity_tables.php
- resources/js/pages/Risk/Index.vue
- resources/js/pages/Continuity/Index.vue
- docs/checkpoints/F6D_RISK_CONTINUITY_TECHNICAL_SCOPE.md
- tests/Unit/Domain/Risk/RiskScorerTest.php
- tests/Feature/Risk/F6DRiskRegisterTest.php
- tests/Feature/Risk/F6DRiskIncidentTest.php
- tests/Feature/Risk/F6DRiskTenantPermissionTest.php
- tests/Feature/Risk/F6DRiskDatabaseInvariantTest.php
- tests/Feature/Continuity/F6DContinuityPlanTest.php
- tests/Feature/Continuity/F6DEmergencyAccessTest.php
- tests/Feature/Continuity/F6DContinuityTestWorkflowTest.php
- tests/Feature/Continuity/F6DContinuityTenantPermissionTest.php
- tests/Feature/Continuity/F6DContinuityDatabaseInvariantTest.php
- tests/E2E/f6d-risk-continuity.spec.ts
- tests/E2E/support/prepare-f6d-e2e.php

## MODIFY manifest

- app/Domain/Access/CapabilityCatalog.php
- app/Domain/Access/StandardAccessProfileMatrix.php
- app/Application/Evidence/EvidenceTargetRegistry.php
- app/Application/Evidence/LinkEvidence.php
- resources/js/components/WorkspaceNavigation.vue
- resources/js/i18n/catalog.ts
- routes/web.php
- tests/Feature/Access/F3StandardAccessProfilesTest.php
- tests/Feature/Evidence/EvidencePrivacyTest.php
- .github/workflows/ci.yml

## DELETE manifest

None.

The CREATE/MODIFY manifest is a hard ceiling. A listed MODIFY support file may remain unchanged. Any new tracked path outside this ceiling requires STOP at G1 and explicit owner-approved scope addendum.

G1 addendum — 2026-09-27: the owner explicitly approved adding only `app/Application/Evidence/LinkEvidence.php` to MODIFY scope to close the verified F6D restricted-target Evidence-link authorization/existence-oracle gap. The fix must reuse the existing F2 record authorization engine and may not broaden access.

## Test matrix

Unit:
- configurable Risk score/level calculation and threshold validation.

Domain/Feature:
- Risk Register Formal Record lifecycle and amendment/version history.
- Risk score uses exact version thresholds.
- Risk owner/role references exact same-Business Effective Operations source.
- insurance/protection/system-access payload rejects secret-like fields.
- incident lifecycle + append-only updates.
- completed Risk control tests immutable.
- Continuity Plan Formal Record lifecycle/amendment/history.
- Backup != Successor.
- critical function role/backup references same-Business exact Operations version.
- Emergency Access records restricted-by-default and never store secrets.
- interim authority plan does not grant Governance/System access.
- emergency activation is time-limited and cannot create PermissionGrant/AccessPolicy.
- activation requiring Governance authority binds exact governed F6A Emergency Authority Grant and cannot expand it.
- continuity tests bind exact plan version and completed tests are immutable.
- restricted Risk/Continuity record existence/counts fail closed.
- cross-Business IDs/evidence/access rows fail closed.
- Evidence target registry remains closed.
- Actions reuse existing Action + Operations responsibility.
- effective child snapshots immutable at PostgreSQL layer.
- historical exact source bindings remain unchanged after later Operations/Governance versions.

Regression:
- Tenant Isolation
- Authorization
- Effective Record Immutability
- Historical Integrity
- F3 Governance critical behavior/signature/evidence
- F6A delegation/emergency authority
- F6B Operations role/action behavior
- F6C Finance/Rewards permanent controls
- closed Evidence target registry

E2E:
- deterministic desktop Chromium journey verifies Risk and Continuity command centers, no secret fields, Backup/Successor separation, restricted/emergency access safety notice, and no frontend Governance shortcut.
- GitHub Actions is authoritative browser CI; no local Chromium.

## Human-G5 Pending Ledger

- F5 Boss UAT 4: DEFERRED / NOT PASS.
- F6A/F6B Boss UAT 5: DEFERRED / NOT PASS.
- F6C Boss UAT 6: DEFERRED / NOT PASS.
- F6D has no separate Boss UAT in the authoritative roadmap; F6E completes the Risk/Continuity/Conflict group before Boss UAT 7.
- No F5/F6 milestone G6 tag/freeze while required Human G5 remains deferred.

## G1 acceptance

PASS requires:
- all changed tracked paths are inside this frozen CREATE/MODIFY ceiling;
- no DELETE path;
- no Public Website, PartnerDynamics, Preview or Production mutation;
- no duplicate Governance/Approval/permission/evidence engine.

## G2 acceptance

PASS requires syntax/style/static/route/Vue checks and authoritative CI frontend typecheck/build.

## G3 acceptance

PASS requires focused F6D PostgreSQL/domain tests and exact workflow/history semantics.

## G4 acceptance

PASS requires default-deny tenant/resource access, restricted-existence protection, immutable effective history, emergency-authority boundary, permanent regressions and exact-head CI security/backend/browser success.
