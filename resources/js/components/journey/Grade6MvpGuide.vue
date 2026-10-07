<script setup lang="ts">
import { Link } from '@inertiajs/vue3';

type Guide = {
    title: string;
    what: string;
    why: string;
    known: string;
    decide: string;
    nextLabel: string;
    nextHref: string;
};

const props = defineProps<{
    step:
        | 'governance'
        | 'operations'
        | 'finance'
        | 'rewards'
        | 'risk'
        | 'partner_changes'
        | 'exit'
        | 'conflict'
        | 'continuity'
        | 'closure'
        | 'implementation_review';
}>();

const guides: Record<typeof props.step, Guide> = {
    governance: {
        title: 'Governance / Decision Rules',
        what: 'Set the simple rules for who can decide, approve, vote and sign.',
        why: 'Clear decision rules reduce confusion and stop one person from silently changing official Business truth.',
        known: 'The system already knows your Business, active members, existing records and any authority already established.',
        decide: 'Choose the decision rules that fit the Business. Approval, Signature and Effective state remain separate.',
        nextLabel: 'Continue to Roles / Operations',
        nextHref: '/operations',
    },
    operations: {
        title: 'Roles / Operations',
        what: 'Decide who is responsible for the important work and how progress will be checked.',
        why: 'Clear roles make daily work easier and show who owns each result without turning Ownership into a job title.',
        known: 'The system already knows your Business members and current governed setup that you are allowed to see.',
        decide: 'Define the main roles, responsibilities, reporting rhythm and the few KPIs that matter most.',
        nextLabel: 'Continue to Finance & Control',
        nextHref: '/finance',
    },
    finance: {
        title: 'Finance & Control',
        what: 'Set safe rules for money, payments, evidence and reconciliation.',
        why: 'The Business needs a clear way to control money without confusing payment authority with Ownership or Governance authority.',
        known: 'The system can reuse current members, Operations roles, Business currency and existing governed Finance records.',
        decide: 'Set the Finance owners, bank/payment controls and the basic rules for evidence and reconciliation.',
        nextLabel: 'Continue to Salary / Bonus / Profit',
        nextHref: '/rewards',
    },
    rewards: {
        title: 'Salary / Bonus / Profit Distribution',
        what: 'Separate pay for work from rewards and profit distribution.',
        why: 'Salary, bonus, profit rights and Ownership are different things. Keeping them separate prevents unfair or accidental payouts.',
        known: 'The system can reuse members, Operations roles, Finance truth and current Ownership information where authorized.',
        decide: 'Choose the basic Salary, bonus and distribution rules. Do not treat Ownership percentage as an automatic Salary.',
        nextLabel: 'Continue to Risk / Insurance / Protection',
        nextHref: '/risk',
    },
    risk: {
        title: 'Risk / Insurance / Protection',
        what: 'List the biggest risks and decide how the Business will reduce, transfer or prepare for them.',
        why: 'The goal is not to predict everything. It is to make the most important risks visible before they become emergencies.',
        known: 'The system can reuse current Business, members, Operations and governed records that are already available.',
        decide: 'Focus on the few risks that could seriously hurt people, cash, operations, legal position or Business continuity.',
        nextLabel: 'Continue to Share Transfer / New Partner',
        nextHref: '/changes/partner-changes',
    },
    partner_changes: {
        title: 'Share Transfer / New Partner',
        what: 'Plan a safe change when shares, partners or partner status may change.',
        why: 'A partner change can affect Ownership, Governance and records, so it needs a traceable process instead of an informal edit.',
        known: 'The system already has the current Partner and Ownership truth that you are authorized to use.',
        decide: 'Record the proposed change and follow the governed process. A proposal does not silently rewrite official Ownership.',
        nextLabel: 'Continue to Exit / Buyout',
        nextHref: '/changes/exit',
    },
    exit: {
        title: 'Exit / Buyout',
        what: 'Plan what should happen when a partner leaves or is bought out.',
        why: 'Exit terms affect people, shares, payments and continuity. Recording the process early reduces conflict later.',
        known: 'The system can reuse current partners, Ownership, Finance links and governed decision rules where available.',
        decide: 'Choose the exit path, requirements and payment structure that apply. Do not mark settlement complete before the real conditions are met.',
        nextLabel: 'Continue to Conflict / Resolution',
        nextHref: '/conflict',
    },
    conflict: {
        title: 'Conflict / Resolution',
        what: 'Use a clear path to record, discuss, escalate and resolve serious Business conflict.',
        why: 'A visible process protects the Business from informal decisions, deadlock and missing evidence.',
        known: 'The system can reuse current members, Governance rules, Operations roles and existing case history.',
        decide: 'Choose the correct resolution path for the actual problem. Use formal decisions only when the rules require them.',
        nextLabel: 'Continue to Continuity / Succession',
        nextHref: '/continuity',
    },
    continuity: {
        title: 'Continuity / Succession',
        what: 'Prepare how the Business keeps running if an important person, system or process becomes unavailable.',
        why: 'Backups, recovery steps and succession plans reduce the chance that one failure stops the whole Business.',
        known: 'The system can reuse your Operations roles, members and current governed records.',
        decide: 'Identify critical functions, backups, emergency access, recovery actions and who can step in temporarily.',
        nextLabel: 'Continue to Closure',
        nextHref: '/changes/closure',
    },
    closure: {
        title: 'Closure',
        what: 'Use a controlled process if the Business really needs to close.',
        why: 'Closure affects claims, money, legal obligations, records and workspace access. It must not be faked or rushed.',
        known: 'The system can reuse current Finance, Governance, Ownership and Business records that you are authorized to see.',
        decide: 'Record the real closure trigger, required claims and completion conditions. Closing a screen is not the same as legal closure.',
        nextLabel: 'Go to Implementation & Review Control Center',
        nextHref: '/overview',
    },
    implementation_review: {
        title: 'Implementation & Review Control Center',
        what: 'Review what is complete, what needs attention and the single best next action for the Business.',
        why: 'A Business system only helps when decisions are followed up, reviewed and kept current after setup.',
        known: 'The Control Center reuses the records, actions, reviews and readiness information you are already allowed to see.',
        decide: 'Choose the next real action. Do not mark work complete just to make the dashboard look green.',
        nextLabel: 'Review Business Health / Readiness',
        nextHref: '/health',
    },
};

