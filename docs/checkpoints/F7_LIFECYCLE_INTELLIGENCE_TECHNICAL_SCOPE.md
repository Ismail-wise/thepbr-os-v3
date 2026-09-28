# F7 Lifecycle, Intelligence & Portability — Technical Scope Freeze

Status: FROZEN before feature implementation
Date: 2026-09-28
Branch: feature/f7-lifecycle-intelligence
Baseline branch: feature/f6-governance-operations
Baseline HEAD: 0a283d9d12998df2ff67cdb5e77c3adf5af27332
Baseline tree: 4bfe9aa8027decc86c2fec640b78526a9f4e3bbe
Baseline CI: #57 / 36369498976 SUCCESS
Human G5: DEFERRED / NOT PASS where listed below

## Source reconciliation

Authority precedence was applied exactly:
1. 01_thePBR_OS_MASTER_SPEC_v1.1.md
2. PBR_MASTER_SOURCE_COMPLETE_2026.pdf
3. current PartnerDynamics implementation only for its protected experience
4. Existing Public Website protected/unchanged
5. historical portal material only as non-authoritative reference

02_SOURCE_TRACEABILITY_MAP.md, 03_DEVELOPMENT_ROADMAP_AND_GATES.md,
the live repository and recovery checkpoint were reconciled as supporting evidence.
No genuine source-precedence conflict was found.
Historical PBR instructions to preserve old chapter UI do not override the V3 Master Spec.
## Accelerated gate model

Technical implementation may continue while required Human G5 is explicitly
DEFERRED / NOT PASS only when the consumed upstream dependency has:
- G1 Scope PASS
- G2 Technical PASS
- G3 Domain PASS
- G4 Security & Integrity PASS
- exact-head CI PASS
- permanent regressions green
- stable callable contracts
- no unresolved security/integrity/foundation failure

Deferred Human G5 is never represented as PASS.
No deferred milestone receives G6 Commit + Annotated Tag + Freeze.
Formal F7 closure still requires actual Boss UAT 8 / Human G5 PASS, then G6.

## Live entry reconciliation

Verified before this freeze:
- exact F6 branch/HEAD/tree/remote matched the accepted checkpoint;
- worktree and index were clean;
- no tags existed at the F6 technical HEAD;
- GitHub CI #57 was SUCCESS at the exact F6 HEAD;
- all four required CI jobs passed;
- migrations currently end at 2026_09_27_000047_create_f6e_conflict_tables.php;
- no local or remote F7 branch existed before this branch was created;
- no competing repository writer process was detected.
## F7 mission and batch order

F7 = Lifecycle, Intelligence & Portability.

1. F7-1 Partner Changes — New Partner / Admission + Share Transfer
2. F7-2 Exit / Buyout
3. F7-3 Closure / Dissolution
4. F7-4 Permission-aware Global Search
5. F7-5 Business Health / Readiness
6. F7-6 Reports / Business Pack
7. F7-7 Import staging / validation / reconciliation / confirm
8. F7-8 Archive / Portability / Business Export
9. F7-9 PBR AI
10. F7-10 whole-lifecycle technical verification / Boss UAT 8 readiness

R1 Production-readiness work is excluded.

## Canonical boundaries

- PartnerChanges owns transfer/admission case process, not Partner/DD/Contribution/Ownership authority.
- Exit owns exit/buyout case process, not Membership/Ownership/Operations/Finance authority.
- Closure owns dissolution/wind-down case process and closure-specific claim inventory.
- Search, Health, Reports and AI are derived and never canonical.
- Import is staged observation until confirmed through authorized domain use cases.
- Archive is a workspace lifecycle state, not deletion and not Closure.
- Generated files are representations of structured canonical records.
## Canonical source ownership

- access: Membership + F2 permission/access policy
- partner identity/lifecycle: Partner + append-only partner lifecycle transitions
- DD: F5 Due Diligence
- contributions: F5 Contribution; only Accepted value may feed Ownership
- current ownership: Current Effective Ownership Register Version
- governance authority: Current Effective Governance Charter + exact Authority Snapshot
- operations responsibility: Current Effective Operations Register / role assignment
- finance/payment truth: F6C Finance
- rewards/distributions: F6C Rewards
- risk: F6D Risk
- continuity: F6D Continuity
- conflict: restricted F6E Conflict Case / effective settlement truth
- transfer/admission: F7 Partner Change Case + exact governed formal record
- exit/buyout: F7 Exit Case + exact governed formal record
- closure: F7 Closure Case + exact governed formal record
- documents/evidence: F2 Document Version / Evidence
- history: append-only Audit/Business Events + immutable versions/snapshots
- Search/Health/AI indexes: rebuildable derived projections only

