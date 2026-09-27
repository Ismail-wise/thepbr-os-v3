# F6A + F6B Technical Scope

Baseline HEAD: ce28e5451aaf8515ad30984312799641d85bb39c
Implementation branch: feature/f6-governance-operations

## Authority and architecture

F6 extends the accepted F2 Formal Record / Proposal substrate and the accepted F3 Decision / Approval / Vote / Signature / Action substrate. It does not add a second approval engine.

Current authority resolution is centralized in ResolveGovernanceAuthority.

- Current Effective governance_charter is the primary Governance source.
- Formation Authority is fallback only while no Effective Governance Charter exists.
- One exact Decision/amount rule must resolve or the request fails closed.
- System Permission never makes a Membership an approver, voter or signer.
- Ownership is not consulted to establish Governance authority.
- Delegation substitutes an existing authority seat while preserving approve/vote/sign flags.
- Emergency Authority is temporary explicit authority; it never rewrites the base threshold or grants System Permission.
- Delegation and Emergency Authority are inert until bound to an exact Frozen Proposal Version and Approved Governance Decision.
- Recusal remains the F3 Decision Participant state/history behavior.
- Every Decision captures an immutable exact Authority Snapshot.

## F6A — Full Governance

Canonical structured content is a versioned Formal Record family with record type governance_charter.
Structured version data includes Governance/Minutes owner, voting basis, default approval rule, meeting frequency/quorum, COI/deadlock rules, remote/written-resolution flags, Decision/Authority Matrix rules, categories, exact Decision Types, Approval/Vote thresholds, Reserved Matters, signature/meeting/record requirements, amount ranges, and actor capacities/responsibilities.

Effective Charter content is immutable. Change follows Amendment -> New Formal Record Version -> Frozen Proposal Version -> Review -> Governance Decision -> Signature when required -> Effectivity.

### Delegation and Emergency Authority

F3 lifecycle records are reused. F6 adds the governed submission binding needed before the central resolver recognizes them.

Enforced dimensions include Business tenant, exact Decision Type, immutable written scope, effective/expiry/revocation timeline, exact Frozen Proposal Version/hash, exact approved authorizing Decision, active Membership at authority capture, and no authority expansion through Delegation.

## F6B — Operations

Canonical structured content is a versioned Formal Record family with record type operations_register.

Structured version data includes Roles/functions, purpose/responsibilities, operational delivery boundary, reporting line/type/frequency, meeting/review frequency, exactly one Primary assignment, at most one distinct Backup, RACI A/R/AR/C/I assignments, and KPI owner/target/measurement/frequency/status.

Operations data never grants Governance, Ownership, System or Document rights.

Governance determines who may decide. Operations determines who must deliver.

### Meetings

Governance Meetings bind the current Governance authority source at scheduling. Held/Cancelled history is immutable; Held requires Minutes. Attendance records present/remote/absent/recused evidence. A meeting-required Decision accepts only a Held meeting from the same captured authority source with quorum met.
### Actions

The existing F3 actions lifecycle is reused. F6 adds an immutable operations_action_links binding to the Current Effective Operations Register and exact Operations Role. The assignee must be an active Membership assigned to that Role.

## PostgreSQL integrity

F6 adds tenant-composite keys and guards for frozen Charter children, centralized Authority Snapshot source/rule validation, Charter-over-Formation precedence, direct/delegated/emergency Participant validation, complete participant sets before approval, immutable Emergency Authority capabilities, exact authority-change Proposal identity/hash, Operations version alignment, frozen Operations children, RACI/KPI Role-version alignment, terminal Meeting history, Decision/Meeting source/quorum binding, and immutable Operations Action source links.

Accepted F3 PostgreSQL functions remain available for rollback. F6 swaps relevant trigger bindings to F6-aware functions and restores the accepted bindings on rollback.

## Security

Sensitive F6 actions remain default-deny and require authenticated User, active Membership, current Business, tenant-bound resource, capability, resource access policy where applicable, valid workflow state, and captured Governance authority where applicable. Frontend visibility is never authorization.

## Test matrix

- ResolvedAuthorityActorTest: Delegation non-expansion and invalid authority.
- F6AGovernanceCharterTest: schema/triggers, F2 reuse, frozen Charter history.
- F6AAuthorityResolutionTest: Formation fallback, Effective Charter precedence, meeting-required fail-closed behavior, historical snapshots.
- F6ADelegationEmergencyTest: governed/inert Delegation, governed Emergency Authority, expiry, unchanged thresholds and resolved Participant validation.
- F6BOperationsRegisterTest: F2 reuse, Primary/Backup, RACI/KPI, freeze/proposal workflow, history and cross-version rejection.
- F6BMeetingActionTest: Meeting/quorum/minutes/history and F3 Action reuse with Effective Operations source.
- F6BTenantPermissionTest: default deny, tenant isolation and Governance/Operations capability separation.
Permanent F1-F5 regressions remain mandatory. PostgreSQL is authoritative for backend integration. Chromium is not run locally; GitHub Actions is the authoritative browser/E2E gate.

## Human-G5 Pending Ledger

- F5 Boss UAT 4: DEFERRED / NOT PASS
- F5 G6: NOT PERFORMED
- F5 milestone tag: ABSENT
- F6A/F6B Boss UAT 5: DEFERRED / NOT PASS
- F6A/F6B G6/tag/freeze: NOT PERFORMED
- Preview remains the accepted F4 release until separately authorized.
- Production remains untouched.

## Owner-approved scope addendum — 2026-09-27

During pre-commit G1 file-scope reconciliation, five existing tracked support files were found to be necessary for the already-frozen F6A/F6B architecture. The owner explicitly approved these paths as a MODIFY-manifest addendum; this does not replace or rewrite the original F6 scope freeze.

Additional approved MODIFY paths:
- app/Application/Governance/ResolveFormationAuthority.php
- app/Application/Governance/UpdateGovernanceActionStatus.php
- app/Infrastructure/Persistence/Eloquent/Governance/AuthoritySnapshot.php
- app/Infrastructure/Persistence/Eloquent/Governance/Decision.php
- app/Infrastructure/Persistence/Eloquent/Governance/EmergencyAuthorityGrant.php

These additions are limited to the reviewed support changes required for centralized Governance authority resolution, Operations reuse of the existing F3 Action lifecycle, exact Decision/Meeting binding, Authority Snapshot F6 fields, and explicit Emergency Authority capability persistence. They do not authorize feature expansion, unrelated refactoring, additional file-scope expansion, weakened tests, Production work, Public Website changes, or PartnerDynamics redesign.

G1 file-scope reconciliation is therefore performed against the original frozen CREATE/MODIFY/DELETE manifest plus this five-file owner-approved addendum. Human-G5 state remains unchanged: F5 Boss UAT 4 and F6A/F6B Boss UAT 5 remain DEFERRED / NOT PASS, and no F5/F6 G6 tag/freeze is authorized.
