# F6C — Finance & Rewards Technical Scope Freeze

Baseline HEAD: 2fab105d4e09f61565085acd5b697b47c6d34ecb
Baseline tree: 5abfd1c8607281fb67d80a3fdc63f9ea835421bd
Branch: feature/f6-governance-operations
Status: architecture/source reconciliation complete; implementation authorized by owner.

## Source reconciliation

Authority order used:
1. 01_thePBR_OS_MASTER_SPEC_v1.1.md
2. PBR_MASTER_SOURCE_COMPLETE_2026.pdf
3. 03_DEVELOPMENT_ROADMAP_AND_GATES.md
4. accepted F2/F3/F5/F6A/F6B implementation evidence

No genuine source conflict was found.

Finance "Approver" is not a second Finance approval engine. A Finance Payment Authority Rule identifies the exact Governance decision type and operational transaction band; current approvers, voters, signatures and thresholds are resolved only by F6A ResolveGovernanceAuthority and executed only by the existing F3 Decision/Approval/Vote/Signature stack.

The PBR sources mention reimbursement in both Finance and Rewards. Canonical ownership is reconciled as follows: Finance owns expense/procurement validity, evidence and control requirements; Rewards owns reimbursement entitlement/timing for an already-valid business expense. The same fact is not stored canonically twice.

Role compensation references the exact Effective Operations Register role/assignment context. It never derives from shares or ownership percentage.

Profit distribution references an exact historical Effective Ownership Register version and immutable ownership positions/share-class profit rights. Finance/Rewards never becomes the canonical ownership source and never stores canonical ownership percentage.
## Purpose and boundaries

F6C delivers two connected command-center modules:
- Finance & Control: policy, bank/access metadata, payment controls, payments/evidence, reconciliation and exceptions.
- Profit, Salary & Distribution: reward policy, role compensation, reimbursement, bonus, loan repayment, reserve/reinvestment and Distribution Runs.

Cash Flow / Distribution calculators are monitoring or scenario tools only. They do not mutate Effective truth.

Out of scope:
- general-ledger/accounting replacement;
- payroll/tax filing engine;
- banking API credentials or money movement;
- legal/tax advice;
- F6D+ risk/continuity/conflict features;
- Preview or Production deployment;
- Public Website or PartnerDynamics redesign.

## Dependency map

F2:
- Formal Record Family/Version, effective-head/supersession, Proposal/Frozen Proposal Version, Evidence, Documents and optimistic concurrency.

F3:
- Proposal Review, Decision, Approval, Vote, Signature, Action, Review/Amendment, governed effectivity.

F5:
- current/historical Effective Ownership Register, immutable Positions and Share Classes, Partner ↔ Membership link.

F6A:
- ResolveGovernanceAuthority is the only approval-authority resolver.

F6B:
- current/historical Effective Operations Register supplies role, assignment and KPI context.

## Canonical source ownership

- Finance policy/control rules -> Effective `finance_policy` Formal Record.
- Bank account metadata/access/limits -> exact Finance Policy version children; never credentials/secrets.
- Governance approver/voter/signer eligibility -> Governance Authority Snapshot/Decision only.
- Operations role/responsibility/KPI -> Effective Operations Register only.
- Expense/procurement validity -> Finance Policy expense/procurement rules.
- Reimbursement entitlement/timing -> Reward Policy; referenced Finance expense rule remains the validity source.
- Salary/service fee -> Reward Policy rule referencing exact Operations role/assignment.
- Bonus eligibility/trigger -> Reward Policy rule referencing exact Operations role/KPI where applicable.
- Ownership/share/profit rights -> Effective Ownership Register only.
- Distribution calculation -> Distribution Run snapshot referencing exact Ownership Register, Finance Reconciliation and Reward Policy.
- Period profit/cash/tax/debt verification -> completed Finance Reconciliation Review.
- Payment evidence -> F2 Evidence linked to the controlled Finance Payment; usage classified as request support or payment proof.
- Finance exceptions/compensating review -> Finance Exception + append-only review history.
- Actual reward payment history -> Finance Payment records classified by reward payment type; no duplicate payment engine.

