# ADR-007 — RBAC + Contextual Governance Authority

**Status:** Accepted — Frozen Architecture Baseline

This architecture acceptance does NOT mean this capability is implemented, deployed, tested, operational, or F0 milestone-accepted.

## Architecture / Source Authority

`01_thePBR_OS_MASTER_SPEC_v1.1.md` is the frozen architecture authority. Section 31 fixes this ADR title as **RBAC + Contextual Governance Authority**.

## Context

System access and legal/business decision authority are different concepts. A user may have application capabilities without being authorized to approve a governed business action, and ownership does not automatically grant system permissions.

## Decision

System permissions use explicit Permission/Profile/Grant concepts together with contextual authorization, while governance authority is resolved separately through the applicable governance rules.

Sensitive requests conceptually evaluate authenticated User, active Membership, current Business, resource ownership, capability, record/document visibility, workflow state and governance authority where applicable.

The Governance Authority Engine resolves the applicable current authority rule and stores an immutable Authority Snapshot at submission/decision time.

## Required Invariants

- Ownership Rights, Governance Rights, System Permissions and Document Permissions remain independent.
- Default deny applies.
- Explicit scoped deny wins over conflicting scoped allow for the same object/action.
- System Permission cannot override missing Governance Authority.
- Workspace Owner is not automatic governance authority.
- Governance Secretary administration is not automatic approval authority.
- Conflict/recusal, delegation and emergency-authority conditions are evaluated where applicable.
- Decision-time Authority Snapshots remain historically immutable.

## Consequences

Authorization cannot be reduced to one role field or one boolean. Capability templates may simplify administration, but business decision authority remains context-sensitive and historically traceable.

## Security & Integrity Implications

Backend authorization must prevent privilege escalation through role labels, ownership percentage or frontend controls. Governance authority must be evaluated for governed actions even when the actor has system access.

## Verification / Testing Implications

Permission-matrix, tenant-isolation and governance-authority tests must include Workspace Owner without approval authority, recused/ineligible actors, scoped denials and historical authority preservation.

## Source Traceability

- `## 12.1 Identity, access and rights`
- `# 16. Four-rights and permission constitution`
- `## 16.1 Permission evaluation`
- `## 16.2 Default role/profile templates`
- `## 16.3 Key role principles`
- `# 17. Governance Authority Engine`
- `# 28. Test Constitution`
- `# 31. Architecture Decision Record starter set`

## Change Control

Collapsing system permissions, governance authority, ownership or document permissions into one rights model requires an explicit Architecture Change Proposal and security/domain review.