## F7-1 Partner lifecycle decision

Master Spec lifecycle is authoritative:
Proposed -> Due Diligence -> Admission Pending -> Active -> Exiting -> Former.
Existing F5 'prospective' remains the stored compatibility label for Proposed.
F7 adds due_diligence, admission_pending, exiting and former.
Existing inactive remains available for non-exit administrative inactivity.
Partner lifecycle status never implies Membership access, Governance authority or Ownership.
Every F7 status transition is append-only recorded.
## F7-1 Transfer / New Partner invariants

- Transfer simulation never mutates live Ownership.
- A Partner Change Case captures the exact current Effective Ownership Register baseline.
- eligibility includes vesting/restriction/lock-up, obligations, pledge/lien,
  agreement conflict and buyer eligibility.
- ROFR is explicit when required and preserves offers/responses/deadlines.
- Governance reuses F3/F6A Decision/Approval/Vote/Authority Snapshot machinery.
- New-partner admission reuses F5 DD and Contribution terms where applicable.
- Transfer-existing-shares and issue-new-shares are distinct.
- voting rights and profit rights remain explicit and separate.
- legal completion and onboarding are explicit pre-effect requirements.
- effectivity is transaction-safe and creates a new Ownership Register Version.
- PartnerChanges never stores a competing canonical ownership percentage.
- Membership/system access onboarding is separate from admission/ownership.

Ownership mutation is delegated to a Partnership-owned application use case.
PartnerChanges cannot update ownership tables outside that contract.

## F7-2 Exit / Buyout invariants

- opening Exit does not revoke Ownership, access or historical authority.
- Partner transitions to Exiting only through the controlled exit lifecycle.
- share treatment/buyout routes through PartnerChanges/Ownership truth.
- buyout payments reuse F6C Finance controls and payment records.
- Operations handover is checked against Current Effective Operations state.
- Continuity obligations reuse F6D; conflict/misconduct/deadlock reuse F6E/F6A.
- system access transition is a separate Membership lifecycle operation.
- Membership access gains suspended/revoked states with append-only history.
- last active Workspace Owner protection is mandatory before suspension/revocation.
- Former Partner requires effective share treatment plus required access/handover completion.
- Former status preserves historic votes, signatures, evidence and authority snapshots.
- approved installment obligations may remain after the effective exit date.
- Exit completion and financial settlement completion are distinct when terms require it.

## F7-3 Closure invariants

- Closure != Archive.
- Closure does not delete Business records/files/history.
- closure authority comes from F6A Governance; no module-local authority engine.
- Operations, Finance, Risk/Continuity and applicable Conflict obligations are reconciled.
- closure-specific claims/liabilities are a wind-down inventory, not a replacement Finance ledger.
- each claim may reference source Finance/F4/legal/evidence records.
- no universal statutory creditor priority is encoded.
- legal priority is recorded as jurisdiction-specific reference/evidence.
- residual distribution is blocked until required claims are settled/waived.
- Finance payments remain canonical payment truth.
- legal closure and workspace Closed state are distinct controlled steps.
- workspace Closed is not the same as Archived.
## F7-4 Search privacy architecture

Search starts PostgreSQL-backed.
search_index_entries are derived, rebuildable and Business-scoped.
Index rows never become canonical truth.

Every result is filtered against the requesting User, active Membership,
current Business, capability and source-module visibility before presentation.
Restricted F6E Conflict records require ConflictRecordVisibility and never fall back
to generic visibility.

Search must not leak restricted:
- existence
- title
- count/facet
- suggestion/autocomplete
- snippet
- filename
- actor metadata

Authorization is re-evaluated at read time even when an index row predates a permission change.
No global cross-Business index query is allowed.

## F7-5 Business Health architecture

Health is deterministic and explainable derived state.
No opaque AI health score is canonical.
Rules return Met / Warning / Blocked / Unknown with safe reason,
authorized source/version, last verified time and safe next action.
Hidden records cannot influence a visible signal in a way that reveals existence.
## F7-6 Reports / Business Pack architecture