## Relational data model — Finance

Versioned policy:
- finance_policy_versions
- finance_bank_account_references
- finance_bank_access_assignments
- finance_payment_authority_rules
- finance_expense_procurement_rules

Operational controlled records:
- finance_reconciliation_reviews
- finance_exceptions
- finance_exception_reviews
- finance_payments
- finance_payment_submissions
- finance_payment_evidence_refs

Finance Policy header includes Finance Owner, Control Owner, Bookkeeping Owner, accounting method, fiscal period, base currency and controlled cash/closing/tax/audit rules.

Bank Account Reference stores name/reference/currency/purpose/status only. The schema has no password, PIN, OTP, token or secret columns; application normalization rejects secret-like payload keys.

Bank Access Assignment binds an active same-Business Membership to one policy-version bank reference with access level, signatory/backup flags, payment limit and access-review date.

Payment Authority Rule stores transaction type/range, exact requester Operations role key, exact Governance decision type, required payer access level, evidence requirement and segregation/compensating-review policy. It does not store canonical Governance approver membership or Governance thresholds.

## Relational data model — Rewards

Versioned policy:
- reward_policy_versions
- reward_role_compensation_rules
- reward_reimbursement_rules
- reward_bonus_rules
- reward_loan_repayment_rules
- reward_distribution_rules
- reward_distribution_status_rules
- reward_distribution_class_rules
Distribution execution:
- distribution_runs
- distribution_run_lines
- distribution_run_submissions
- distribution_run_payment_links

Reward Policy header includes Reward Owner, Finance Owner, currency, payment frequency, reserve/reinvestment controls, target/minimum cash controls and distribution decision type.

Role Compensation binds exact Operations Formal Record Version + Role + assigned Membership + Partner link. No ownership field participates in salary/service-fee calculation.

Distribution Run binds exact:
- Reward Policy Formal Record Version;
- Finance Policy Formal Record Version;
- completed Finance Reconciliation Review;
- historical Effective Ownership Register Version at record date;
- frozen run revision/content hash;
- F2 Formal Record Version + Frozen Proposal Version;
- F3 Governance Decision + Authority Snapshot when approved.

Distribution lines reference exact immutable Ownership Register Position/Share Class and store calculation outputs only. They do not store canonical ownership percentage.

## Finance workflow/state machines

Finance Policy:
Draft -> ReadyForReview -> UnderReview -> Approved -> Governance Proposal/Review/Decision -> ReadyForEffect -> Effective -> Superseded through existing F2/F3 primitives.

Finance Payment:
Draft -> Finance Verified / Frozen Proposal -> Governance Pending -> Authorized -> Paid -> Completed.
Rejected/Cancelled are terminal.
Core amount/rule/requester/bank/reward-source fields freeze at Finance Verification.

Finance verification requires a current Effective Finance Policy, exact matching Payment Authority Rule, requester role binding, required request evidence and a valid exact snapshot hash.

Governance authorization requires the approved F3 Decision for the exact Frozen Proposal Version, exact configured decision type and exact payment amount. No Finance-local approval boolean exists.

Payment requires an active Payer with FINANCE_PAY system capability plus exact same-policy Bank Access Assignment/access level/payment limit. System permission alone is insufficient.

Completed requires payment proof Evidence and reconciliation/control requirements.

Finance Reconciliation Review:
Open -> Completed or Exception.
Completed/Exception review facts are immutable.

Finance Exception:
Open -> CompensatingReview -> Cleared or Blocked/Resolved.
Compensating review is an independent finance control review, not Governance Approval.
## Segregation of duties

Default control goal is distinct Requester, Governance approval actor(s) and Payer.

Strict-three-way rules reject overlap.

