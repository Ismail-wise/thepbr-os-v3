# ADR-016 — Proposal/Frozen Review Version

**Status:** Accepted — Frozen Architecture Baseline

This architecture acceptance does NOT mean this capability is implemented, deployed, tested, operational, or F0 milestone-accepted.

## Architecture / Source Authority

`01_thePBR_OS_MASTER_SPEC_v1.1.md` is the frozen architecture authority. Section 31 fixes this ADR title as **Proposal/Frozen Review Version**.

## Context

Formal decisions and approvals must bind to exact content. A mutable draft cannot safely serve as the target of approval because it could change after review and invalidate the meaning of the decision.

## Decision

**Proposal is first-class**, and formal review/approval targets an exact frozen Proposal Version/Snapshot.

The approved scenario path is:

`Effective Baseline -> Scenario -> Compare -> Create Proposal -> Frozen Proposal Version -> Review -> Decision / Approval -> Signature if required -> Ready for Effect -> Effective Version`

A Scenario never directly mutates live truth.

## Required Invariants

- Formal approval never points to a mutable draft.
- Proposal and Proposal Version/Frozen Snapshot remain distinct concepts.
- Review submission freezes the exact version under review.
- Approval/Decision evidence binds to the frozen version.
- Signature, when required, follows the applicable exact-version controls.
- Scenario cannot directly become Effective truth.
- A changed proposal requires a new version and appropriate review/decision flow.

## Consequences

Formal review objects require explicit version identity. Draft planning can remain easy to edit until promoted, while reviewed content remains stable and auditable.

## Security & Integrity Implications

Authorized actors must approve the same frozen content they reviewed. Later draft edits cannot inherit prior approval or signature evidence.

## Verification / Testing Implications

Workflow and historical-integrity tests must verify frozen review content, version-bound approvals, denial of direct Scenario application and preservation of older proposal/decision evidence.

## Source Traceability

- `# 3. Phase 9 v1.1 reconciliation - final corrections`
- `## 12.6 Governance and formal decision records`
- `## 14.1 Draft and official record`
- `## 14.2 Scenario and Proposal`
- `# 15. Effective dating, concurrency and historical integrity`
- `# 28. Test Constitution`
- `# 31. Architecture Decision Record starter set`

## Change Control

Allowing formal approval of mutable drafts or direct Scenario application conflicts with the frozen architecture and requires STOP plus an Architecture Change Proposal.