Business Pack is generated representation, not canonical truth.
A generation request freezes an authorized manifest containing:
Business, requester, output language, requested scope,
exact source record/document version IDs and hashes,
as-of/effective dates and explicit exclusions.

Pipeline:
request -> authorize -> freeze manifest -> generate -> verify -> available.
Download performs authorization again.
Generated files are private and use existing private object storage.
No "include everything" bypass exists.
User-entered data is not auto-translated.

## F7-7 Import architecture

Import Batch -> Parse -> Imported Records -> Validation Results
-> Review/Reconcile -> Confirm -> canonical domain use cases.

Imported/AI-extracted values remain observed/proposed until confirmation.
Source fingerprint, source record key and parser/schema version are preserved.
Idempotency scope:
Business + source type/system + source fingerprint + source record key + intended target.
A collision with Effective truth never overwrites it.
Confirmation is per selected record with explicit result; no silent partial success.
## F7-8 Archive / Portability architecture

Archive is reversible only through an explicit authorized unarchive operation.
Archive never means delete and never means legal dissolution.
Closed Businesses cannot be "unclosed" by Archive operations.

Archive/restore preserves canonical/effective/superseded records,
Audit, Business Events, authority snapshots, votes, approvals, signatures,
Document Versions, Evidence and historical Membership/Partner/Role/Ownership records.

Portability export is self-describing and permission-filtered.
Manifest includes schema version, Business ID, generated timestamp,
requester, included/excluded categories and exact record/document IDs/hashes.
Secrets, password material, raw provider secrets and inaccessible restricted data are excluded.

## F7-9 PBR AI architecture

AI receives only User + current Business + authorization-filtered minimal context.
Domain/application code depends on a PbrAiProvider interface.
Provider configuration is external; no provider secret is stored as business data.
AI may explain, compare, summarize and draft.
AI may not approve, vote, sign, admit a partner, change Ownership,
issue payment, revoke rights, archive/close a Business or create Effective truth.
Any suggested change must enter an explicit authorized application workflow.
AI retrieval reuses permission-aware Search and direct authorized canonical reads.
Restricted-domain retrieval may be disabled by Business policy/configuration.
AI responses may not leak hidden existence through refusal wording, counts or citations.
No unrestricted SQL/database agent is introduced.

## Transaction / consistency boundaries

Strong transaction boundaries are required for:
- Partner Change effect + Ownership Register activation + partner lifecycle transition;
- Exit transitions that change Partner/Membership state;
- Closure legal-effect transition + Business workspace Closed state;
- Import confirmation per canonical target;
- Archive/restore workspace-state transition.

Optimistic revision/locking is required on mutable case/batch/export projections.
Critical canonical truth uses strong consistency.
Search/Health/AI projections may be eventually consistent but must authorize at read time.

## Queue / idempotency boundaries

Queues are used for report generation, portability export, import parse/validation,
search projection rebuilds and long AI work where configured.
Jobs must be retry-safe and idempotent.
Synchronous Test/CI queue execution is acceptable.
No queue job may infer Business context from ambient session state;
Business and authorized target identifiers are explicit job input.
## F7 migration allocation

The live sequence ends at 000047, therefore F7 allocates:
- 2026_09_28_000048_create_f7_partner_change_tables.php
- 2026_09_28_000049_create_f7_exit_tables.php
- 2026_09_28_000050_create_f7_closure_tables.php
- 2026_09_28_000051_create_f7_search_tables.php
- 2026_09_28_000052_create_f7_reporting_tables.php
- 2026_09_28_000053_create_f7_import_tables.php
- 2026_09_28_000054_create_f7_portability_tables.php

F7 Health and PBR AI add no canonical database tables.
AI retrieval uses authorized Search/read services; no conversation-retention table is introduced.

## CREATE manifest — F7-0 / F7-1

