# ADR-008 — Private Object Storage

**Status:** Accepted — Frozen Architecture Baseline

This architecture acceptance does NOT mean this capability is implemented, deployed, tested, operational, or F0 milestone-accepted.

## Architecture / Source Authority

`01_thePBR_OS_MASTER_SPEC_v1.1.md` is the frozen architecture authority. Section 31 fixes this ADR title as **Private Object Storage**.

## Context

Business documents and evidence may contain confidential or restricted information. File bytes require storage separate from relational business records while remaining subject to Business isolation and authorization.

## Decision

V1 will use **private S3-compatible object storage** for file bytes.

The database stores document metadata and storage keys. Object storage holds file bytes. Downloads and previews require authorization. Large binary content does not belong in PostgreSQL.

This ADR does not choose an S3-compatible provider.

## Required Invariants

- Business files are private by default.
- Object bytes are not exposed by direct public URLs as a normal access model.
- Database records hold metadata/storage references rather than large binaries.
- Downloads and previews enforce Business and access authorization.
- Architecture supports validation of MIME/type/size and safe handling controls.
- Document permissions remain distinct from general system permissions.
- No provider selection is implied by this ADR.

## Consequences

File storage infrastructure is separated from structured canonical records. Document access must flow through authorized application behavior rather than public object exposure.

## Security & Integrity Implications

Cross-Business/private-file download must be denied. Restricted evidence cannot bypass access policy. File handling must account for validation, malware-scanning readiness and safe preview behavior.

## Verification / Testing Implications

Tests must cover private-file authorization, cross-Business download denial, document access grants/restrictions and preservation of document/version integrity.

## Source Traceability

- `# 19. Documents, Vault, evidence and e-sign`
- `## 19.1 Document Vault`
- `## 19.2 Document vs Document Version`
- `## 25.1 Architecture`
- `## 25.4 Database`
- `## 25.8 Files`
- `# 26. Security Constitution`
- `# 31. Architecture Decision Record starter set`

## Change Control

Changing the private-storage model or introducing public document access requires explicit architecture/security approval. S3-compatible provider selection is a separate implementation decision.
