# PBR Simple — အဆင့် ၁: အသုံးပြုမည့် scope

Date: 2026-10-09 (Asia/Bangkok)

Status: G1 scope preparation / review document. Application implementation, G2–G6 acceptance and R1 release are pending. This document does not certify production readiness.

## ၁။ ကျွန်တော်တို့ လုပ်မည့်အရာ

`Ai_Autono_PBR_Simple` ရဲ့ ရိုးရှင်းတဲ့အသုံးပြုပုံကို V3 ရဲ့ ရှိပြီးသား backend နဲ့ ချိတ်မယ်။ ပထမမှာ လုပ်ငန်းတစ်ခုအတွက် သီးသန့်စမ်းသပ်မယ်။ နောက်ခံစနစ်မှာ လုပ်ငန်းတစ်ခုစီရဲ့ အချက်အလက်နဲ့ အသုံးပြုခွင့်ကို ဆက်ခွဲထားမယ်။

ပထမဆုံး လုပ်မည့် flow က **Login → လုပ်ငန်းရွေးခြင်း → Dashboard** ဖြစ်တယ်။ ဒါပြီးမှ မိတ်ဖက်၊ ငွေကြေးနဲ့ အတည်ပြုမှုတွေကို တစ်ပိုင်းချင်း ဆက်မယ်။

## ၂။ အခြေခံယူမည့် code