- docs/checkpoints/F7_LIFECYCLE_INTELLIGENCE_TECHNICAL_SCOPE.md
- app/Domain/PartnerChanges/Enums/PartnerChangeStatus.php
- app/Domain/PartnerChanges/Enums/PartnerChangeTransactionType.php
- app/Domain/PartnerChanges/Enums/PartnerChangeEligibilityStatus.php
- app/Domain/PartnerChanges/Enums/RofrResponseStatus.php
- app/Domain/PartnerChanges/Services/PartnerChangeStateMachine.php
- app/Application/PartnerChanges/RecordPartnerChangeOccurrence.php
- app/Application/PartnerChanges/PartnerChangeWorkflow.php
- app/Application/PartnerChanges/GetPartnerChangesWorkspace.php
- app/Application/Partnership/PartnerLifecycleWorkflow.php
- app/Application/Partnership/OwnershipTransferWorkflow.php
- app/Infrastructure/Persistence/Eloquent/PartnerChanges/PartnerChangeCase.php
- app/Presentation/Http/Controllers/PartnerChanges/PartnerChangesWorkspaceController.php
- database/migrations/2026_09_28_000048_create_f7_partner_change_tables.php
- resources/js/pages/Changes/PartnerChanges.vue
- tests/Unit/Domain/PartnerChanges/PartnerChangeStateMachineTest.php
- tests/Feature/PartnerChanges/F7PartnerChangeWorkflowTest.php
- tests/Feature/PartnerChanges/F7PartnerChangeSecurityTest.php
- tests/Feature/PartnerChanges/F7OwnershipTransferIntegrityTest.php

## CREATE manifest — F7-2

- app/Domain/Exit/Enums/ExitCaseStatus.php
- app/Domain/Exit/Enums/ExitTrigger.php
- app/Domain/Exit/Enums/LeaverClassification.php
- app/Domain/Exit/Services/ExitCaseStateMachine.php
- app/Application/Exit/RecordExitOccurrence.php
- app/Application/Exit/ExitCaseWorkflow.php
- app/Application/Exit/GetExitWorkspace.php
- app/Application/Access/TransitionMembershipAccess.php
- app/Infrastructure/Persistence/Eloquent/Exit/ExitCase.php
- app/Presentation/Http/Controllers/Exit/ExitWorkspaceController.php
- database/migrations/2026_09_28_000049_create_f7_exit_tables.php
- resources/js/pages/Changes/Exit.vue
- tests/Unit/Domain/Exit/ExitCaseStateMachineTest.php
- tests/Feature/Exit/F7ExitWorkflowTest.php
- tests/Feature/Exit/F7ExitAccessTransitionTest.php
- tests/Feature/Exit/F7ExitHistoryIntegrityTest.php

## CREATE manifest — F7-3

- app/Domain/Closure/Enums/ClosureCaseStatus.php
- app/Domain/Closure/Enums/ClosureClaimStatus.php
- app/Domain/Closure/Services/ClosureCaseStateMachine.php
- app/Application/Closure/RecordClosureOccurrence.php
- app/Application/Closure/ClosureWorkflow.php
- app/Application/Closure/GetClosureWorkspace.php
- app/Infrastructure/Persistence/Eloquent/Closure/ClosureCase.php
- app/Presentation/Http/Controllers/Closure/ClosureWorkspaceController.php
- database/migrations/2026_09_28_000050_create_f7_closure_tables.php
- resources/js/pages/Changes/Closure.vue
- tests/Unit/Domain/Closure/ClosureCaseStateMachineTest.php
- tests/Feature/Closure/F7ClosureWorkflowTest.php
- tests/Feature/Closure/F7ClosureSecurityTest.php
- tests/Feature/Closure/F7ClosureHistoryIntegrityTest.php

## CREATE manifest — F7-4

- app/Application/Search/GlobalSearch.php
- app/Application/Search/SearchIndexProjector.php
- app/Application/Search/SearchVisibility.php
- app/Infrastructure/Persistence/Eloquent/Search/SearchIndexEntry.php
- app/Presentation/Http/Controllers/Search/SearchController.php
- database/migrations/2026_09_28_000051_create_f7_search_tables.php
- resources/js/pages/Search/Index.vue
- tests/Feature/Search/F7GlobalSearchTest.php
- tests/Feature/Search/F7SearchPrivacyTest.php

## CREATE manifest — F7-5

- app/Domain/Health/Enums/HealthRequirementState.php
- app/Domain/Health/ValueObjects/HealthRequirement.php
- app/Application/Health/HealthRuleCatalog.php
- app/Application/Health/GetBusinessHealth.php
- app/Presentation/Http/Controllers/Health/HealthController.php
- resources/js/pages/Health/Index.vue
- tests/Unit/Domain/Health/HealthRequirementTest.php
- tests/Feature/Health/F7BusinessHealthTest.php
- tests/Feature/Health/F7HealthPrivacyTest.php

## CREATE manifest — F7-6

