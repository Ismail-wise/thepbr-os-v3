# thePBR OS V3

thePBR OS is the private **Partnership Business Operating System** for structuring, deciding, approving, operating, protecting, reviewing, documenting, and evolving a partnership business using the PBR framework as its underlying business logic.

This repository is for the private OS application. It is not a redesign of the Public Website, not a course or chapter website, and not a continuation of the old Student Portal architecture.

## Authority model

Development uses these sources for distinct kinds of truth:

- **Master Specification v1.1** — authority for product, UX, data, security, technical architecture, development order, and acceptance gates.
- **Original PBR source PDFs** — authority for PBR business and domain logic.
- **Current PartnerDynamics implementation** — authority for the protected PartnerDynamics experience, including its UI/UX, eight personality types, illustrations, results, and content experience.
- **Accepted Git baselines, annotated milestone tags, and verified tests/gates** — authority for what has actually been implemented and accepted.

The existing Public Website is a protected boundary and remains unchanged; it is not the architecture authority for the private OS.

The old Student Portal is historical reference only and must not be treated as V3 architecture or design authority.

A genuine conflict between authoritative sources requires **STOP + Architecture Change Proposal**. Do not silently choose an interpretation or implement around the conflict.

## Product model

The core tenancy model is:

```text
User
  -> Membership
      -> Business Workspace
          -> Business Records
```

A Business is the tenant and security boundary. One User may belong to multiple Businesses.

The system keeps these concepts separate:

- Ownership Rights
- Governance Rights
- System Permissions
- Document Permissions

Ownership does not automatically grant system access. System access does not automatically grant governance authority.

## Canonical records and history

The operating principle is:

> Enter once -> Verify once -> Approve once -> Reuse everywhere.

One fact should have one canonical source.

Effective official records are historical truth and must not be silently overwritten. Changes to effective truth require an amendment and a new version. Draft and planning work should remain easy to edit until promoted into a formal record or proposal.

Structured business records are canonical. Generated documents and files represent those records; they do not replace canonical structured truth.

## Scenarios, proposals, and effectivity

A Scenario never changes live truth directly.

The formal path is:

```text
Scenario
-> Proposal
-> Frozen Proposal Version
-> Review
-> Decision / Approval
-> Signature when required
-> Ready for Effect
-> Effective Record
```

There is no direct "Apply Scenario" path.

Decision, Vote, Approval, and Signature are separate concepts. Approvals bind to exact frozen versions and applicable authority snapshots.

A signature binds to an exact Document Version and hash. Changing signed content requires a new version and new signatures.

**Signed does not automatically mean Effective.** Future effective dates and applicable legal or business conditions must be supported.

## Contributions and ownership

Contribution lifecycle:

```text
Proposed
-> Reviewed
-> Approved
-> Delivered
-> Accepted
```

Only **Accepted Contribution Value** may flow into official Ownership.

The Current Effective Ownership Register is the official ownership source. Canonical ownership percentage must not be duplicated onto a Partner record.

Voting rights and profit rights remain separate from ownership percentage.

## Governance and operations

Governance determines **who may decide**.

Operations determines **who must deliver**.

Governance authority must use centralized authority rules rather than module-specific hard-coded approval logic.

Before initial Governance exists, the system uses explicit Temporary Formation Authority. A Workspace Owner is not automatically authorized to make governance decisions.

## Security invariants

Security is **default deny**.

Every sensitive backend action must verify the relevant combination of:

- authenticated User
- active Membership
- current Business
- resource ownership and tenant scope
- system capability
- confidentiality and access policy
- workflow state
- governance authority when applicable

Frontend visibility is never a security control.

An explicit scoped deny wins over a conflicting scoped allow for the same object and action. System permission never overrides governance authority.

Search, AI, reports, dashboards, Business Health, notifications, timeline, exports, and file downloads must not leak restricted records or restricted existence where that would violate access policy.

Business files are private by default.

AI must never receive unrestricted database access and may not approve, vote, sign, change ownership, issue payments, or create Effective business truth.

## Technical baseline

V1 is an approved **Modular Monolith**.

The target technical baseline is:

- Laravel backend
- Inertia
- Vue 3
- TypeScript
- Tailwind CSS with PBR design tokens
- PostgreSQL as the production database
- Redis for queues, cache, and short-lived coordination
- private S3-compatible object storage

Application layering follows:

```text
Presentation
-> Application Use Cases
-> Domain
-> Infrastructure
```

Core business logic does not belong in Vue components or controllers. Do not introduce a God Service or a universal JSON records table.

Production SQLite is not part of the approved architecture.

Microservices or Kubernetes must not be introduced without an approved architecture change.

**This section defines the approved target architecture. It does not claim that these components are already scaffolded, provisioned, deployed, or operational in the repository.**

## Protected product boundaries

The Public Website remains outside the private OS rebuild and must not be redesigned, restyled, or restructured as part of this repository.

PartnerDynamics remains a protected experience. The OS may provide authorization and business/partner context around it, but must not redesign it into generic OS UI or infer equity, governance authority, or system permissions from personality results.

The old Student Portal must not be copied or treated as the V3 application foundation.

## Language architecture

English, Myanmar, and Myanmar + English are first-class language modes.

Language changes content, not UI architecture. User-entered data is not automatically translated.

## Development discipline

Development proceeds through foundation and capability milestones from F0 through R1.

Every milestone follows the required gate sequence:

```text
G1 Scope
-> G2 Technical
-> G3 Domain
-> G4 Security & Integrity
-> G5 UX / UAT when required
-> G6 Commit + Annotated Tag + Freeze
```

If a required gate fails:

```text
STOP
-> Diagnose
-> Narrow Fix
-> Re-run the failed gate and required regressions
```

Do not continue feature work on top of a failed foundation.

Tenant Isolation, Authorization, Effective Record Immutability, and Historical Integrity are permanent regression requirements.

Development, Test/CI, Preview/Staging, and Production must remain isolated. Production data must not be used for development or testing.

## Repository guardrails

Before contributing, read:

- `CONTRIBUTING.md`
- `SECURITY.md`
- `.github/pull_request_template.md`

Repository changes should be narrow, reviewable, and tied to the currently authorized development step.

Do not weaken tests merely to make them pass. Do not silently deviate from the Master Specification.

## Implementation-status discipline

Architecture described in this README must not be interpreted as proof that a feature, service, environment, or infrastructure component is already implemented.

This README intentionally does not hard-code transient branch HEADs or remote commit SHAs.

Implementation progress must be established from the controlled development checkpoint, verified Git/server state, acceptance evidence, and accepted milestone tags.
