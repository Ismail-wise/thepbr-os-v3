# ADR-011 — Explicit State-Machine Workflows

**Status:** Accepted — Frozen Architecture Baseline

This architecture acceptance does NOT mean this capability is implemented, deployed, tested, operational, or F0 milestone-accepted.

## Architecture / Source Authority

`01_thePBR_OS_MASTER_SPEC_v1.1.md` is the frozen architecture authority. Section 31 fixes this ADR title as **Explicit State-Machine Workflows**.

## Context

Formal business records pass through meaningful states such as Draft, Review, Approval, Signature, Ready for Effect, Effective, Superseded, Delivered or Accepted. Implicit or ad-hoc transitions would allow business rules to be bypassed.

## Decision

Important lifecycle workflows use **explicit, guarded and tested state-machine transitions**.

Guards produce reusable Requirement outcomes such as Met, Warning or Blocked. Side effects are orchestrated through application use cases and Business/Domain Events.

The universal workflow constitution governs formal record, Scenario/Proposal, Amendment, Contribution, Signature, Action, Review and Partner lifecycle patterns.

## Required Invariants

- Invalid transitions are rejected.
- Draft work may remain easy to edit until promoted into formal review.
- Review submission freezes the exact reviewed version.
- Scenario cannot directly mutate Effective truth.
- Proposal review uses a frozen version.
- Review does not directly edit the reviewed Effective record.
- Signature completion does not automatically mean Effectivity.
- Contribution states Proposed, Reviewed, Approved, Delivered and Accepted remain distinct.
- Approval is not reduced to a boolean.

## Consequences

Workflow rules become explicit domain behavior rather than scattered controller/UI conditions. Formal state changes can be audited and tested consistently.

## Security & Integrity Implications

Authorization and governance authority are evaluated in the context of the current workflow state. Users cannot bypass required stages by calling backend endpoints directly.

## Verification / Testing Implications

Workflow transition tests must cover allowed and denied transitions, guards, frozen review versions, future Effectivity and terminal/exception states.

## Source Traceability

- `# 14. Universal workflow constitution`
- `## 14.1 Draft and official record`
- `## 14.2 Scenario and Proposal`
- `## 14.3 Amendment`
- `## 14.4 Contribution`
- `## 14.5 Signature`
- `## 14.6 Action`
- `## 14.7 Review`
- `## 14.8 Partner lifecycle`
- `## 25.7 Workflow/state machines`
- `# 28. Test Constitution`
- `# 31. Architecture Decision Record starter set`

## Change Control

New workflow shortcuts that bypass explicit state transitions require architecture/domain review. Direct scenario application or direct mutation of Effective records is not permitted.