- app/Domain/Reporting/Enums/BusinessPackStatus.php
- app/Application/Reporting/CreateBusinessPack.php
- app/Application/Reporting/GenerateBusinessPack.php
- app/Application/Reporting/AuthorizeBusinessPackDownload.php
- app/Application/Reporting/GetReportsWorkspace.php
- app/Infrastructure/Persistence/Eloquent/Reporting/BusinessPackExport.php
- app/Infrastructure/Reporting/BusinessPackRenderer.php
- app/Presentation/Http/Controllers/Reporting/ReportsController.php
- database/migrations/2026_09_28_000052_create_f7_reporting_tables.php
- resources/js/pages/Reports/Index.vue
- tests/Feature/Reporting/F7BusinessPackTest.php
- tests/Feature/Reporting/F7BusinessPackPrivacyTest.php
- tests/Feature/Reporting/F7BusinessPackExportTest.php

## CREATE manifest — F7-7

- app/Domain/Import/Enums/ImportBatchStatus.php
- app/Domain/Import/Enums/ImportedRecordStatus.php
- app/Application/Import/CreateImportBatch.php
- app/Application/Import/ParseImportBatch.php
- app/Application/Import/ValidateImportBatch.php
- app/Application/Import/ConfirmImportRecords.php
- app/Application/Import/GetImportWorkspace.php
- app/Infrastructure/Import/ImportParser.php
- app/Infrastructure/Import/CsvImportParser.php
- app/Infrastructure/Import/JsonImportParser.php
- app/Infrastructure/Persistence/Eloquent/Import/ImportBatch.php
- app/Infrastructure/Persistence/Eloquent/Import/ImportedRecord.php
- app/Infrastructure/Persistence/Eloquent/Import/ImportValidationResult.php
- app/Presentation/Http/Controllers/Import/ImportController.php
- database/migrations/2026_09_28_000053_create_f7_import_tables.php
- resources/js/pages/Import/Index.vue
- tests/Feature/Import/F7ImportStagingTest.php
- tests/Feature/Import/F7ImportIdempotencyTest.php
- tests/Feature/Import/F7ImportConfirmationTest.php
- tests/Feature/Import/F7ImportSecurityTest.php

## CREATE manifest — F7-8

- app/Domain/Portability/Enums/BusinessExportStatus.php
- app/Application/Portability/ChangeWorkspaceArchiveState.php
- app/Application/Portability/CreateBusinessExport.php
- app/Application/Portability/GenerateBusinessExport.php
- app/Application/Portability/AuthorizeBusinessExportDownload.php
- app/Application/Portability/GetPortabilityWorkspace.php
- app/Infrastructure/Persistence/Eloquent/Portability/BusinessArchiveTransition.php
- app/Infrastructure/Persistence/Eloquent/Portability/BusinessPortabilityExport.php
- app/Infrastructure/Portability/BusinessExportRenderer.php
- app/Presentation/Http/Controllers/Portability/PortabilityController.php
- database/migrations/2026_09_28_000054_create_f7_portability_tables.php
- resources/js/pages/Records/Portability.vue
- tests/Feature/Portability/F7ArchiveWorkflowTest.php
- tests/Feature/Portability/F7BusinessExportTest.php
- tests/Feature/Portability/F7PortabilityPrivacyTest.php
- tests/Feature/Portability/F7PortabilityHistoryTest.php

## CREATE manifest — F7-9

- app/Application/AI/PbrAiProvider.php
- app/Application/AI/BuildAuthorizedAiContext.php
- app/Application/AI/PbrAiAssistant.php
- app/Infrastructure/AI/DisabledPbrAiProvider.php
- app/Presentation/Http/Controllers/AI/PbrAiController.php
- config/pbr_ai.php
- resources/js/pages/AI/Index.vue
- tests/Feature/AI/F7AiAuthorizationTest.php
- tests/Feature/AI/F7AiPrivacyTest.php
- tests/Feature/AI/F7AiNonActionTest.php

## CREATE manifest — F7-10

- tests/Feature/F7/F7WholeLifecycleTest.php
- tests/Feature/F7/F7PermanentRegressionTest.php
- tests/E2E/f7-lifecycle-intelligence.spec.ts
- tests/E2E/support/prepare-f7-e2e.php

## MODIFY manifest

