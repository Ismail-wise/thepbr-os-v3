# ADR-003 — Inertia + Vue 3 + TypeScript

**Status:** Accepted — Frozen Architecture Baseline

This architecture acceptance does NOT mean this capability is implemented, deployed, tested, operational, or F0 milestone-accepted.

## Architecture / Source Authority

`01_thePBR_OS_MASTER_SPEC_v1.1.md` is the frozen architecture authority. Section 31 fixes this ADR title as **Inertia + Vue 3 + TypeScript**.

## Context

The private OS requires a professional interactive application shell while keeping backend business rules authoritative and avoiding a duplicated client-side domain system.

## Decision

The V1 presentation stack will use **Inertia + Vue 3 + TypeScript**.

Tailwind CSS with internal PBR design tokens is part of the preferred presentation stack. Vue components implement presentation and interaction, not canonical business rules or security authority.

## Required Invariants

- Inertia, Vue 3 and TypeScript form the V1 frontend/application presentation baseline.
- Core business logic does not live in Vue.
- Frontend visibility does not grant permission or governance authority.
- Language changes content only; it does not create separate UI architecture.
- Business context and record state presented by the UI must remain consistent with backend-authoritative truth.

## Consequences

The frontend can provide rich OS interactions while avoiding a separate client-side business architecture. TypeScript supports explicit frontend contracts, but backend enforcement remains decisive.

## Security & Integrity Implications

Hidden buttons, disabled actions or client-side checks are never sufficient authorization. Sensitive operations must be revalidated by the backend against Business, permissions, confidentiality, workflow and governance requirements.

## Verification / Testing Implications

Frontend build/typecheck, language regression, accessibility checks and critical browser/E2E tests are required where applicable. Security tests must prove that direct requests cannot bypass backend controls.

## Source Traceability

- `# 9. UI/UX Constitution`
- `# 10. Language, terminology and presentation`
- `# 25. Technical Constitution`
- `## 25.1 Architecture`
- `## 25.2 Application layers`
- `# 26. Security Constitution`
- `# 28. Test Constitution`
- `# 31. Architecture Decision Record starter set`

## Change Control

A different primary frontend architecture requires explicit architecture approval. Presentation choices must not silently relocate canonical business logic or authorization into the client.
