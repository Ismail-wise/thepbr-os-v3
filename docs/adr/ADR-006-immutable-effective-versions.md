# ADR-006 — Immutable Effective Versions

**Status:** Accepted — Frozen Architecture Baseline

This architecture acceptance does NOT mean this capability is implemented, deployed, tested, operational, or F0 milestone-accepted.

## Architecture / Source Authority

`01_thePBR_OS_MASTER_SPEC_v1.1.md` is the frozen architecture authority. Section 31 fixes this ADR title as **Immutable Effective Versions**.

## Context

Formal business truth must remain historically trustworthy. Editing a currently Effective record in place would destroy evidence of what was previously approved, signed or relied upon.

## Decision

Effective formal records are normally **immutable**.

The live truth is the applicable Current Effective Version, not merely the latest-created row. Changes to Effective truth proceed through a controlled amendment/new-version process. Previous Effective versions remain preserved as historical or superseded records.

Created At, Approved At, Signed At, Effective From and Effective Until remain distinct concepts where applicable.

## Required Invariants

- Effective records are not directly overwritten.
- A change to Effective truth creates a controlled new version.
- Previous official versions remain preserved.
- Historical snapshots cannot be rewritten by later ownership, governance or role changes.
- Under Review versions are frozen.
- Validity/effective uniqueness is scoped by Business, record family, subject/scope and effective period as required by the domain.
- Logical historical identities are not recycled.

## Consequences

Formal changes require version-aware workflows and explicit effective dating. Consumers must resolve applicable Effective truth rather than assuming the newest row is official.

## Security & Integrity Implications

Authorization to edit a draft does not grant permission to mutate Effective history. High-impact amendments must preserve authority, approval, signature and audit evidence associated with exact versions.

## Verification / Testing Implications

Effective Record Immutability and Historical Integrity are permanent regressions. Tests must prove that old versions and historical authority/ownership snapshots remain unchanged after later amendments.

## Source Traceability

- `# 11. Core data philosophy`
- `## 11.3 Current Effective truth`
- `## 11.4 History`
- `## 14.3 Amendment`
- `# 15. Effective dating, concurrency and historical integrity`
- `# 28. Test Constitution`
- `# 31. Architecture Decision Record starter set`

## Change Control

Any proposal to allow direct mutation of Effective formal truth conflicts with the frozen architecture and requires STOP plus an Architecture Change Proposal.