Where the approved Finance Policy explicitly allows a small-business exception, overlap creates/requires a Finance Exception with:
- independent compensating reviewer;
- reviewer not equal to Requester/Payer or affirmative Governance approval/vote actor;
- verified evidence;
- explicit cleared/blocked review result.

No compensating review may create Governance authority or bypass a required Governance Decision.

## Rewards and Distribution workflow

Reward Policy uses the same Formal Record/Proposal/Governance lifecycle as Finance Policy.

Reward payment request:
- validate current Effective Reward Policy;
- validate category-specific rule;
- salary/service fee must match exact Operations role/assignment;
- reimbursement must reference an allowed Finance expense control;
- bonus must satisfy configured role/KPI context where used;
- loan repayment remains a distinct reward payment type;
- hand off to the single Finance Payment workflow for verification, Governance and payment evidence.

Distribution Scenario Simulator:
- pure Domain calculation;
- no database mutation;
- waterfall = Approved Net Profit - Tax - Debt Due - Required Reserve - Reinvestment +/- Approved Adjustments;
- cannot authorize, schedule or pay anything.

Distribution Run:
Draft -> Finance Verified / Frozen Proposal -> Governance Pending -> Approved/ReadyForEffect -> Payment Scheduled -> Completed + Effective.
Governance approval alone never makes the Run Effective.
The Run becomes Completed/Effective only after every linked distribution Finance Payment is Completed with payment evidence.

Allocation uses the Reward Policy basis against exact Ownership Register rights/shares. Canonical ownership percentage is never copied.

Manual distribution adjustment is allowed only when the Effective Reward Policy permits it and requires reason, evidence and Governance approval as part of the frozen Run.

## Authority and permission model

New system capabilities:
- finance.view
- finance.manage
- finance.pay
- rewards.view
- rewards.manage

These are system permissions only.

Finance Owner/Control Owner/Reward Owner/Requester/Payer assignments are controlled domain records. System capability cannot manufacture those assignments.

Approver/Voter/Signer authority comes only from F6A/F3 Governance.

Requester must satisfy the Payment Authority Rule's exact Operations role assignment.
Payer must satisfy Finance Bank Access Assignment and limit in addition to FINANCE_PAY.
Finance verification must be performed by the Effective Finance Policy Finance Owner or Control Owner with FINANCE_MANAGE.
Distribution Finance Verification uses the same Finance authority.
Reward policy management requires REWARDS_MANAGE and the controlled Reward Owner context where applicable.

Every sensitive action checks authenticated User, active Membership, current Business, resource tenant, capability, document/evidence policy, workflow state and Governance authority where applicable.

## Version/history/concurrency rules

Effective Finance and Reward policies are immutable; amendment creates a new Formal Record Version.
Frozen policy child rows are PostgreSQL-protected.
Operational payment snapshot fields freeze at Finance Verification.
Completed Reconciliation, cleared/blocked Exception review history, Paid/Completed Payment facts and completed Distribution facts are immutable.
All cross-record FKs are Business-bound where materially possible.
Exact source versions remain recorded so later Operations/Ownership/Finance/Reward/Governance changes never rewrite historical calculations or approvals.
Optimistic revision checks protect mutable drafts.
Critical freeze/authorize/pay/complete operations use transactions and row locks.
Idempotent repeat completion must not duplicate payment/distribution truth.

## PostgreSQL protection

F6C migrations will add relational checks/triggers for:
- same-Business + same-version policy children;
- frozen Formal Record child immutability;
- exact Operations role/assignment binding for requester and role compensation;
- Partner ↔ Membership binding for partner compensation;
- Bank Access/payment policy binding and payer limits;
- terminal reconciliation/exception/payment immutability;
- exact Finance Payment submission Proposal/Record identity;
- exact Distribution source tenant/version binding;
- Distribution line -> immutable Ownership position/class binding;
- Distribution payment link tenant integrity;
- no Distribution completion unless all linked payments are completed and the bound governed record can become Effective.

