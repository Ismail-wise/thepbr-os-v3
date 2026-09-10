# Contributing to thePBR OS V3

thePBR OS V3 is developed as an independent private Business Operating System repository.

## Trusted branches

- `main` is the trusted integration baseline.
- Foundation work is performed on `feature/f0-foundation`.
- Later feature and fix branches should be short-lived and scoped.
- Do not perform routine feature development directly on `main`.

## Development gates

Every milestone follows:

G1 Scope -> G2 Technical -> G3 Domain -> G4 Security & Integrity
-> G5 UX/UAT where required -> G6 Commit + Annotated Tag + Freeze.

If a required gate fails:

STOP -> Diagnose -> Narrow Fix -> Re-run the failed gate and required regressions.

Do not continue feature work on top of a failed foundation.

## Architecture boundaries

The V1 architecture is a Modular Monolith using:

Presentation -> Application Use Cases -> Domain -> Infrastructure.

Core business rules do not belong in Vue components or thin HTTP controllers.
Do not introduce a God Service, universal JSON business-record table,
microservices, or Kubernetes without an approved architecture change.

## Protected external boundaries

This repository must not modify or use as its implementation foundation:

- the existing Public Website at `/var/www/thepbr-laravel`;
- the existing V2 Preview at `/var/www/thepbr-v2-preview`;
- the old Student Portal architecture/UI;
- the protected PartnerDynamics experience except at its approved integration phase.

PartnerDynamics does not determine equity or governance authority.

## Security and data integrity

Default deny.

Keep Ownership Rights, Governance Rights, System Permissions and
Document Permissions separate.

Business is the tenant/security boundary.

Effective official records are immutable. Changes create amendments/new
versions; historical approved records are never silently overwritten.

Formal live-truth changes follow:

Effective Baseline -> Scenario -> Compare -> Create Proposal
-> Frozen Proposal Version -> Review -> Decision / Approval
-> Signature if required -> Ready for Effect -> Effective Version.

A Scenario can never directly mutate live records.

Signed is not Effective. A fully signed document may still wait for a future
effective date or another required legal/business condition.

## Secrets

Never commit passwords, API keys, tokens, private keys, connection strings,
`.env` files, provider credentials, production database credentials or
production storage credentials.

Example environment templates may contain variable names and safe placeholders
only.

## Tests

Do not weaken or remove tests merely to make a change pass.

Tenant Isolation, Authorization, Effective Record Immutability and Historical
Integrity are permanent regression requirements.

PostgreSQL is the canonical V3 database. Database integration tests must not
silently substitute SQLite for PostgreSQL behavior.