const guide = guides[props.step];
</script>

<template>
    <section class="mx-auto mt-5 w-full max-w-[1500px] px-4 sm:px-6 lg:px-8">
        <div class="rounded-[22px] border border-[#cfe0d4] bg-[linear-gradient(145deg,#ffffff_0%,#f5f9f6_70%,#fffaf0_100%)] p-5 shadow-[0_12px_30px_rgb(16_35_26_/_5%)] sm:p-6">
            <div class="flex flex-wrap items-start justify-between gap-4">
                <div class="max-w-4xl">
                    <p class="text-[10px] font-black uppercase tracking-[0.18em] text-[var(--pbr-green)]">Simple guide</p>
                    <h2 class="mt-1 text-xl font-black tracking-[-0.02em] text-[var(--pbr-ink)]">{{ guide.title }}</h2>
                    <p class="mt-2 text-sm leading-6 text-[var(--pbr-muted)]">Work on one main decision at a time. The system will keep official records separate from drafts and planning.</p>
                </div>
                <span class="rounded-full border border-[#dfd2a8] bg-[#fff8e8] px-3 py-1 text-xs font-black text-[#755f27]">Grade-6 guide</span>
            </div>

            <div class="mt-5 grid gap-3 md:grid-cols-2 xl:grid-cols-4">
                <div class="rounded-[16px] border border-[#dfe8e1] bg-white p-4">
                    <p class="text-xs font-black text-[var(--pbr-green-dark)]">What am I doing?</p>
                    <p class="mt-2 text-sm leading-6 text-slate-600">{{ guide.what }}</p>
                </div>
                <div class="rounded-[16px] border border-[#dfe8e1] bg-white p-4">
                    <p class="text-xs font-black text-[var(--pbr-green-dark)]">Why does this matter?</p>
                    <p class="mt-2 text-sm leading-6 text-slate-600">{{ guide.why }}</p>
                </div>
                <div class="rounded-[16px] border border-[#dfe8e1] bg-white p-4">
                    <p class="text-xs font-black text-[var(--pbr-green-dark)]">What does the system already know?</p>
                    <p class="mt-2 text-sm leading-6 text-slate-600">{{ guide.known }}</p>
                </div>
                <div class="rounded-[16px] border border-[#dfe8e1] bg-white p-4">
                    <p class="text-xs font-black text-[var(--pbr-green-dark)]">What do I need to decide?</p>
                    <p class="mt-2 text-sm leading-6 text-slate-600">{{ guide.decide }}</p>
                </div>
            </div>

            <div class="mt-5 flex flex-wrap items-center justify-between gap-3 rounded-[16px] border border-[#d9e4db] bg-white/85 p-4">
                <p class="text-xs leading-5 text-slate-500">Advanced settings can be completed later. Never create fake approvals or official records just to make a step look finished.</p>
                <Link :href="guide.nextHref" class="inline-flex min-h-11 items-center rounded-xl bg-[var(--pbr-green-dark)] px-4 text-sm font-black text-white">
                    {{ guide.nextLabel }}
                </Link>
            </div>
        </div>
    </section>
</template>
