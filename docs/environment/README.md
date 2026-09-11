# F0 Environment Architecture & Isolation Contract

**Project:** thePBR OS V3
**Checkpoint:** F0-E1 — Environment Architecture & Isolation Contract
**Status:** Draft implementation evidence until this checkpoint passes its gates

## Purpose

This checkpoint implements the environment-isolation contract required by the
frozen thePBR OS V3 Master Specification without provisioning infrastructure.

It does not authorize PostgreSQL installation, Redis installation, Preview
deployment, Nginx/DNS/TLS changes, object-storage provider selection,
Production infrastructure, or F1 work.

## Required environments

thePBR OS V3 maintains four logically and operationally isolated environments:

| Environment | Purpose | Production data allowed? | Current F0 state |
| --- | --- | --- | --- |
| Development | Local/non-production development and engineering checks | No | Contract only |
| Test / CI | Automated verification and disposable integration testing | No | GitHub Actions CI exists |
| Preview / Staging | V3 deployment verification and Boss UAT | No | Not provisioned |
| Production | Real production workload | Production only | Not provisioned / not authorized in F0 |

No environment may silently reuse another environment's database, storage,
secrets, queue credentials, provider credentials, or application key.

## Database isolation

PostgreSQL is the canonical V3 database.

Each environment must use its own database and credentials. Development,
Test/CI, Preview/Staging and Production databases must remain isolated.

Production database credentials and Production data must never be used by
Development, Test/CI or Preview/Staging.

F0-E1 does not install PostgreSQL or create databases/users. That work belongs
to a separately approved PostgreSQL foundation checkpoint.

## Redis, cache and queue isolation

Redis is the approved V1 technology for queues, cache and short-lived
coordination.

Redis provisioning is not part of F0-E1. Development may remain on the
framework-safe file/sync defaults until the Redis checkpoint is approved.

Preview and Production templates describe the target Redis contract only.
They do not prove Redis is installed or operational.

Queue workers, Horizon or other persistent worker processes are not authorized
by this document. They require a separately approved runtime checkpoint.

A scheduler service is also not implied by this checkpoint. It is introduced
only when approved scheduled application work requires it.

## Storage isolation

Business files are private by default.

The approved V1 architecture uses private S3-compatible object storage for
file bytes, with database records holding metadata/storage references.

No S3-compatible provider is selected or provisioned by F0-E1.

During the F0 Preview foundation, local private application storage may be used
only where explicitly approved and only as non-Production Preview storage.
Production target configuration remains private S3-compatible storage.

Development, Test/CI, Preview/Staging and Production storage must not share
credentials or canonical object namespaces.

## Secrets

Runtime secrets must never be committed.

This repository may contain only variable names and safe placeholders in the
non-secret example templates under `docs/environment/templates/`.

Actual runtime `.env` files remain untracked and must be created through the
approved environment/provisioning process.

Never commit:

- real `APP_KEY` values
- database passwords
- Redis passwords
- object-storage access/secret keys
- provider API keys
- production credentials
- plaintext passwords, bank PINs or OTP seeds

## Template naming

The repository intentionally uses:

```text
development.env.example
test-ci.env.example
preview.env.example
production.env.example
```

rather than tracked `.env*` files.

The accepted CI Foundation currently rejects tracked `.env`-style files.
F0-E1 preserves that closed CI guardrail instead of weakening it.

These templates are examples only. They are not runtime environment files and
are never proof that an environment is provisioned.

## Preview / Staging boundary

Preview/Staging is the only environment used for Boss UAT.

F0-E1 does not choose the Preview filesystem path, hostname, DNS record, TLS
certificate, deployment process or Nginx configuration. Those decisions belong
to later explicitly approved F0 Preview checkpoints.

Preview must remain isolated from:

- the existing Public Website
- the existing V2 Preview
- PartnerDynamics
- unrelated existing services, including the existing PBR AI Advisor service
- Production data and Production credentials

## Production boundary

Production infrastructure is not created or modified during F0-E1.

Production readiness later requires, among other applicable gates:

- PostgreSQL migration verification
- security review
- full required regression testing
- database backup architecture
- document/object-storage backup architecture
- an actual isolated restore drill
- release and rollback planning
- Production configuration review

A backup configuration alone is not proof of recovery. Recovery is proven only
after a successful isolated restore test.

## F0-E1 negative scope

This checkpoint does not authorize:

- PostgreSQL installation or database/user creation
- Redis installation or configuration
- PHP extension installation
- queue worker deployment
- scheduler deployment
- Supervisor installation
- Preview deployment
- Nginx changes
- DNS changes
- TLS changes
- S3-compatible provider selection/provisioning
- Production infrastructure
- Public Website changes
- V2 Preview changes
- PartnerDynamics changes
- F1 authentication, User, Business, Membership or Business Switcher work
- milestone tag creation

Later checkpoints must re-verify the accepted Git and protected-boundary
baselines before performing infrastructure writes.
