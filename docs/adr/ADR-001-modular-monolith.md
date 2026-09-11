# ADR-001 — Modular Monolith

**Status:** Accepted — Frozen Architecture Baseline

This architecture acceptance does NOT mean this capability is implemented, deployed, tested, operational, or F0 milestone-accepted.

## Architecture / Source Authority

`01_thePBR_OS_MASTER_SPEC_v1.1.md` is the frozen architecture authority. Section 31 fixes this ADR title as **Modular Monolith**.

## Context

thePBR OS V1 must support many strongly related business domains while preserving clear domain boundaries, transaction integrity, tenant isolation, version history, authorization and governed workflows. The frozen architecture explicitly selects a Modular Monolith for V1 rather than beginning with distributed services.

## Decision

V1 will be implemented as a **Modular Monolith**.

The application will preserve explicit module boundaries while remaining one coherent application architecture. Application layering follows:

`Presentation -> Application Use Cases -> Domain -> Infrastructure`

Core business logic must not live in Vue components or thin transport/controller layers. The architecture must not collapse into a universal God Service.

Microservices or Kubernetes are not part of the V1 baseline and require an explicit approved architecture change if later justified.

## Required Invariants

- Domain boundaries remain explicit inside the monolith.
- Core business rules remain in domain/application layers rather than presentation code.
- Business isolation, permissions, governance authority, workflow and historical integrity apply across all modules.
- Cross-module operations that affect critical truth must preserve transactional consistency.
- A shared deployable architecture must not become a universal-record or universal-service design.
- V1 must not silently evolve into microservices or Kubernetes.

## Consequences

The initial system can share deployment, transactions and infrastructure while still enforcing modular responsibilities. Module boundaries must remain strong enough to prevent uncontrolled coupling and to allow deliberate future architectural evolution if an approved need arises.

## Security & Integrity Implications

Central deployment does not relax tenant isolation or default-deny authorization. Sensitive actions still require Business context, permissions, access policy, workflow state and governance authority where applicable.

## Verification / Testing Implications

Architecture review must verify module boundaries, application layering and the absence of business logic in Vue/controllers. Foundation and later regression testing must continue to cover Tenant Isolation, Authorization, Effective Record Immutability and Historical Integrity.

## Source Traceability

- `# 25. Technical Constitution`
- `## 25.1 Architecture`
- `## 25.2 Application layers`
- `## 25.3 Domain modules`
- `## 25.6 Transactions and consistency`
- `# 33. Explicitly banned anti-patterns`
- `# 31. Architecture Decision Record starter set`

## Change Control

A change away from this frozen architecture requires an explicit Architecture Change Proposal. Do not silently introduce microservices, Kubernetes, a God Service, or another conflicting architecture.
