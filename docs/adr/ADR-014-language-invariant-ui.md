# ADR-014 — Language-Invariant UI

**Status:** Accepted — Frozen Architecture Baseline

This architecture acceptance does NOT mean this capability is implemented, deployed, tested, operational, or F0 milestone-accepted.

## Architecture / Source Authority

`01_thePBR_OS_MASTER_SPEC_v1.1.md` is the frozen architecture authority. Section 31 fixes this ADR title as **Language-Invariant UI**.

## Context

thePBR OS supports English, မြန်မာ and မြန်မာ + EN as first-class modes. Language support must not fragment the application into separate architectures or cause user-entered business data to be silently transformed.

## Decision

Language changes **content only**, not the application shell, layout, components or interaction patterns.

English, မြန်မာ and မြန်မာ + EN are first-class modes. Burmese UI copy should be natural and conversational, while formal/legal/business records may use precise formal language.

User-entered stored content is not automatically translated when UI language changes. Report/document output language is selected independently.

## Required Invariants

- All three language modes use the same UI architecture.
- Language switching does not change workflow or permission semantics.
- User-entered data is not auto-translated merely because UI language changes.
- Output/document language is independently selectable where applicable.
- Centralized PBR terminology is used to reduce inconsistent labels.
- Separate Burmese-only UI architecture is prohibited.

## Consequences

Localization is a first-class content concern built on a shared interaction system. Layout/components must be designed to accommodate language differences without creating divergent product behavior.

## Security & Integrity Implications

Language choice cannot alter permissions, governance authority, workflow state or canonical data meaning.

## Verification / Testing Implications

Language regression must verify structural consistency across English, မြန်မာ and Mixed modes, including critical workflows and accessibility behavior.

## Source Traceability

- `# 10. Language, terminology and presentation`
- `# 28. Test Constitution`
- `# 33. Explicitly banned anti-patterns`
- `# 31. Architecture Decision Record starter set`

## Change Control

Any proposal for separate language-specific application architecture requires explicit product/architecture approval.
