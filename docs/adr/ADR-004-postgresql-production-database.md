# ADR-004 — PostgreSQL Production Database

**Status:** Accepted — Frozen Architecture Baseline

This architecture acceptance does NOT mean this capability is implemented, deployed, tested, operational, or F0 milestone-accepted.

## Architecture / Source Authority

`01_thePBR_OS_MASTER_SPEC_v1.1.md` is the frozen architecture authority. Section 31 fixes this ADR title as **PostgreSQL Production Database**.

## Context

thePBR OS contains relational business truth with strong requirements for tenant isolation, explicit relationships, transactions, version history, effective records and historical integrity.

## Decision

**PostgreSQL** is the V1 production database.

Important business relationships use relational tables and explicit foreign keys. JSON/JSONB may support flexible metadata, snapshots, provider payloads or extensions, but must not become the sole storage model for core ownership, governance or finance relationships.

Large binary document content does not belong in PostgreSQL.

No PostgreSQL hosting/provider decision is made by this ADR.

## Required Invariants

- Production uses PostgreSQL.
- Production SQLite is prohibited.
- Important relationships use explicit relational structure and foreign keys.
- Core business truth is not hidden inside one universal JSON record model.
- High-impact multi-record operations must be transaction-safe.
- Large file bytes remain outside PostgreSQL.
- Development, Test/CI, Preview/Staging and Production databases remain isolated.

## Consequences

Schema design must model domain relationships explicitly and support strong consistency for critical truth. Flexible data storage remains available only where it does not replace required relational semantics.

## Security & Integrity Implications

Tenant isolation must be enforced through application/query boundaries and tested against cross-Business access. Environment separation prevents production data from becoming normal development/test data.

## Verification / Testing Implications

PostgreSQL integration tests are required. Migration verification, transaction behavior, tenant-isolation tests and historical-integrity tests must run against PostgreSQL where applicable.

## Source Traceability

- `# 25. Technical Constitution`
- `## 25.1 Architecture`
- `## 25.4 Database`
- `## 25.6 Transactions and consistency`
- `# 27. Environments, backup and observability`
- `# 28. Test Constitution`
- `# 33. Explicitly banned anti-patterns`
- `# 31. Architecture Decision Record starter set`

## Change Control

Changing the production database architecture or replacing relational core truth with another storage model requires an explicit Architecture Change Proposal.
