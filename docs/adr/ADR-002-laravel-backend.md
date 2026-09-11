# ADR-002 — Laravel Backend

**Status:** Accepted — Frozen Architecture Baseline

This architecture acceptance does NOT mean this capability is implemented, deployed, tested, operational, or F0 milestone-accepted.

## Architecture / Source Authority

`01_thePBR_OS_MASTER_SPEC_v1.1.md` is the frozen architecture authority. Section 31 fixes this ADR title as **Laravel Backend**.

## Context

thePBR OS requires a backend that can host application use cases, domain rules, authorization, governed workflow execution, persistence, queues, documents, audit and integrations without moving core business logic into the frontend.

## Decision

The V1 backend will use **Laravel**.

Laravel is the backend framework within the Modular Monolith. Controllers and presentation adapters remain thin. Core business decisions, domain invariants and use-case orchestration must live in the appropriate application/domain layers rather than controllers or Vue components.

## Required Invariants

- Laravel is the V1 backend framework.
- Controllers remain thin.
- Core domain logic is not implemented in controllers or Vue.
- Backend authorization is authoritative; frontend visibility is never security.
- Business context and resource ownership are verified server-side for sensitive actions.
- No universal `PbrService` God Service may replace proper application/domain boundaries.

## Consequences

Laravel provides the application host for backend use cases and infrastructure integration while architectural layering remains explicit. Framework convenience must not become a reason to bypass domain boundaries.

## Security & Integrity Implications

Authentication, membership, Business context, capabilities, record/document access, workflow state and governance authority must be enforced by backend behavior. Security-sensitive operations require audit evidence where defined.

## Verification / Testing Implications

Feature/application tests, permission tests, tenant-isolation tests, workflow tests and PostgreSQL integration tests must exercise backend enforcement rather than relying on frontend behavior.

## Source Traceability

- `# 25. Technical Constitution`
- `## 25.1 Architecture`
- `## 25.2 Application layers`
- `# 26. Security Constitution`
- `# 28. Test Constitution`
- `# 33. Explicitly banned anti-patterns`
- `# 31. Architecture Decision Record starter set`

## Change Control

Replacing Laravel or shifting core business logic outside the approved backend/domain architecture requires explicit architecture review and approval.