- app/Domain/Access/CapabilityCatalog.php
- app/Domain/Access/StandardAccessProfileMatrix.php
- app/Domain/Members/Enums/MembershipAccessStatus.php
- app/Application/Access/ProvisionStandardAccessProfiles.php
- app/Application/Evidence/EvidenceTargetRegistry.php
- app/Application/Activity/ActivityTargetRegistry.php
- app/Providers/AppServiceProvider.php
- resources/js/components/WorkspaceNavigation.vue
- resources/js/i18n/catalog.ts
- routes/web.php
- tests/Feature/Access/F3StandardAccessProfilesTest.php
- tests/Feature/Access/BusinessAuthorizationTest.php
- tests/Unit/Domain/Members/MembershipAccessStatusTest.php
- tests/Feature/Access/AuthorizedPermissionProfileQueryTest.php
- tests/Feature/Evidence/EvidencePrivacyTest.php
- tests/Feature/Activity/ActivityPrivacyTest.php
- tests/Feature/Security/BusinessTenantIsolationTest.php
- .github/workflows/ci.yml

## DELETE manifest

None.
This manifest is a hard ceiling.
A listed MODIFY path may remain unchanged.
Any genuinely required tracked path outside this manifest requires STOP at G1
and an explicit narrow scope addendum before that path is touched.

### Narrow G1 addendum — CI #60 repair

Approved on 2026-09-28 solely to reconcile the intentional F7 Membership access lifecycle
(`active`, `suspended`, `revoked`) with two stale pre-F7 test expectations.
This addendum adds only:

- tests/Unit/Domain/Members/MembershipAccessStatusTest.php
- tests/Feature/Access/AuthorizedPermissionProfileQueryTest.php

The repair must preserve fail-closed authorization: missing, suspended and revoked Memberships
do not authorize access; active Membership remains necessary but never sufficient without the
required capability, Business/resource scope and access-policy checks.
No other F7 scope expansion is approved by this addendum.

## Capability plan

Add separate view/manage capabilities for:
partner_changes, exit, closure, reports, import, portability and pbr_ai.
Add search.view and business_health.view as derived read capabilities.
No capability grants Governance authority, Ownership rights or Document rights.
Standard profile templates receive only system capabilities appropriate to their existing role.
Document access remains separately evaluated.

## Evidence / Activity integration

EvidenceTargetRegistry may add partner_change_case, exit_case and closure_case targets.
Import staging is not formal Evidence by default.
ActivityTargetRegistry may add the three lifecycle case models and export/import targets
only when payload is safe and visibility is authorization-filtered.
Restricted Conflict remains governed by its existing registry/visibility behavior.

## UI / language

Use the existing Business Command Center shell.
New Changes navigation groups Partner Changes, Exit & Buyout and Closure.
Search is available from the workspace command/search surface.
Health remains action-first and privacy-safe.
Reports/Import/Portability/AI use full pages for complex workflows.
No chapter cards, public hero layout or PartnerDynamics redesign.
EN / မြန်မာ / Mixed share one architecture.
User-entered data is never auto-translated.

## F7-1 acceptance

- state machine and optimistic revision guards pass;
- exact current Ownership Register baseline is captured;
- ineligible transfer cannot advance;
- ROFR requirements cannot be bypassed;
- new-partner path requires applicable DD/contribution/legal/onboarding prerequisites;
- governed proposal binds exact frozen transfer/admission record;
- effect creates a new Ownership Register Version atomically;
- prior register remains immutable/superseded, never rewritten;
- partner lifecycle transition is append-only;
- cross-Business IDs fail closed;
- no system-access or governance shortcut is created.

## F7-2 acceptance

- Exit opening changes no Ownership/Membership truth;
- trigger/notice/share position/treatment/valuation/leaver/payment terms are preserved;
- hard-coded universal good/bad-leaver discount is prohibited;
- Finance payment links use F6C;
- Operations/Continuity handover requirements are checked rather than overwritten;
- Membership access transition is explicit, audited and last-owner safe;
- Former status requires effective share treatment/access/handover conditions;
- historic votes/signatures/authority/evidence remain valid;
- settlement may remain outstanding after effective exit when approved terms permit.

## F7-3 acceptance

- Closure requires governed authority and explicit legal conditions;
- asset protection and claim inventory precede residual distribution;
- no universal creditor priority exists in code/schema;
- Finance payment evidence is referenced, not duplicated as payment truth;
- unresolved required claims block residual distribution;
- legal closure may move workspace_status to closed only through controlled use case;
- closure preserves all canonical/history/file records;
- Closed and Archived remain distinct.

## F7-4 / F7-5 privacy acceptance

