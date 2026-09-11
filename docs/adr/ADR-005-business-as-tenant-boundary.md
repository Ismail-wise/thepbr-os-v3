# ADR-005 — Business as Tenant Boundary

**Status:** Accepted — Frozen Architecture Baseline

This architecture acceptance does NOT mean this capability is implemented, deployed, tested, operational, or F0 milestone-accepted.

## Architecture / Source Authority

`01_thePBR_OS_MASTER_SPEC_v1.1.md` is the frozen architecture authority. Section 31 fixes this ADR title as **Business as Tenant Boundary**.

## Context

One User may participate in multiple Businesses. Each Business owns its own business records, partners, capital, ownership, governance, finance, documents, decisions and history. Cross-Business exposure is a critical security failure.

## Decision

**Business is the tenant/security boundary.**

Isolation applies through route/context resolution, application queries, policies, file access, search, reports, AI retrieval and background jobs. Sensitive backend actions must resolve the authenticated User, active Membership, current Business and resource ownership before allowing the action.

## Required Invariants

- User -> Membership -> Business Workspace -> Business Records remains the core tenancy model.
- Every tenant-owned resource is resolved in Business context.
- Active Membership does not automatically create governance authority.
- Frontend visibility is never tenant security.
- Files, reports, search, AI and background work respect the same Business boundary.
- Restricted data must not leak across Business boundaries through derived surfaces.

## Consequences

Business scoping is a platform concern rather than a page-level convention. Queries, policies, jobs, documents, derived indexes and integrations must all carry explicit tenant context.

## Security & Integrity Implications

Cross-Business URL/object-ID manipulation, file access, search, reports and AI retrieval must fail. Default-deny behavior applies where Business context or authorized Membership is absent.

## Verification / Testing Implications

Tenant Isolation is a permanent regression requirement. Tests must include Business A/B isolation, manipulated identifiers, private files, search/AI/report surfaces and background execution where applicable.

## Source Traceability

- `# 5. Multi-business model`
- `## 5.1 Core hierarchy`
- `## 12.1 Identity, access and rights`
- `# 16. Four-rights and permission constitution`
- `## 16.1 Permission evaluation`
- `## 25.5 Tenant isolation`
- `# 28. Test Constitution`
- `# 31. Architecture Decision Record starter set`

## Change Control

Any change to the Business tenant/security boundary requires explicit architecture and security review. Tenant scoping must never be weakened as an implementation shortcut.
