# ADR-009 — Structured Data Is Canonical

**Status:** Accepted — Frozen Architecture Baseline

This architecture acceptance does NOT mean this capability is implemented, deployed, tested, operational, or F0 milestone-accepted.

## Architecture / Source Authority

`01_thePBR_OS_MASTER_SPEC_v1.1.md` is the frozen architecture authority. Section 31 fixes this ADR title as **Structured Data Is Canonical**.

## Context

The OS produces documents, exports, dashboards, search indexes and other representations. Allowing those representations to become competing sources of truth would create inconsistent ownership, governance, finance and historical state.

## Decision

Structured business records are the canonical source of business truth.

The governing rule is: **Enter once -> Verify once -> Approve once -> Reuse everywhere.**

Generated PDF/DOCX/XLSX/CSV files represent or export structured records; they do not replace canonical structured ownership, capital, governance or other formal business truth.

Derived systems may be rebuilt from canonical records and are not canonical truth.

## Required Invariants

- One business fact has one canonical source.
- Structured records are canonical.
- Generated files are representations/exports unless explicitly modeled as evidence or document versions.
- Search, Health, Notifications, Timeline and AI indexes are derived and rebuildable.
- Imported or AI-extracted data does not become approved truth automatically.
- Canonical source selection remains explicit for key business questions.

## Consequences

Application features must reference canonical records instead of re-entering or duplicating authoritative values. Generated artifacts remain traceable back to structured source records.

## Security & Integrity Implications

A file, report, cache, index, AI output or import cannot bypass authorization, governance or version controls to establish Effective truth.

## Verification / Testing Implications

Tests must confirm that derived outputs do not mutate canonical truth and that official values are resolved from their defined canonical source/version.

## Source Traceability

- `# 11. Core data philosophy`
- `## 11.1 Golden rule`
- `## 11.2 Canonical vs representation`
- `## 11.3 Current Effective truth`
- `## 12.11 Import, history and derived systems`
- `# 13. Canonical source matrix`
- `# 24. Import and external data`
- `# 35. Final North Stars`
- `# 31. Architecture Decision Record starter set`

## Change Control

Introducing a competing canonical representation or duplicating formal truth requires explicit architecture review. No feature may silently establish a second source of truth.
