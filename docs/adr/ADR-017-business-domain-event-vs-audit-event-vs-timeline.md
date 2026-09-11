# ADR-017 — Business/Domain Event vs Audit Event vs Timeline

**Status:** Accepted — Frozen Architecture Baseline

This architecture acceptance does NOT mean this capability is implemented, deployed, tested, operational, or F0 milestone-accepted.

## Architecture / Source Authority

`01_thePBR_OS_MASTER_SPEC_v1.1.md` is the frozen architecture authority. Section 31 fixes this ADR title as **Business/Domain Event vs Audit Event vs Timeline**.

## Context

An important business occurrence, evidentiary audit record and human-readable activity feed serve different purposes. Collapsing them into one generic event stream would weaken domain behavior, audit integrity and user-facing presentation.

## Decision

The system keeps three distinct concepts:

- **Business/Domain Event:** an important business occurrence that can trigger system behavior.
- **Audit Event:** append-only evidence of who/system did what, when, in which Business and to which record/version.
- **Business Timeline:** a human-readable derived selection of significant events.

They may reference related occurrences, but they are not interchangeable records.

## Required Invariants

- Domain Events model business/system-significant occurrences.
- Audit Events provide append-only evidence.
- Timeline is derived, human-readable presentation.
- Timeline entries do not replace audit evidence.
- Audit actor type supports User, System and Service/Integration.
- Audit metadata does not expose secrets or unrestricted sensitive payloads.
- Derived timeline behavior remains permission-aware.

## Consequences

Event-driven side effects, evidentiary history and activity presentation can evolve independently without corrupting each other's semantics.

## Security & Integrity Implications

Audit evidence remains durable even if timeline presentation changes. Derived Timeline must not expose restricted information or restricted record existence.

## Verification / Testing Implications

Tests must distinguish domain-event behavior, audit creation and permission-filtered timeline derivation. Audit immutability and restricted-data non-leakage require dedicated regression coverage.

## Source Traceability

- `# 3. Phase 9 v1.1 reconciliation - final corrections`
- `## 12.7 Execution and review`
- `## 12.11 Import, history and derived systems`
- `# 20. Business events, Audit and Timeline`
- `# 21. Derived systems and privacy`
- `# 31. Architecture Decision Record starter set`

## Change Control

Collapsing Domain Event, Audit Event and Business Timeline into a single generic record requires explicit architecture review and approval.
