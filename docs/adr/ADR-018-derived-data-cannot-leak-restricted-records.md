# ADR-018 — Derived Data Cannot Leak Restricted Records

**Status:** Accepted — Frozen Architecture Baseline

This architecture acceptance does NOT mean this capability is implemented, deployed, tested, operational, or F0 milestone-accepted.

## Architecture / Source Authority

`01_thePBR_OS_MASTER_SPEC_v1.1.md` is the frozen architecture authority. Section 31 fixes this ADR title as **Derived Data Cannot Leak Restricted Records**.

## Context

Even when canonical record contents are protected, derived systems can leak sensitive facts through counts, titles, snippets, notifications, search suggestions, timeline entries, health signals, caches or AI retrieval.

## Decision

Derived systems must **never leak restricted records or restricted information about their existence** where access policy requires concealment.

Derived systems include Dashboard counts, Business Health, Notifications, Timeline, Search Index, AI retrieval/indexes and caches. They are rebuildable from canonical data and remain authorization-filtered.

## Required Invariants

- Authorization applies to derived surfaces, not only canonical record pages.
- Restricted content cannot leak through title, count, snippet, notification text or health signal.
- Search is permission-filtered.
- AI retrieval is authorization-filtered.
- Derived indexes/caches are not canonical truth.
- Business isolation applies to derived systems.
- Rebuilding derived systems must not weaken access restrictions.

## Consequences

Every derived feature must carry authorization context into computation, indexing, retrieval and presentation. Aggregate or summary UI is not exempt from confidentiality rules.

## Security & Integrity Implications

The existence of a restricted record can itself be sensitive. Dashboard, Health, Notifications, Timeline, Search and AI are therefore security surfaces and must be treated as such.

## Verification / Testing Implications

Security tests must attempt leakage through URL manipulation, dashboard/health counts, notifications, timeline, search, reports and AI retrieval. Cross-Business and restricted-record derivation must fail.

## Source Traceability

- `# 3. Phase 9 v1.1 reconciliation - final corrections`
- `## 12.11 Import, history and derived systems`
- `# 21. Derived systems and privacy`
- `# 22. AI Constitution`
- `## 25.5 Tenant isolation`
- `## 25.10 Search`
- `# 28. Test Constitution`
- `# 31. Architecture Decision Record starter set`

## Change Control

Any derived surface that bypasses record/Business authorization conflicts with the frozen architecture. Such a change requires explicit architecture and security approval.
