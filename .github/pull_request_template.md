## Scope

- [ ] The change is inside the approved milestone/work-item scope.
- [ ] No unrelated Public Website, V2 Preview or PartnerDynamics change is included.
- [ ] No architecture deviation is hidden inside this PR.

## Architecture

- [ ] Presentation/Application/Domain/Infrastructure boundaries are respected.
- [ ] Core business logic is not implemented in Vue components or thin controllers.
- [ ] No God Service, universal JSON record model or unapproved service split is introduced.

## Security & integrity

- [ ] Business tenant isolation has been considered.
- [ ] Authorization is enforced backend-side, not only through UI visibility.
- [ ] Ownership, Governance, System and Document rights remain separate.
- [ ] Effective/history/version invariants remain intact.
- [ ] Derived data/search/report/AI paths do not leak restricted information.
- [ ] No secrets or production credentials are committed.

## Workflow

- [ ] Formal live-truth changes preserve: Effective Baseline -> Scenario -> Compare -> Create Proposal -> Frozen Proposal Version -> Review -> Decision / Approval -> Signature if required -> Ready for Effect -> Effective Version.
- [ ] A Scenario cannot directly mutate live records.
- [ ] Signed is not Effective; effectivity remains a separate state/condition.
- [ ] Decision, Vote, Approval and Signature remain distinct concepts.

## Tests

- [ ] Relevant tests were added or updated.
- [ ] Required PostgreSQL integration behavior is tested where applicable.
- [ ] Tenant Isolation regression passes where applicable.
- [ ] Authorization regression passes where applicable.
- [ ] Effective Record Immutability regression passes where applicable.
- [ ] Historical Integrity regression passes where applicable.
- [ ] Existing tests were not weakened merely to obtain a pass.

## Gates

- [ ] G1 Scope
- [ ] G2 Technical
- [ ] G3 Domain
- [ ] G4 Security & Integrity
- [ ] G5 UX/UAT where required
- [ ] G6 is performed only when the milestone is actually ready to freeze.