Search:
- permission-aware, Business-scoped, read-time authorization;
- restricted result existence/title/count/snippet/autocomplete does not leak;
- stale index row cannot bypass later access revocation.

Health:
- deterministic Met/Warning/Blocked/Unknown rules;
- safe explainability;
- no hidden record count/existence leakage;
- derived result never becomes canonical.
## F7-6 acceptance

- exact source/version/hash manifest is frozen before generation;
- download re-authorizes;
- private storage only;
- output language does not translate user-entered data;
- export is representation, not canonical truth.

## F7-7 / F7-8 acceptance

Import:
- source provenance/fingerprint/parser identity preserved;
- duplicate/idempotent import behaves deterministically;
- validation issues are explicit;
- collision with Effective truth never overwrites;
- confirmation routes through target domain use case;
- failed selected record does not silently report batch success.

Archive/Portability:
- archive != delete and archive != closure;
- explicit unarchive is supported only for Archived, not Closed;
- history/audit/signatures/authority snapshots/doc versions are preserved;
- portability manifest is self-describing and hashed;
- inaccessible/restricted data and secrets are excluded;
- download re-authorizes.
## F7-9 acceptance

- AI context is current-Business and authorization filtered before provider call;
- hidden record existence cannot be inferred from context/result;
- no unrestricted DB access;
- no approval/vote/signature/ownership/payment/access/archive/closure/effectivity actions;
- drafts/suggestions only;
- disabled/unconfigured provider fails safely without leaking context.

## F7-10 whole lifecycle proof

Admit Partner -> Contribution -> Ownership -> Governance participation
-> Exit -> access transition -> share transfer/buyout -> Former Partner,
while historic votes/signatures/authority/history remain intact.

## Test matrix

Maintain applicable:
- Unit
- Domain Invariant
- Feature
- Permission Matrix
- Tenant Isolation
- Workflow
- Historical Integrity
- Concurrency
- Document/Signature Version
- Export
- Language
- Accessibility
- E2E
- PostgreSQL integration
Permanent never-skip:
- Tenant Isolation
- Authorization
- Effective Record Immutability
- Historical Integrity

Each batch runs L1 Focused -> L2 Module -> L3 Dependency as needed.
L4 Foundation is required at major checkpoints/final candidate.
L5 Full System is required for the final F7 technical candidate/R1 handoff.
GitHub CI remains authoritative for full frontend/build/Chromium on the constrained VPS.

## G1 acceptance

PASS requires:
- actual changed paths remain inside this frozen manifest;
- no DELETE;
- source reconciliation remains non-conflicting;
- no R1/Production/Public Website/PartnerDynamics scope is pulled in;
- any required new path first receives an explicit narrow G1 addendum.

## G2 acceptance

PASS requires applicable:
- PHP syntax
- Pint
- Vue SFC parse/typecheck/build
- route compile
- CI YAML validation
- PostgreSQL migrations and integration tests
- exact-head GitHub CI green
## G3 acceptance

PASS requires all F7 lifecycle/canonical-boundary invariants above.

## G4 acceptance

PASS requires:
- default deny and tenant isolation;
- capability + confidentiality/record/document visibility;
- centralized Governance authority;
- immutable effective/history semantics;
- restricted-existence protection across all derived surfaces;
- exact version/hash/snapshot bindings;
- concurrency/idempotency protections;
- permanent regressions green.

## Human-G5 Pending Ledger

- F5 Boss UAT 4: DEFERRED / NOT PASS
- F6A/F6B Boss UAT 5: DEFERRED / NOT PASS
- F6C Boss UAT 6: DEFERRED / NOT PASS
- F6D Boss UAT: DEFERRED / NOT PASS
- F6E Boss UAT 7: DEFERRED / NOT PASS
- F7 Boss UAT 8: NOT YET RUN / NOT PASS

No F5/F6/F7 G6 tag/freeze while its required Human G5 remains deferred/not passed.
Preview remains the accepted earlier release until an explicit UAT candidate is authorized.
Production is untouched. Public Website is unchanged. PartnerDynamics is protected.
## Formal F7 closure

Technical completion may be reported only as:

F7 — TECHNICALLY COMPLETE
G5 — DEFERRED / NOT PASS
G6 — NOT PERFORMED

Formal F7 closure requires actual Boss UAT 8 / Human G5 PASS,
then G6 Commit + Annotated Tag + Freeze.
