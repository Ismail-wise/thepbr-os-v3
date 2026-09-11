# ADR-010 — AI Authorization Boundary

**Status:** Accepted — Frozen Architecture Baseline

This architecture acceptance does NOT mean this capability is implemented, deployed, tested, operational, or F0 milestone-accepted.

## Architecture / Source Authority

`01_thePBR_OS_MASTER_SPEC_v1.1.md` is the frozen architecture authority. Section 31 fixes this ADR title as **AI Authorization Boundary**.

## Context

AI features may explain, summarize, draft and retrieve business information, but unrestricted AI access could bypass tenant isolation, confidentiality, governance authority and canonical-record rules.

## Decision

AI operates only through an **authorization-filtered boundary** using explicit User + Business + authorized context.

AI never receives unrestricted database access. It may analyze, explain, compare and draft, but may not approve, vote, sign, admit a partner, change ownership, issue payment, revoke business rights or create an Effective business record.

AI-generated drafts follow the same human workflow as other drafts.

Domain/application code talks to an AI service/adapter rather than coupling core business logic directly to one provider SDK.

## Required Invariants

- Every AI request has explicit User and Business context.
- Retrieval is authorization-filtered.
- Restricted data and restricted existence are not leaked to AI context.
- AI cannot perform governed or Effective-truth actions reserved for authorized humans/workflows.
- AI output is not automatically canonical truth.
- Restricted domains may disable AI processing according to policy/privacy/legal requirements.
- Provider choice does not redefine domain authority.

## Consequences

AI is an assistive capability inside the existing authorization and workflow system, not an alternate control plane.

## Security & Integrity Implications

Cross-Business AI retrieval and inference leakage must fail. Sensitive context is minimized to what the requesting actor is authorized to access.

## Verification / Testing Implications

Security tests must attempt cross-Business and restricted-record retrieval through AI surfaces. Tests must prove that AI cannot directly execute approvals, signatures, ownership changes, payments or Effective-record creation.

## Source Traceability

- `# 21. Derived systems and privacy`
- `# 22. AI Constitution`
- `## 25.11 AI provider abstraction`
- `# 26. Security Constitution`
- `# 28. Test Constitution`
- `# 33. Explicitly banned anti-patterns`
- `# 31. Architecture Decision Record starter set`

## Change Control

Any expansion of AI authority or data access requires explicit architecture, security and governance review. AI must never silently gain unrestricted database or Effective-truth authority.
