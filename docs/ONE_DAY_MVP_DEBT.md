# One-Day Grade-6 MVP — Deferred Quality Work

Baseline strategy: complete the full safe user journey first, then harden and polish in later passes.

## Known test debt

- CI #183 exposed a timing race in the F5 Contribution browser journey around the review form watcher.
- The issue is test synchronization under slower CI conditions. Do not hide it with retries or a larger timeout.
- Product canonical Contribution logic remains unchanged by the One-Day MVP journey work.

## Resolved during the MVP sprint

- CI #184 exposed a stale F4 browser expectation after the Master Journey was intentionally changed to open by default.
- The Product behavior stays open by default. The test now preserves an already-open journey and only expands it when needed.
- The F4 journey passes locally against a fresh deterministic fixture after that repair.

## Deferred after MVP

- Perfect visual polish and animation
- Pixel-perfect mobile layout
- Exhaustive Burmese / mixed-language QA
- Exhaustive accessibility polishing
- Advanced Partner AI completion
- Advanced Import / Export refinement
- Advanced E-Signature experience
- Exhaustive edge-case hardening
- Minor copy consistency cleanup
- Full flaky-browser-test hardening

## Safety rules that remain non-negotiable

- Tenant / Business isolation
- Authentication, capability and authorization boundaries
- Canonical official truth and immutable history where required
- Evidence / source provenance where already available
- Draft / Scenario / Official Truth separation
- Contribution is not Ownership
- Ownership is not Role, Governance authority, Salary or Profit Distribution policy
- Approval is not Signature and is not Effective state
- Planning / simulator tools never silently mutate official records
- No fake approvals, signatures, effective records or completion states

## Deployment

This One-Day MVP work is a Preview candidate only after explicit user authorization.
Do not deploy Production.
