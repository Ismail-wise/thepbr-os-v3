# ADR-012 — Append-Only Audit

**Status:** Accepted — Frozen Architecture Baseline

This architecture acceptance does NOT mean this capability is implemented, deployed, tested, operational, or F0 milestone-accepted.

## Architecture / Source Authority

`01_thePBR_OS_MASTER_SPEC_v1.1.md` is the frozen architecture authority. Section 31 fixes this ADR title as **Append-Only Audit**.

## Context

Formal business actions must remain traceable without allowing ordinary application behavior to rewrite or erase evidence of who or what acted, when, in which Business and against which record/version.

## Decision

Audit Events are **append-only evidence**.

Audit is distinct from Business/Domain Events and the user-facing Business Timeline. Audit actor type may be User, System or Service/Integration.

Permission/access changes, signatures, votes, approvals, sensitive exports and high-risk administration require audit evidence where applicable.

## Required Invariants

- Audit Events are not normally mutable or deletable.
- Audit identifies actor/action, time, Business and relevant record/version.
- Audit actor type supports User, System and Service/Integration.
- Audit/log metadata does not contain secrets or unrestricted sensitive payloads.
- Business/Domain Event, Audit Event and Business Timeline remain distinct concepts.
- User-facing timeline behavior does not replace append-only audit evidence.

## Consequences

Audit storage and APIs must favor durable evidentiary history over ordinary CRUD behavior. Timeline views may derive selected events without changing the underlying audit record.

## Security & Integrity Implications

Sensitive administrative and governance actions remain attributable. Audit must itself respect sensitive-data handling and must not become a channel for secret leakage.

## Verification / Testing Implications

Tests must prove that ordinary application flows cannot update/delete Audit Events and that required sensitive operations emit appropriate audit evidence.

## Source Traceability

- `## 12.11 Import, history and derived systems`
- `# 20. Business events, Audit and Timeline`
- `# 26. Security Constitution`
- `# 28. Test Constitution`
- `# 31. Architecture Decision Record starter set`

## Change Control

Any normal mutation/deletion path for accepted audit evidence conflicts with this baseline and requires explicit architecture/security approval.
