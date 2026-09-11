# ADR-015 — PartnerDynamics Protected Boundary

**Status:** Accepted — Frozen Architecture Baseline

This architecture acceptance does NOT mean this capability is implemented, deployed, tested, operational, or F0 milestone-accepted.

## Architecture / Source Authority

`01_thePBR_OS_MASTER_SPEC_v1.1.md` is the frozen architecture authority. Section 31 fixes this ADR title as **PartnerDynamics Protected Boundary**.

## Context

PartnerDynamics is an existing protected partner-intelligence experience with its own current UI/UX, eight personality types, results, illustrations and content. Integrating it into the private OS must not erase that identity or misuse personality output as business authority.

## Decision

PartnerDynamics remains a **protected experience** inside the private OS context.

The OS may provide authorization, Business/Partner context, completion status, result references and a minimal wrapper. A `PartnerDynamicsAssessmentReference` provides an authorized reference to the preserved PartnerDynamics result rather than broadly duplicating it.

PartnerDynamics must not be normalized into generic OS forms and must never automatically determine equity, governance power or system permissions.

## Required Invariants

- Current PartnerDynamics implementation remains authority for its experience/content.
- Integration preserves the current PartnerDynamics identity.
- The OS may reference results without broadly duplicating them.
- Personality type never automatically determines ownership.
- Personality type never automatically determines governance authority.
- Personality type never automatically determines system permissions.
- PartnerDynamics integration remains subject to Business authorization.

## Consequences

The OS integrates PartnerDynamics contextually rather than redesigning it as a generic module. Domain decisions may reference assessment information only through appropriate human/governed processes.

## Security & Integrity Implications

Assessment access must follow authorized Business/Partner context. Assessment output cannot bypass contribution, ownership, governance or permission rules.

## Verification / Testing Implications

Integration tests must verify authorized access, reference behavior and the absence of automatic equity/governance/permission derivation from PartnerDynamics personality results.

## Source Traceability

- `## 2.1 Authority order`
- `## 2.3 PartnerDynamics`
- `# 3. Phase 9 v1.1 reconciliation - final corrections`
- `## 12.2 People and organization`
- `# 31. Architecture Decision Record starter set`

## Change Control

Redesigning PartnerDynamics into generic OS UI or using personality results as automatic business authority conflicts with the frozen architecture and requires explicit approved change control.