## CREATE manifest

- docs/checkpoints/F6C_FINANCE_REWARDS_TECHNICAL_SCOPE.md
- database/migrations/2026_09_27_000043_create_f6c_finance_tables.php
- database/migrations/2026_09_27_000044_create_f6c_rewards_tables.php
- app/Domain/Finance/Enums/FinancePaymentStatus.php
- app/Domain/Finance/Enums/FinanceExceptionStatus.php
- app/Domain/Finance/Enums/FinanceReconciliationStatus.php
- app/Domain/Finance/Services/SegregationOfDuties.php
- app/Domain/Rewards/Enums/DistributionRunStatus.php
- app/Domain/Rewards/Enums/RewardPaymentType.php
- app/Domain/Rewards/Services/DistributionCalculator.php
- app/Application/Finance/ResolveFinanceControl.php
- app/Application/Finance/FinancePolicyWorkflow.php
- app/Application/Finance/FinanceReconciliationWorkflow.php
- app/Application/Finance/FinanceExceptionWorkflow.php
- app/Application/Finance/FinancePaymentWorkflow.php
- app/Application/Finance/GetFinanceWorkspace.php
- app/Application/Rewards/ResolveRewardPolicy.php
- app/Application/Rewards/RewardPolicyWorkflow.php
- app/Application/Rewards/RewardPaymentWorkflow.php
- app/Application/Rewards/DistributionRunWorkflow.php
- app/Application/Rewards/SimulateDistribution.php
- app/Application/Rewards/GetRewardsWorkspace.php
- app/Infrastructure/Persistence/Eloquent/Finance/FinancePayment.php
- app/Infrastructure/Persistence/Eloquent/Finance/FinanceReconciliationReview.php
- app/Infrastructure/Persistence/Eloquent/Finance/FinanceException.php
- app/Infrastructure/Persistence/Eloquent/Rewards/DistributionRun.php
- app/Presentation/Http/Controllers/Finance/FinanceWorkspaceController.php
- app/Presentation/Http/Controllers/Rewards/RewardsWorkspaceController.php
- resources/js/pages/Finance/Index.vue
- resources/js/pages/Rewards/Index.vue
- tests/Unit/Domain/Finance/SegregationOfDutiesTest.php
- tests/Unit/Domain/Rewards/DistributionCalculatorTest.php
- tests/Feature/Finance/F6CFinancePolicyTest.php
- tests/Feature/Finance/F6CPaymentControlTest.php
- tests/Feature/Finance/F6CReconciliationExceptionTest.php
- tests/Feature/Finance/F6CTenantPermissionTest.php
- tests/Feature/Finance/F6CFinanceDatabaseInvariantTest.php
- tests/Feature/Rewards/F6CRewardPolicyTest.php
- tests/Feature/Rewards/F6CRewardPaymentTest.php
- tests/Feature/Rewards/F6CDistributionRunTest.php
- tests/Feature/Rewards/F6CRewardsDatabaseInvariantTest.php
- tests/E2E/f6c-finance-rewards.spec.ts
- tests/E2E/support/prepare-f6c-e2e.php

## MODIFY manifest

- app/Domain/Access/CapabilityCatalog.php
- app/Domain/Access/StandardAccessProfileMatrix.php
- app/Application/Evidence/EvidenceTargetRegistry.php
- app/Application/Governance/GetGovernanceCommandCenter.php
- resources/js/pages/Governance/Index.vue
- resources/js/components/WorkspaceNavigation.vue
- resources/js/i18n/catalog.ts
- routes/web.php
- tests/Feature/Access/F3StandardAccessProfilesTest.php
- tests/Feature/Evidence/EvidencePrivacyTest.php
- .github/workflows/ci.yml
## DELETE manifest

None.