| Item | Verified baseline |
|---|---|
| Repository | `Ismail-wise/thepbr-os-v3` |
| Source branch | `feature/pbr-new-system-redesign` |
| Exact source commit | `710cbb4e6de048ae94aa0db45cadd9a8624f0de7` |
| Exact source tree | `9bf506967d34559dcaa2cf90b346175f9cff1389` |
| Existing CI evidence | [CI #186 — success on the exact source commit](https://github.com/Ismail-wise/thepbr-os-v3/actions/runs/37620626135) |
| Scoped working branch | `feature/pbr-simple-production` |

Branch နာမည်မှာ `production` ပါတာက ရည်ရွယ်ချက်ကို ဖော်ပြတာသာဖြစ်တယ်။ Production-ready အထောက်အထား သို့မဟုတ် release approval မဟုတ်ဘူး။

V3 tests ကို ဒီပြင်ဆင်မှုအတွက် local မှာ ပြန်မ run ရသေးပါ။ အထက်က CI result က source commit အတွက် အထောက်အထားဖြစ်တယ်။ ဒီစာတမ်းက documentation-only preparation ဖြစ်တယ်။

## ၃။ ရိုးရှင်းသော Menu ၅ ခု

| Menu | အဓိကလုပ်နိုင်မည့်အရာ | ဆက်သုံးမည့် V3 domain |
|---|---|---|
| ပင်မစာမျက်နှာ | အာရုံစိုက်ရန်၊ လုပ်ငန်းအခြေအနေ၊ လုပ်ငန်းစတင်သတ်မှတ်ချက်၊ အစီရင်ခံစာ | Overview; Business Profile; Business Model; Legal Structure; Reports |
| မိတ်ဖက်နှင့် ရှယ်ယာ | မိတ်ဖက်၊ စစ်ဆေးမှု၊ PartnerDynamics၊ ထည့်ဝင်မှု၊ ရှယ်ယာစာရင်း၊ မိတ်ဖက်ဝင်/ထွက်/လွှဲပြောင်းမှု | Partners; Due Diligence; PartnerDynamics; Contributions; Ownership; Changes |
| ငွေကြေး | အရင်းလိုအပ်မှု၊ ငွေတောင်းခံမှု၊ ငွေပေးချေမှု၊ စာရင်းတိုက်စစ်မှု၊ လစာ/အမြတ်ခွဲဝေမှု | Capital; Finance; Rewards |
| လုပ်ရန်နှင့် အတည်ပြုရန် | တာဝန်၊ ဆောင်ရွက်ရန်၊ ပြန်လည်စစ်ဆေးရန်၊ ဆုံးဖြတ်ချက်၊ အစည်းအဝေး၊ မဲ/အတည်ပြု/လက်မှတ် | Operations; Governance Decisions/Meetings; My Work; My Approvals; My Signatures |
| စည်းကမ်းနှင့် စာရွက်စာတမ်း | စည်းကမ်း၊ စာရွက်စာတမ်း၊ အထောက်အထား၊ မှတ်တမ်း၊ အန္တရာယ်/ဆက်လက်လည်ပတ်ရေး/အငြင်းပွားမှု | Governance Rules; Records/Vault/Activity; Protection |

Business Switcher၊ My Businesses၊ Search၊ Notifications နဲ့ Profile/Settings ကို account/shell controls အဖြစ် ဆက်ထားမယ်။ လက်ရှိလုပ်ငန်းအမည်ကို အမြဲမြင်ရမယ်။

### ACP-SIMPLE-001: Navigation grouping proposal

Master Spec sections 8–9 define the existing information architecture. The table above explicitly proposes five primary entry points with all original domain destinations retained as child links or contextual destinations. This is a recorded navigation change proposal, not a silent replacement of the frozen navigation baseline. The user's instruction to proceed authorizes preparation of this scope; the detailed grouping remains a review item before navigation implementation.

No domain is deleted or merged into another domain's canonical record. Formal workflows retain full pages; the existing authorized routes and backend controls remain authoritative. Accessible mobile navigation, EN/MM/Mixed modes, record-state visibility, and the protected PartnerDynamics experience remain required.

## ၄။ ပြန်သုံးမည့် backend

| Capability | Existing code anchor / required behavior |
|---|---|
| Authentication | `app/Application/Identity/AuthenticateAccount.php`; existing login/session/password controls |
| Business context | `app/Http/Middleware/EnsureCurrentBusinessContext.php`; active membership and business-scoped queries |
| Authorization | `app/Application/Access/AuthorizeBusinessCapability.php`; default deny and scoped restrictions |
| Dashboard | `app/Application/Dashboard/GetBusinessControlCenter.php`; only authorized data, counts and attention items |
| Contributions | `app/Application/Partnership/ContributionWorkflow.php`; Proposed → Reviewed → Approved → Delivered → Accepted |
| Ownership | `app/Application/Partnership/OwnershipWorkflow.php`; Accepted contributions feed scenarios; formal approval produces a new official register version |
| Payments | `app/Application/Finance/FinancePaymentWorkflow.php`; required authority, evidence and reconciliation; separate Requester/Approver/Payer responsibilities |
| Approval / signature / effectivity | Existing Governance use cases, including `RecordGovernanceApproval.php`, `SignGovernanceDocument.php`, `MakeGovernedRecordEffective.php`; separate states, exact frozen versions and authority snapshots |
| Private documents | `app/Application/Documents/DownloadDocumentVersion.php`; document/version/business authorization before download |
| Audit / history | `app/Application/Audit/AppendAuditEvent.php` and existing versioned-record workflows; immutable official history |
| AI | `app/Application/AI/BuildAuthorizedAiContext.php`; filtered context and advisory output |

UI button တစ်ခုချင်းက သက်ဆိုင်ရာ V3 use case ကို ခေါ်မယ်။ ဥပမာ **ငွေတောင်းခံမယ် → လိုအပ်သောအတည်ပြုချက် → ငွေပေးချေမှုအထောက်အထား → စာရင်းတိုက်စစ်မှု/ပြီးစီးမှု** ကို ရှိပြီးသား payment workflow နဲ့ ဆောင်ရွက်မယ်။

## ၅။ ပထမ implementation အပိုင်း

Scope: authentication, account/business selection, visible current-business context, authorized dashboard data, and navigation entry points after the navigation proposal is reviewed.

Acceptance checks:

1. မှန်ကန်သော account နဲ့ login ဝင်၊ logout ထွက်နိုင်ရမယ်။
2. Active membership ရှိသော လုပ်ငန်းကိုသာ ရွေးနိုင်ရမယ်။
3. လက်ရှိလုပ်ငန်းအမည်ကို Dashboard နဲ့ page shell မှာ မြင်ရမယ်။
4. Business A account က Business B URL/object ID ကို ပြောင်းခေါ်သော်လည်း အချက်အလက် မမြင်ရဘူး။
5. Dashboard totals၊ attention items နဲ့ navigation visibility က အသုံးပြုခွင့်ရှိသော data အတိုင်းသာ ဖြစ်ရမယ်။ Link ကိုဖျောက်ရုံနဲ့ authorization ကို အစားမထိုးရဘူး။
6. EN/MM/Mixed ပြောင်းလဲသော်လည်း flow နဲ့ structure တူရမယ်။ Mobile နဲ့ keyboard နဲ့ အသုံးပြုနိုင်ရမယ်။
7. Login → Business selection → Dashboard ကို သီးသန့် Preview/Test မှာ လူကိုယ်တိုင် စမ်းရမယ်။

Reuse meaningful existing tests first. Add focused checks only for changed behavior. Tenant isolation, authorization, effective-record immutability and historical integrity remain permanent regressions. Database checks use PostgreSQL.

## ၆။ သီးခြားဖြေရှင်းရမည့် gaps

| Gap / decision | Required next action |
|---|---|
| Navigation grouping | Review ACP-SIMPLE-001 and record the chosen grouping before changing the shell. |
| PartnerDynamics result experience | Preserve the eight profiles, original content, illustrations and result experience under a minimal OS wrapper. The inspected V3 result screen is incomplete against the preserved website source. Verify the current authoritative source before the PartnerDynamics implementation slice. |
| PartnerDynamics source provenance | The ZIP's preserved website baseline is `d769107cebd76eb9197066468cabd75914093d29`; another redesign branch exists. This baseline is evidence, not a claim that the deployed current experience was visually inspected. |
| Development environment | Identify the user's computer or isolated test server before issuing installation or checkout commands. Verify repository, branch and commit before any runtime changes. |
| Pilot business | Choose New or Existing Business. Follow the corresponding master journey; an Existing Business needs its verified financial/owner/obligation baseline. |
| Seed/demo data | Keep demo fixtures in Test/Preview. Do not import demo PINs, hard-coded identities, sample money, approvals or signatures as official business truth. |
| Runtime provisioning | PostgreSQL, Redis, private storage, queues, secrets and AI provider need isolated setup and verification. Repository config and successful CI do not prove these services are provisioned. |
| Operational recovery | Business export is separate from whole-system backup. An actual isolated backup restore drill is required before R1 release. |

The original PDF business rules and Master Spec remain authoritative. A five-menu UI must still distinguish Contribution, Ownership, Governance Authority, System Access, Document Access, Salary and Profit Distribution. Approval, Signature and Effective state remain separate.

## ၇။ တစ်ဆင့်ချင်း လုပ်မည့်အစီအစဉ်

1. **ဒီစာတမ်းကိုဖတ်ပြီး scope နဲ့ menu grouping ကို စစ်မယ်။** Source၊ reuse mapping၊ ပထမ flow နဲ့ gaps ကို ရှင်းလင်းထားမယ်။
2. **စမ်းသပ်မည့်နေရာကို သတ်မှတ်မယ်။** Windows/Mac computer သို့မဟုတ် သီးသန့် test server ဖြစ်နိုင်တယ်။ နေရာသိပြီးမှ အဲဒီနေရာနဲ့ကိုက်ညီတဲ့ command ကို တစ် block စီပေးမယ်။
3. **V3 baseline ကို စမ်းသပ်ပတ်ဝန်းကျင်မှာ ဖွင့်မယ်။** Repository/branch/hash နဲ့ baseline checks ကို အရင်အတည်ပြုမယ်။ မမျှော်လင့်ထားတဲ့ result ရရင် ရပ်ပြီး အရင်ဖြေရှင်းမယ်။
4. **Login → Business → Dashboard ကို ပြင်ပြီး စမ်းမယ်။** ပထမ flow နဲ့ permissions အောင်မြင်မှ နောက်အပိုင်းကို ဆက်မယ်။
5. **မိတ်ဖက်၊ ငွေကြေး၊ အတည်ပြုမှုနဲ့ စာရွက်စာတမ်းကို တစ်ပိုင်းချင်း ဆက်မယ်။** PartnerDynamics source gap ကို သက်ဆိုင်ရာအပိုင်းမစမီ ဖြေရှင်းမယ်။
6. **Preview မှာ လူကိုယ်တိုင် စမ်းသုံးမယ်။** ကိုယ့်လုပ်ငန်းပုံစံနဲ့ workflow တစ်ခုချင်း ပြီးဆုံးနိုင်မှုကို စစ်မယ်။
7. **R1 checks ပြီးမှ release ကို စီစဉ်မယ်။** Regression၊ security၊ migration၊ backup/restore၊ performance၊ accessibility၊ languages၊ final UAT နဲ့ rollback plan ပါရမယ်။

This preparation does not modify application code, seed data, deployed services or database state. No production deployment is part of Step 1. The existing `docs/ONE_DAY_MVP_DEBT.md` identifies the source as a Preview candidate and states: “Do not deploy Production.”

## Source authority and gate record

- `01_thePBR_OS_MASTER_SPEC_v1.1.md`: sections 2, 6, 8–9, 14–16, 19, 21–22, 25–30 and 32.
- `02_SOURCE_TRACEABILITY_MAP.md`: domain chains and canonical-source invariants.
- `03_DEVELOPMENT_ROADMAP_AND_GATES.md`: G1 → G6 and R1 requirements.
- Repository `CONTRIBUTING.md`, `docs/adr/ADR-015-partnerdynamics-protected-boundary.md`, and `docs/ONE_DAY_MVP_DEBT.md`.
- G1: scope document prepared; navigation/source/environment review items are explicit.
- G2–G5: pending for the implementation slices.
- G6: no milestone freeze or production-ready tag created by this preparation.
- R1: pending; no production-readiness claim.
