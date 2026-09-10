# Security Policy

Security-sensitive development in thePBR OS V3 follows default-deny rules.

## Protected data boundaries

Business is the tenant boundary. Sensitive backend actions must verify the
authenticated user, active membership, current business, resource ownership,
system capability, confidentiality/access policy, workflow state and
governance authority where applicable.

Frontend visibility is never an authorization boundary.

A scoped deny wins over a conflicting scoped allow for the same object/action.
System permission does not create governance authority.

## Derived systems

Search, reports, dashboards, health, notifications, timelines, files and AI
must not leak restricted records or restricted existence where inappropriate.

AI must not receive unrestricted database access and must not approve, vote,
sign, change ownership, issue payment or create Effective truth.

## Credentials and secrets

Do not commit:

- passwords or access tokens;
- API/provider credentials;
- private keys;
- database connection strings;
- Redis credentials;
- object-storage credentials;
- production `.env` content.

If a secret is exposed, stop development on the affected scope and handle
rotation/remediation explicitly. Do not hide the incident by merely deleting
the visible line in a later commit.

## Production boundary

Development and automated testing must never use the Production database.

Development, Test/CI, Preview/Staging and Production require isolated
credentials and data boundaries.