The MODIFY manifest is an authorized ceiling; a listed support file may remain unchanged if implementation does not need it.
Any path outside CREATE + MODIFY is out of scope. If later evidence proves another tracked path is necessary, STOP at G1 and request an explicit owner-approved scope addendum. Do not rewrite this freeze.

### Approved G1 one-file scope addendum — 2026-09-27

Owner approval adds only `tests/Feature/Evidence/EvidencePrivacyTest.php` to the F6C MODIFY ceiling so the existing closed Evidence target registry expectation can include the four F6C-approved targets: `finance_payment`, `finance_reconciliation`, `finance_exception`, and `distribution_run`. The original targets remain required. This addendum does not authorize weakening, deleting, bypassing, or otherwise broadening the closed-registry security test.

## Test matrix

Unit:
- SegregationOfDuties strict vs compensating-review behavior.
- Distribution waterfall arithmetic, negative/zero distributable protection, deterministic allocation/rounding.

Domain/Feature:
- Finance Policy and Reward Policy F2/F3 version/effect lifecycle.
- no plaintext-secret payload acceptance/schema fields.
- exact Effective Finance/Reward source resolution.
- requester Operations role binding.
- payer bank access/access-level/payment-limit enforcement.
- Governance decision type/amount/Proposal/Authority Snapshot binding.
- no second approval engine.
- required request evidence and payment-proof evidence.
- reconciliation completion/history.
- exception + independent compensating review.
- salary/service fee linked to Operations role and independent of ownership.
- reimbursement Finance-rule reference.
- bonus role/KPI binding where configured.
- loan repayment remains distinct.
- Distribution source reconciliation/Ownership/Finance/Reward version binding.
- Distribution Finance Verification -> Governance approval -> payments/evidence -> completion/effect sequence.
- scenario simulator performs zero writes.
- manual distribution adjustment policy/reason/evidence guard.
- no canonical ownership percentage on Finance/Rewards tables.

Security/integrity:
- Permission Matrix and System Permission != Finance/Governance authority.
- cross-Business IDs, bank refs, roles, partners, ownership positions, evidence, proposals and decisions fail closed.
- tenant isolation/default deny.
- Effective Record Immutability.
- Historical Integrity.
- optimistic concurrency/idempotency.
- PostgreSQL trigger/constraint tests.

Regression:
- permanent F1–F6A/F6B authorization, tenant isolation, immutability and historical-integrity suites remain green.
- GitHub exact-head CI is authoritative for full PostgreSQL, frontend typecheck/build and Chromium.
- deterministic F6C Chromium journey is added to CI; no local Chromium on the VPS.

## Human-G5 Pending Ledger

- F5 Boss UAT 4: DEFERRED / NOT PASS; no F5 G6/tag.
- F6A/F6B Boss UAT 5: DEFERRED / NOT PASS; no F6A/F6B G6/tag.
- F6C Boss UAT 6: may remain DEFERRED / NOT PASS.
- If F6C Boss UAT 6 is deferred, do not create `pbr-os-v3-f6c-finance-rewards` and do not perform G6.
- Preview remains accepted F4 until separately authorized.
- Production/Public Website/PartnerDynamics remain untouched.

## Freeze rule

This document is the F6C G1 implementation boundary. Architecture/source reconciliation is complete. Implementation may proceed only inside this manifest and must preserve every invariant above.

## Architecture clarification — canonical Finance Owner

During implementation mapping, the Reward Policy header was corrected to avoid duplicating the Finance Owner fact.

The Effective Finance Policy is the sole canonical source for Finance Owner / Control Owner / Bookkeeping Owner assignments. Reward Policy stores only its exact Finance Policy Formal Record Version reference and derives Finance ownership/control context from that source.

Therefore F6C does NOT store a duplicate `finance_owner_membership_id` in Reward Policy. This clarification changes no CREATE/MODIFY/DELETE path and does not expand scope; it strengthens the frozen one-fact/one-canonical-source invariant. The original freeze remains preserved above.
