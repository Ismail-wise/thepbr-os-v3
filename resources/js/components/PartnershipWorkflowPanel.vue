<script setup lang="ts">
import { Link, useForm } from '@inertiajs/vue3';
import { computed } from 'vue';
import { useI18n } from '../i18n/useI18n';

type Partner = {
    id: string;
    display_name: string;
    revision: number;
};

type Contribution = {
    id: string;
    partner_id: string;
    contribution_type: string;
    description: string;
    status: string;
    revision: number;
    approved_value: string | null;
};

type Scenario = {
    id: string;
    name: string;
    status: string;
    revision: number;
};

type ScenarioShareClass = {
    id: string;
    ownership_scenario_id: string;
    name?: string;
    class_name?: string;
};

type ScenarioPosition = {
    id: string;
    ownership_scenario_id: string;
    partner_id: string;
    shares_issued?: string;
};

type Submission = {
    id: string;
    contribution_id?: string;
    ownership_scenario_id?: string;
    phase?: string;
    formal_record_version_id: string;
    proposal_version_id: string;
};

const props = defineProps<{
    partnership: {
        permissions: Record<string, boolean>;
        partners: Partner[];
        contributions: Contribution[];
        contribution_submissions: Submission[];
        ownership_scenarios: Scenario[];
        ownership_scenario_share_classes: ScenarioShareClass[];
        ownership_scenario_positions: ScenarioPosition[];
        ownership_submissions: Submission[];
    };
}>();

const { t } = useI18n();

const partnerOptions = computed(() => props.partnership.partners);
const contributionOptions = computed(() => props.partnership.contributions);
const scenarioOptions = computed(() => props.partnership.ownership_scenarios);

const invite = useForm({
    partner_id: '',
    email: '',
    expires_in_hours: 72,
});

const dd = useForm({
    partner_id: '',
    case_id: '',
    expected_revision: 0,
    status: 'draft',
    risk_rating: '',
    identity_legal_info: '',
    background_summary: '',
    business_experience: '',
    financial_capacity: '',
    reputation: '',
    existing_business_interests: '',
    conflict_of_interest: '',
    time_commitment: '',
    legal_regulatory_check: '',
    notes: '',
});

const dynamics = useForm({
    partner_id: '',
    source_assessment_id: '',
    source_url: '',
    assessment_version: 'current',
    primary_profile: 'visionary',
    secondary_profile: '',
    completed_at: new Date().toISOString().slice(0, 10),
});

const contribution = useForm({
    partner_id: '',
    contribution_type: 'cash',
    currency: 'USD',
    description: '',
    proposed_value: '',
    conditions: '',
    committed_date: '',
    due_date: '',

    amount_committed: '',
    amount_received: '',
    payment_date: '',

    role_work: '',
    hours_per_month: '',
    fair_market_rate: '',
    number_of_months: '',
    cash_compensation_received: '',
    start_date: '',
    end_date: '',
    performance_condition: '',
    vesting_rule: '',

    asset_description: '',
    ownership_transferred: false,
    usage_period: '',
    market_value: '',
    fair_rental_use_value: '',
    asset_valuation_method: '',

    intangible_kind: '',
    intangible_description: '',
    legal_beneficial_owner: '',
    contribution_form: '',
    contribution_period: '',
    intangible_valuation_method: '',
});

const review = useForm({
    contribution_id: '',
    expected_revision: 1,
    reviewed_value: '',
    valuation_method: '',
    note: '',
});

const contributionGovernance = useForm({
    contribution_id: '',
    phase: 'approval',
    proposed_accepted_value: '',
});

const contributionReview = useForm({
    submission_id: '',
    target: 'under_review',
});

const contributionSync = useForm({
    submission_id: '',
});

const delivery = useForm({
    contribution_id: '',
    expected_revision: 1,
    delivered_value: '',
    delivered_at: new Date().toISOString().slice(0, 10),
    notes: '',
    mark_delivered: true,
});

const scenario = useForm({
    name: '',
    currency: 'USD',
    share_value_minor_units: 100,
    authorized_shares: '100000',
    reserved_unissued_shares: '0',
});

const rights = useForm({
    scenario_id: '',
    share_class_id: '',
    voting_right_per_share: '1',
    profit_right_per_share: '1',
    transfer_allowed: true,
    restrictions: '',
    special_rights: '',
});

const vesting = useForm({
    scenario_id: '',
    position_id: '',
    vested_shares: '0',
    start_date: '',
    period_months: '',
    cliff_months: '',
    conditions: '',
    early_exit_treatment: '',
});

const freeze = useForm({
    scenario_id: '',
    expected_revision: 1,
});

const ownershipGovernance = useForm({
    scenario_id: '',
    effective_from: new Date().toISOString().slice(0, 10),
    effective_until: '',
});

const ownershipReview = useForm({
    submission_id: '',
    target: 'under_review',
});

const ownershipEffect = useForm({
    submission_id: '',
});

const errorText = (form: { errors: Record<string, string> }): string =>
    Object.values(form.errors)[0] ?? '';

const submitInvite = () => {
    if (!invite.partner_id) return;

    invite.post(
        `/partnership/partners/${invite.partner_id}/invite`,
        { preserveScroll: true },
    );
};

const submitDd = () => {
    if (!dd.partner_id) return;

    dd.put(
        `/partnership/partners/${dd.partner_id}/due-diligence`,
        { preserveScroll: true },
    );
};

const submitDynamics = () => {
    if (!dynamics.partner_id) return;

    dynamics.post(
        `/partnership/partners/${dynamics.partner_id}/partner-dynamics`,
        { preserveScroll: true },
    );
};

const submitContribution = () =>
    contribution.post('/partnership/contributions', {
        preserveScroll: true,
        onSuccess: () => {
            contribution.description = '';
            contribution.proposed_value = '';
        },
    });

const submitReview = () => {
    if (!review.contribution_id) return;

    review.put(
        `/partnership/contributions/${review.contribution_id}/review`,
        { preserveScroll: true },
    );
};

const submitContributionGovernance = () => {
    if (!contributionGovernance.contribution_id) return;

    contributionGovernance.post(
        `/partnership/contributions/${contributionGovernance.contribution_id}/governance`,
        { preserveScroll: true },
    );
};

const submitContributionReview = () => {
    if (!contributionReview.submission_id) return;

    contributionReview.post(
        `/partnership/contribution-submissions/${contributionReview.submission_id}/content-review`,
        { preserveScroll: true },
    );
};

const submitContributionSync = () => {
    if (!contributionSync.submission_id) return;

    contributionSync.post(
        `/partnership/contribution-submissions/${contributionSync.submission_id}/sync-decision`,
        { preserveScroll: true },
    );
};

const submitDelivery = () => {
    if (!delivery.contribution_id) return;

    delivery.post(
        `/partnership/contributions/${delivery.contribution_id}/delivery`,
        { preserveScroll: true },
    );
};

const submitScenario = () =>
    scenario.post('/partnership/ownership/scenarios', {
        preserveScroll: true,
    });

const submitRights = () => {
    if (!rights.scenario_id || !rights.share_class_id) return;

    rights.put(
        `/partnership/ownership/scenarios/${rights.scenario_id}/share-classes/${rights.share_class_id}`,
        { preserveScroll: true },
    );
};

const submitVesting = () => {
    if (!vesting.scenario_id || !vesting.position_id) return;

    vesting.put(
        `/partnership/ownership/scenarios/${vesting.scenario_id}/positions/${vesting.position_id}/vesting`,
        { preserveScroll: true },
    );
};

const submitFreeze = () => {
    if (!freeze.scenario_id) return;

    freeze.post(
        `/partnership/ownership/scenarios/${freeze.scenario_id}/freeze`,
        { preserveScroll: true },
    );
};

const submitOwnershipGovernance = () => {
    if (!ownershipGovernance.scenario_id) return;

    ownershipGovernance.post(
        `/partnership/ownership/scenarios/${ownershipGovernance.scenario_id}/governance`,
        { preserveScroll: true },
    );
};

const submitOwnershipReview = () => {
    if (!ownershipReview.submission_id) return;

    ownershipReview.post(
        `/partnership/ownership-submissions/${ownershipReview.submission_id}/content-review`,
        { preserveScroll: true },
    );
};

const submitOwnershipEffect = () => {
    if (!ownershipEffect.submission_id) return;

    ownershipEffect.post(
        `/partnership/ownership-submissions/${ownershipEffect.submission_id}/effect`,
        { preserveScroll: true },
    );
};
</script>

<template>
    <section
        v-if="
            partnership.permissions.partners_manage ||
            partnership.permissions.due_diligence_manage ||
            partnership.permissions.contributions_manage ||
            partnership.permissions.ownership_manage
        "
        class="mt-6 border border-slate-200 bg-white"
        aria-labelledby="partnership-workflow-title"
    >
        <div class="border-b border-slate-200 px-4 py-4 sm:px-5">
            <h2
                id="partnership-workflow-title"
                class="text-base font-bold text-slate-950"
            >
                {{ t('partnership.workflow') }}
            </h2>

            <p class="mt-1 text-sm leading-6 text-slate-600">
                {{ t('partnership.governanceNotice') }}
            </p>
        </div>

        <div class="divide-y divide-slate-200">
            <details
                v-if="partnership.permissions.partners_manage"
                class="group px-4 py-4 sm:px-5"
            >
                <summary
                    class="cursor-pointer font-semibold text-slate-950"
                >
                    {{ t('partnership.invitation') }}
                </summary>

                <form
                    class="mt-4 grid gap-3 md:grid-cols-3"
                    @submit.prevent="submitInvite"
                >
                    <label class="text-sm font-medium text-slate-700">
                        Partner
                        <select
                            v-model="invite.partner_id"
                            required
                            class="mt-1 min-h-11 w-full border border-slate-300 px-3"
                        >
                            <option value="" disabled>Select Partner</option>
                            <option
                                v-for="partner in partnerOptions"
                                :key="partner.id"
                                :value="partner.id"
                            >
                                {{ partner.display_name }}
                            </option>
                        </select>
                    </label>

                    <label class="text-sm font-medium text-slate-700">
                        Email
                        <input
                            v-model="invite.email"
                            type="email"
                            required
                            class="mt-1 min-h-11 w-full border border-slate-300 px-3"
                        />
                    </label>

                    <label class="text-sm font-medium text-slate-700">
                        Expires in hours
                        <input
                            v-model.number="invite.expires_in_hours"
                            type="number"
                            min="1"
                            max="168"
                            required
                            class="mt-1 min-h-11 w-full border border-slate-300 px-3"
                        />
                    </label>

                    <p
                        v-if="errorText(invite)"
                        class="text-sm text-red-700 md:col-span-3"
                    >
                        {{ errorText(invite) }}
                    </p>

                    <button
                        type="submit"
                        class="min-h-11 bg-slate-950 px-4 text-sm font-semibold text-white md:w-max"
                    >
                        {{ t('partnership.invitation') }}
                    </button>
                </form>
            </details>

            <details
                v-if="partnership.permissions.due_diligence_manage"
                class="group px-4 py-4 sm:px-5"
            >
                <summary
                    class="cursor-pointer font-semibold text-slate-950"
                >
                    {{ t('partnership.dueDiligence') }}
                </summary>

                <form
                    class="mt-4 grid gap-3 md:grid-cols-2 xl:grid-cols-4"
                    @submit.prevent="submitDd"
                >
                    <label class="text-sm font-medium text-slate-700">
                        Partner
                        <select
                            v-model="dd.partner_id"
                            required
                            class="mt-1 min-h-11 w-full border border-slate-300 px-3"
                        >
                            <option value="" disabled>Select Partner</option>
                            <option
                                v-for="partner in partnerOptions"
                                :key="partner.id"
                                :value="partner.id"
                            >
                                {{ partner.display_name }}
                            </option>
                        </select>
                    </label>

                    <label class="text-sm font-medium text-slate-700">
                        Status
                        <select
                            v-model="dd.status"
                            class="mt-1 min-h-11 w-full border border-slate-300 px-3"
                        >
                            <option value="draft">Draft</option>
                            <option value="in_review">In Review</option>
                            <option value="completed">Completed</option>
                            <option value="blocked">Blocked</option>
                        </select>
                    </label>

                    <label class="text-sm font-medium text-slate-700">
                        Risk
                        <select
                            v-model="dd.risk_rating"
                            class="mt-1 min-h-11 w-full border border-slate-300 px-3"
                        >
                            <option value="">Not set</option>
                            <option value="low">Low</option>
                            <option value="moderate">Moderate</option>
                            <option value="high">High</option>
                            <option value="critical">Critical</option>
                        </select>
                    </label>

                    <label class="text-sm font-medium text-slate-700">
                        Revision
                        <input
                            v-model.number="dd.expected_revision"
                            type="number"
                            min="0"
                            required
                            class="mt-1 min-h-11 w-full border border-slate-300 px-3"
                        />
                    </label>

                    <label class="text-sm font-medium text-slate-700 md:col-span-2">
                        Identity / legal information
                        <textarea
                            v-model="dd.identity_legal_info"
                            class="mt-1 min-h-24 w-full border border-slate-300 p-3"
                        />
                    </label>

                    <label class="text-sm font-medium text-slate-700 md:col-span-2">
                        Background summary
                        <textarea
                            v-model="dd.background_summary"
                            class="mt-1 min-h-24 w-full border border-slate-300 p-3"
                        />
                    </label>

                    <p
                        v-if="errorText(dd)"
                        class="text-sm text-red-700 md:col-span-2 xl:col-span-4"
                    >
                        {{ errorText(dd) }}
                    </p>

                    <button
                        type="submit"
                        class="min-h-11 bg-slate-950 px-4 text-sm font-semibold text-white md:w-max"
                    >
                        {{ t('partnership.dueDiligence') }}
                    </button>
                </form>
            </details>

            <details
                v-if="partnership.permissions.partners_manage"
                class="group px-4 py-4 sm:px-5"
            >
                <summary
                    class="cursor-pointer font-semibold text-slate-950"
                >
                    {{ t('partnership.partnerDynamics') }}
                </summary>

                <form
                    class="mt-4 grid gap-3 md:grid-cols-2 xl:grid-cols-4"
                    @submit.prevent="submitDynamics"
                >
                    <label class="text-sm font-medium text-slate-700">
                        Partner
                        <select
                            v-model="dynamics.partner_id"
                            required
                            class="mt-1 min-h-11 w-full border border-slate-300 px-3"
                        >
                            <option value="" disabled>Select Partner</option>
                            <option
                                v-for="partner in partnerOptions"
                                :key="partner.id"
                                :value="partner.id"
                            >
                                {{ partner.display_name }}
                            </option>
                        </select>
                    </label>

                    <label class="text-sm font-medium text-slate-700">
                        Assessment ID
                        <input
                            v-model="dynamics.source_assessment_id"
                            required
                            class="mt-1 min-h-11 w-full border border-slate-300 px-3"
                        />
                    </label>

                    <label class="text-sm font-medium text-slate-700">
                        Primary profile
                        <select
                            v-model="dynamics.primary_profile"
                            class="mt-1 min-h-11 w-full border border-slate-300 px-3"
                        >
                            <option value="visionary">Visionary</option>
                            <option value="builder">Builder</option>
                            <option value="connector">Connector</option>
                            <option value="analyst">Analyst</option>
                            <option value="operator">Operator</option>
                            <option value="guardian">Guardian</option>
                            <option value="negotiator">Negotiator</option>
                            <option value="optimizer">Optimizer</option>
                        </select>
                    </label>

                    <label class="text-sm font-medium text-slate-700">
                        Completed
                        <input
                            v-model="dynamics.completed_at"
                            type="date"
                            required
                            class="mt-1 min-h-11 w-full border border-slate-300 px-3"
                        />
                    </label>

                    <p
                        v-if="errorText(dynamics)"
                        class="text-sm text-red-700 md:col-span-2 xl:col-span-4"
                    >
                        {{ errorText(dynamics) }}
                    </p>

                    <button
                        type="submit"
                        class="min-h-11 bg-slate-950 px-4 text-sm font-semibold text-white md:w-max"
                    >
                        {{ t('partnership.partnerDynamics') }}
                    </button>
                </form>
            </details>

            <details
                v-if="partnership.permissions.contributions_manage"
                class="group px-4 py-4 sm:px-5"
                open
            >
                <summary
                    class="cursor-pointer font-semibold text-slate-950"
                >
                    {{ t('partnership.createContribution') }}
                </summary>

                <p class="mt-3 text-sm text-slate-600">
                    {{ t('partnership.acceptedOnly') }}
                </p>

                <form
                    class="mt-4 grid gap-3 md:grid-cols-2 xl:grid-cols-4"
                    @submit.prevent="submitContribution"
                >
                    <label class="text-sm font-medium text-slate-700">
                        Partner
                        <select
                            v-model="contribution.partner_id"
                            required
                            class="mt-1 min-h-11 w-full border border-slate-300 px-3"
                        >
                            <option value="" disabled>Select Partner</option>
                            <option
                                v-for="partner in partnerOptions"
                                :key="partner.id"
                                :value="partner.id"
                            >
                                {{ partner.display_name }}
                            </option>
                        </select>
                    </label>

                    <label class="text-sm font-medium text-slate-700">
                        Type
                        <select
                            v-model="contribution.contribution_type"
                            class="mt-1 min-h-11 w-full border border-slate-300 px-3"
                        >
                            <option value="cash">Cash</option>
                            <option value="time_skill">Time & Skill</option>
                            <option value="property_asset">Property & Asset</option>
                            <option value="ip_intangible">IP & Intangible</option>
                        </select>
                    </label>

                    <label class="text-sm font-medium text-slate-700">
                        Currency
                        <input
                            v-model="contribution.currency"
                            maxlength="3"
                            required
                            class="mt-1 min-h-11 w-full border border-slate-300 px-3 uppercase"
                        />
                    </label>

                    <label class="text-sm font-medium text-slate-700">
                        Proposed value
                        <input
                            v-model="contribution.proposed_value"
                            required
                            inputmode="decimal"
                            class="mt-1 min-h-11 w-full border border-slate-300 px-3"
                        />
                    </label>

                    <label class="text-sm font-medium text-slate-700 md:col-span-2">
                        Description
                        <input
                            v-model="contribution.description"
                            required
                            class="mt-1 min-h-11 w-full border border-slate-300 px-3"
                        />
                    </label>

                    <template
                        v-if="contribution.contribution_type === 'cash'"
                    >
                        <label class="text-sm font-medium text-slate-700">
                            Amount committed
                            <input
                                v-model="contribution.amount_committed"
                                required
                                class="mt-1 min-h-11 w-full border border-slate-300 px-3"
                            />
                        </label>

                        <label class="text-sm font-medium text-slate-700">
                            Amount received
                            <input
                                v-model="contribution.amount_received"
                                class="mt-1 min-h-11 w-full border border-slate-300 px-3"
                            />
                        </label>
                    </template>

                    <template
                        v-else-if="contribution.contribution_type === 'time_skill'"
                    >
                        <label class="text-sm font-medium text-slate-700">
                            Role / work
                            <input
                                v-model="contribution.role_work"
                                required
                                class="mt-1 min-h-11 w-full border border-slate-300 px-3"
                            />
                        </label>

                        <label class="text-sm font-medium text-slate-700">
                            Hours / month
                            <input
                                v-model="contribution.hours_per_month"
                                required
                                class="mt-1 min-h-11 w-full border border-slate-300 px-3"
                            />
                        </label>

                        <label class="text-sm font-medium text-slate-700">
                            Fair market rate
                            <input
                                v-model="contribution.fair_market_rate"
                                required
                                class="mt-1 min-h-11 w-full border border-slate-300 px-3"
                            />
                        </label>

                        <label class="text-sm font-medium text-slate-700">
                            Months
                            <input
                                v-model="contribution.number_of_months"
                                type="number"
                                min="1"
                                required
                                class="mt-1 min-h-11 w-full border border-slate-300 px-3"
                            />
                        </label>
                    </template>

                    <template
                        v-else-if="contribution.contribution_type === 'property_asset'"
                    >
                        <label class="text-sm font-medium text-slate-700 md:col-span-2">
                            Asset description
                            <input
                                v-model="contribution.asset_description"
                                required
                                class="mt-1 min-h-11 w-full border border-slate-300 px-3"
                            />
                        </label>

                        <label class="text-sm font-medium text-slate-700">
                            Ownership transferred
                            <select
                                v-model="contribution.ownership_transferred"
                                class="mt-1 min-h-11 w-full border border-slate-300 px-3"
                            >
                                <option :value="true">Yes</option>
                                <option :value="false">No</option>
                            </select>
                        </label>
                    </template>

                    <template v-else>
                        <label class="text-sm font-medium text-slate-700">
                            Intangible kind
                            <input
                                v-model="contribution.intangible_kind"
                                required
                                class="mt-1 min-h-11 w-full border border-slate-300 px-3"
                            />
                        </label>

                        <label class="text-sm font-medium text-slate-700">
                            Legal / beneficial owner
                            <input
                                v-model="contribution.legal_beneficial_owner"
                                required
                                class="mt-1 min-h-11 w-full border border-slate-300 px-3"
                            />
                        </label>

                        <label class="text-sm font-medium text-slate-700 md:col-span-2">
                            Intangible description
                            <input
                                v-model="contribution.intangible_description"
                                required
                                class="mt-1 min-h-11 w-full border border-slate-300 px-3"
                            />
                        </label>

                        <label class="text-sm font-medium text-slate-700">
                            Contribution form
                            <input
                                v-model="contribution.contribution_form"
                                required
                                class="mt-1 min-h-11 w-full border border-slate-300 px-3"
                            />
                        </label>

                        <label class="text-sm font-medium text-slate-700">
                            Valuation method
                            <input
                                v-model="contribution.intangible_valuation_method"
                                required
                                class="mt-1 min-h-11 w-full border border-slate-300 px-3"
                            />
                        </label>
                    </template>

                    <p
                        v-if="errorText(contribution)"
                        class="text-sm text-red-700 md:col-span-2 xl:col-span-4"
                    >
                        {{ errorText(contribution) }}
                    </p>

                    <button
                        type="submit"
                        class="min-h-11 bg-slate-950 px-4 text-sm font-semibold text-white md:w-max"
                    >
                        {{ t('partnership.createContribution') }}
                    </button>
                </form>
            </details>

            <details
                v-if="partnership.permissions.contributions_manage"
                class="group px-4 py-4 sm:px-5"
            >
                <summary class="cursor-pointer font-semibold text-slate-950">
                    {{ t('partnership.reviewContribution') }}
                </summary>

                <form
                    class="mt-4 grid gap-3 md:grid-cols-2 xl:grid-cols-4"
                    @submit.prevent="submitReview"
                >
                    <label class="text-sm font-medium text-slate-700">
                        Contribution
                        <select
                            v-model="review.contribution_id"
                            required
                            class="mt-1 min-h-11 w-full border border-slate-300 px-3"
                        >
                            <option value="" disabled>Select Contribution</option>
                            <option
                                v-for="row in contributionOptions"
                                :key="row.id"
                                :value="row.id"
                            >
                                {{ row.description }} · {{ row.status }}
                            </option>
                        </select>
                    </label>

                    <label class="text-sm font-medium text-slate-700">
                        Revision
                        <input
                            v-model.number="review.expected_revision"
                            type="number"
                            min="1"
                            class="mt-1 min-h-11 w-full border border-slate-300 px-3"
                        />
                    </label>

                    <label class="text-sm font-medium text-slate-700">
                        Reviewed value
                        <input
                            v-model="review.reviewed_value"
                            required
                            class="mt-1 min-h-11 w-full border border-slate-300 px-3"
                        />
                    </label>

                    <label class="text-sm font-medium text-slate-700">
                        Valuation method
                        <input
                            v-model="review.valuation_method"
                            required
                            class="mt-1 min-h-11 w-full border border-slate-300 px-3"
                        />
                    </label>

                    <p
                        v-if="errorText(review)"
                        class="text-sm text-red-700 md:col-span-2 xl:col-span-4"
                    >
                        {{ errorText(review) }}
                    </p>

                    <button
                        type="submit"
                        class="min-h-11 bg-slate-950 px-4 text-sm font-semibold text-white md:w-max"
                    >
                        {{ t('partnership.reviewContribution') }}
                    </button>
                </form>
            </details>

            <details
                v-if="partnership.permissions.contributions_manage"
                class="group px-4 py-4 sm:px-5"
            >
                <summary class="cursor-pointer font-semibold text-slate-950">
                    {{ t('partnership.contributionGovernance') }}
                </summary>

                <div
                    class="mt-4 border border-slate-200 bg-slate-50 p-4 text-sm leading-6 text-slate-700"
                >
                    {{ t('partnership.evidenceNotice') }}
                    <div class="mt-2 flex gap-4">
                        <Link
                            href="/records/documents"
                            class="font-semibold underline underline-offset-4"
                        >
                            {{ t('partnership.openVault') }}
                        </Link>

                        <Link
                            href="/governance"
                            class="font-semibold underline underline-offset-4"
                        >
                            {{ t('partnership.openGovernance') }}
                        </Link>
                    </div>
                </div>

                <form
                    class="mt-4 grid gap-3 md:grid-cols-3"
                    @submit.prevent="submitContributionGovernance"
                >
                    <label class="text-sm font-medium text-slate-700">
                        Contribution
                        <select
                            v-model="contributionGovernance.contribution_id"
                            required
                            class="mt-1 min-h-11 w-full border border-slate-300 px-3"
                        >
                            <option value="" disabled>Select Contribution</option>
                            <option
                                v-for="row in contributionOptions"
                                :key="row.id"
                                :value="row.id"
                            >
                                {{ row.description }} · {{ row.status }}
                            </option>
                        </select>
                    </label>

                    <label class="text-sm font-medium text-slate-700">
                        Phase
                        <select
                            v-model="contributionGovernance.phase"
                            class="mt-1 min-h-11 w-full border border-slate-300 px-3"
                        >
                            <option value="approval">Approval</option>
                            <option value="acceptance">Acceptance</option>
                        </select>
                    </label>

                    <label
                        v-if="contributionGovernance.phase === 'acceptance'"
                        class="text-sm font-medium text-slate-700"
                    >
                        Proposed accepted value
                        <input
                            v-model="contributionGovernance.proposed_accepted_value"
                            required
                            class="mt-1 min-h-11 w-full border border-slate-300 px-3"
                        />
                    </label>

                    <p
                        v-if="errorText(contributionGovernance)"
                        class="text-sm text-red-700 md:col-span-3"
                    >
                        {{ errorText(contributionGovernance) }}
                    </p>

                    <button
                        type="submit"
                        class="min-h-11 bg-slate-950 px-4 text-sm font-semibold text-white md:w-max"
                    >
                        {{ t('partnership.contributionGovernance') }}
                    </button>
                </form>

                <form
                    class="mt-5 grid gap-3 border-t border-slate-200 pt-4 md:grid-cols-3"
                    @submit.prevent="submitContributionReview"
                >
                    <label class="text-sm font-medium text-slate-700">
                        Submission
                        <select
                            v-model="contributionReview.submission_id"
                            required
                            class="mt-1 min-h-11 w-full border border-slate-300 px-3"
                        >
                            <option value="" disabled>Select Submission</option>
                            <option
                                v-for="row in partnership.contribution_submissions"
                                :key="row.id"
                                :value="row.id"
                            >
                                {{ row.phase }} · {{ row.id.slice(0, 8) }}
                            </option>
                        </select>
                    </label>

                    <label class="text-sm font-medium text-slate-700">
                        Content review state
                        <select
                            v-model="contributionReview.target"
                            class="mt-1 min-h-11 w-full border border-slate-300 px-3"
                        >
                            <option value="under_review">Under Review</option>
                            <option value="approved">Approved</option>
                        </select>
                    </label>

                    <button
                        type="submit"
                        class="min-h-11 self-end bg-slate-800 px-4 text-sm font-semibold text-white"
                    >
                        Advance Content Review
                    </button>
                </form>

                <form
                    class="mt-4 grid gap-3 border-t border-slate-200 pt-4 md:grid-cols-2"
                    @submit.prevent="submitContributionSync"
                >
                    <label class="text-sm font-medium text-slate-700">
                        Approved Governance Submission
                        <select
                            v-model="contributionSync.submission_id"
                            required
                            class="mt-1 min-h-11 w-full border border-slate-300 px-3"
                        >
                            <option value="" disabled>Select Submission</option>
                            <option
                                v-for="row in partnership.contribution_submissions"
                                :key="row.id"
                                :value="row.id"
                            >
                                {{ row.phase }} · {{ row.id.slice(0, 8) }}
                            </option>
                        </select>
                    </label>

                    <button
                        type="submit"
                        class="min-h-11 self-end bg-slate-950 px-4 text-sm font-semibold text-white"
                    >
                        {{ t('partnership.syncDecision') }}
                    </button>
                </form>
            </details>

            <details
                v-if="partnership.permissions.contributions_manage"
                class="group px-4 py-4 sm:px-5"
            >
                <summary class="cursor-pointer font-semibold text-slate-950">
                    {{ t('partnership.delivery') }}
                </summary>

                <form
                    class="mt-4 grid gap-3 md:grid-cols-2 xl:grid-cols-4"
                    @submit.prevent="submitDelivery"
                >
                    <label class="text-sm font-medium text-slate-700">
                        Contribution
                        <select
                            v-model="delivery.contribution_id"
                            required
                            class="mt-1 min-h-11 w-full border border-slate-300 px-3"
                        >
                            <option value="" disabled>Select Contribution</option>
                            <option
                                v-for="row in contributionOptions"
                                :key="row.id"
                                :value="row.id"
                            >
                                {{ row.description }} · {{ row.status }}
                            </option>
                        </select>
                    </label>

                    <label class="text-sm font-medium text-slate-700">
                        Revision
                        <input
                            v-model.number="delivery.expected_revision"
                            type="number"
                            min="1"
                            required
                            class="mt-1 min-h-11 w-full border border-slate-300 px-3"
                        />
                    </label>

                    <label class="text-sm font-medium text-slate-700">
                        Delivered value
                        <input
                            v-model="delivery.delivered_value"
                            required
                            class="mt-1 min-h-11 w-full border border-slate-300 px-3"
                        />
                    </label>

                    <label class="text-sm font-medium text-slate-700">
                        Delivered date
                        <input
                            v-model="delivery.delivered_at"
                            type="date"
                            required
                            class="mt-1 min-h-11 w-full border border-slate-300 px-3"
                        />
                    </label>

                    <p
                        v-if="errorText(delivery)"
                        class="text-sm text-red-700 md:col-span-2 xl:col-span-4"
                    >
                        {{ errorText(delivery) }}
                    </p>

                    <button
                        type="submit"
                        class="min-h-11 bg-slate-950 px-4 text-sm font-semibold text-white md:w-max"
                    >
                        {{ t('partnership.delivery') }}
                    </button>
                </form>
            </details>

            <details
                v-if="partnership.permissions.ownership_manage"
                class="group px-4 py-4 sm:px-5"
                open
            >
                <summary class="cursor-pointer font-semibold text-slate-950">
                    {{ t('partnership.createScenario') }}
                </summary>

                <p class="mt-3 text-sm text-slate-600">
                    {{ t('partnership.scenarioNotice') }}
                </p>

                <form
                    class="mt-4 grid gap-3 md:grid-cols-2 xl:grid-cols-5"
                    @submit.prevent="submitScenario"
                >
                    <label class="text-sm font-medium text-slate-700">
                        Scenario name
                        <input
                            v-model="scenario.name"
                            required
                            class="mt-1 min-h-11 w-full border border-slate-300 px-3"
                        />
                    </label>

                    <label class="text-sm font-medium text-slate-700">
                        Currency
                        <input
                            v-model="scenario.currency"
                            maxlength="3"
                            required
                            class="mt-1 min-h-11 w-full border border-slate-300 px-3 uppercase"
                        />
                    </label>

                    <label class="text-sm font-medium text-slate-700">
                        Share value (minor units)
                        <input
                            v-model.number="scenario.share_value_minor_units"
                            type="number"
                            min="1"
                            required
                            class="mt-1 min-h-11 w-full border border-slate-300 px-3"
                        />
                    </label>

                    <label class="text-sm font-medium text-slate-700">
                        Authorized shares
                        <input
                            v-model="scenario.authorized_shares"
                            required
                            class="mt-1 min-h-11 w-full border border-slate-300 px-3"
                        />
                    </label>

                    <label class="text-sm font-medium text-slate-700">
                        Reserved unissued
                        <input
                            v-model="scenario.reserved_unissued_shares"
                            required
                            class="mt-1 min-h-11 w-full border border-slate-300 px-3"
                        />
                    </label>

                    <p
                        v-if="errorText(scenario)"
                        class="text-sm text-red-700 md:col-span-2 xl:col-span-5"
                    >
                        {{ errorText(scenario) }}
                    </p>

                    <button
                        type="submit"
                        class="min-h-11 bg-slate-950 px-4 text-sm font-semibold text-white md:w-max"
                    >
                        {{ t('partnership.createScenario') }}
                    </button>
                </form>
            </details>

            <details
                v-if="partnership.permissions.ownership_manage"
                class="group px-4 py-4 sm:px-5"
            >
                <summary class="cursor-pointer font-semibold text-slate-950">
                    {{ t('partnership.shareRights') }}
                </summary>

                <form
                    class="mt-4 grid gap-3 md:grid-cols-2 xl:grid-cols-4"
                    @submit.prevent="submitRights"
                >
                    <label class="text-sm font-medium text-slate-700">
                        Scenario
                        <select
                            v-model="rights.scenario_id"
                            required
                            class="mt-1 min-h-11 w-full border border-slate-300 px-3"
                        >
                            <option value="" disabled>Select Scenario</option>
                            <option
                                v-for="row in scenarioOptions"
                                :key="row.id"
                                :value="row.id"
                            >
                                {{ row.name }} · {{ row.status }}
                            </option>
                        </select>
                    </label>

                    <label class="text-sm font-medium text-slate-700">
                        Share class
                        <select
                            v-model="rights.share_class_id"
                            required
                            class="mt-1 min-h-11 w-full border border-slate-300 px-3"
                        >
                            <option value="" disabled>Select Share Class</option>
                            <option
                                v-for="row in partnership.ownership_scenario_share_classes"
                                :key="row.id"
                                :value="row.id"
                            >
                                {{ row.name || row.class_name || row.id.slice(0, 8) }}
                            </option>
                        </select>
                    </label>

                    <label class="text-sm font-medium text-slate-700">
                        Voting right / share
                        <input
                            v-model="rights.voting_right_per_share"
                            required
                            class="mt-1 min-h-11 w-full border border-slate-300 px-3"
                        />
                    </label>

                    <label class="text-sm font-medium text-slate-700">
                        Profit right / share
                        <input
                            v-model="rights.profit_right_per_share"
                            required
                            class="mt-1 min-h-11 w-full border border-slate-300 px-3"
                        />
                    </label>

                    <p
                        v-if="errorText(rights)"
                        class="text-sm text-red-700 md:col-span-2 xl:col-span-4"
                    >
                        {{ errorText(rights) }}
                    </p>

                    <button
                        type="submit"
                        class="min-h-11 bg-slate-950 px-4 text-sm font-semibold text-white md:w-max"
                    >
                        {{ t('partnership.shareRights') }}
                    </button>
                </form>
            </details>

            <details
                v-if="partnership.permissions.ownership_manage"
                class="group px-4 py-4 sm:px-5"
            >
                <summary class="cursor-pointer font-semibold text-slate-950">
                    {{ t('partnership.vesting') }}
                </summary>

                <form
                    class="mt-4 grid gap-3 md:grid-cols-2 xl:grid-cols-4"
                    @submit.prevent="submitVesting"
                >
                    <label class="text-sm font-medium text-slate-700">
                        Scenario
                        <select
                            v-model="vesting.scenario_id"
                            required
                            class="mt-1 min-h-11 w-full border border-slate-300 px-3"
                        >
                            <option value="" disabled>Select Scenario</option>
                            <option
                                v-for="row in scenarioOptions"
                                :key="row.id"
                                :value="row.id"
                            >
                                {{ row.name }}
                            </option>
                        </select>
                    </label>

                    <label class="text-sm font-medium text-slate-700">
                        Position
                        <select
                            v-model="vesting.position_id"
                            required
                            class="mt-1 min-h-11 w-full border border-slate-300 px-3"
                        >
                            <option value="" disabled>Select Position</option>
                            <option
                                v-for="row in partnership.ownership_scenario_positions"
                                :key="row.id"
                                :value="row.id"
                            >
                                {{ row.id.slice(0, 8) }}
                            </option>
                        </select>
                    </label>

                    <label class="text-sm font-medium text-slate-700">
                        Vested shares
                        <input
                            v-model="vesting.vested_shares"
                            required
                            class="mt-1 min-h-11 w-full border border-slate-300 px-3"
                        />
                    </label>

                    <label class="text-sm font-medium text-slate-700">
                        Start date
                        <input
                            v-model="vesting.start_date"
                            type="date"
                            class="mt-1 min-h-11 w-full border border-slate-300 px-3"
                        />
                    </label>

                    <p
                        v-if="errorText(vesting)"
                        class="text-sm text-red-700 md:col-span-2 xl:col-span-4"
                    >
                        {{ errorText(vesting) }}
                    </p>

                    <button
                        type="submit"
                        class="min-h-11 bg-slate-950 px-4 text-sm font-semibold text-white md:w-max"
                    >
                        {{ t('partnership.vesting') }}
                    </button>
                </form>
            </details>

            <details
                v-if="partnership.permissions.ownership_manage"
                class="group px-4 py-4 sm:px-5"
            >
                <summary class="cursor-pointer font-semibold text-slate-950">
                    {{ t('partnership.freezeScenario') }}
                </summary>

                <form
                    class="mt-4 grid gap-3 md:grid-cols-3"
                    @submit.prevent="submitFreeze"
                >
                    <label class="text-sm font-medium text-slate-700">
                        Scenario
                        <select
                            v-model="freeze.scenario_id"
                            required
                            class="mt-1 min-h-11 w-full border border-slate-300 px-3"
                        >
                            <option value="" disabled>Select Scenario</option>
                            <option
                                v-for="row in scenarioOptions"
                                :key="row.id"
                                :value="row.id"
                            >
                                {{ row.name }} · rev {{ row.revision }}
                            </option>
                        </select>
                    </label>

                    <label class="text-sm font-medium text-slate-700">
                        Expected revision
                        <input
                            v-model.number="freeze.expected_revision"
                            type="number"
                            min="1"
                            required
                            class="mt-1 min-h-11 w-full border border-slate-300 px-3"
                        />
                    </label>

                    <button
                        type="submit"
                        class="min-h-11 self-end bg-slate-950 px-4 text-sm font-semibold text-white"
                    >
                        {{ t('partnership.freezeScenario') }}
                    </button>
                </form>
            </details>

            <details
                v-if="partnership.permissions.ownership_manage"
                class="group px-4 py-4 sm:px-5"
            >
                <summary class="cursor-pointer font-semibold text-slate-950">
                    {{ t('partnership.ownershipGovernance') }}
                </summary>

                <div
                    class="mt-4 border border-slate-200 bg-slate-50 p-4 text-sm leading-6 text-slate-700"
                >
                    {{ t('partnership.governanceNotice') }}

                    <div class="mt-2">
                        <Link
                            href="/governance"
                            class="font-semibold underline underline-offset-4"
                        >
                            {{ t('partnership.openGovernance') }}
                        </Link>
                    </div>
                </div>

                <form
                    class="mt-4 grid gap-3 md:grid-cols-3"
                    @submit.prevent="submitOwnershipGovernance"
                >
                    <label class="text-sm font-medium text-slate-700">
                        Frozen Scenario
                        <select
                            v-model="ownershipGovernance.scenario_id"
                            required
                            class="mt-1 min-h-11 w-full border border-slate-300 px-3"
                        >
                            <option value="" disabled>Select Scenario</option>
                            <option
                                v-for="row in scenarioOptions"
                                :key="row.id"
                                :value="row.id"
                            >
                                {{ row.name }} · {{ row.status }}
                            </option>
                        </select>
                    </label>

                    <label class="text-sm font-medium text-slate-700">
                        Effective from
                        <input
                            v-model="ownershipGovernance.effective_from"
                            type="date"
                            required
                            class="mt-1 min-h-11 w-full border border-slate-300 px-3"
                        />
                    </label>

                    <label class="text-sm font-medium text-slate-700">
                        Effective until
                        <input
                            v-model="ownershipGovernance.effective_until"
                            type="date"
                            class="mt-1 min-h-11 w-full border border-slate-300 px-3"
                        />
                    </label>

                    <p
                        v-if="errorText(ownershipGovernance)"
                        class="text-sm text-red-700 md:col-span-3"
                    >
                        {{ errorText(ownershipGovernance) }}
                    </p>

                    <button
                        type="submit"
                        class="min-h-11 bg-slate-950 px-4 text-sm font-semibold text-white md:w-max"
                    >
                        {{ t('partnership.ownershipGovernance') }}
                    </button>
                </form>

                <form
                    class="mt-5 grid gap-3 border-t border-slate-200 pt-4 md:grid-cols-3"
                    @submit.prevent="submitOwnershipReview"
                >
                    <label class="text-sm font-medium text-slate-700">
                        Submission
                        <select
                            v-model="ownershipReview.submission_id"
                            required
                            class="mt-1 min-h-11 w-full border border-slate-300 px-3"
                        >
                            <option value="" disabled>Select Submission</option>
                            <option
                                v-for="row in partnership.ownership_submissions"
                                :key="row.id"
                                :value="row.id"
                            >
                                {{ row.id.slice(0, 8) }}
                            </option>
                        </select>
                    </label>

                    <label class="text-sm font-medium text-slate-700">
                        Content review
                        <select
                            v-model="ownershipReview.target"
                            class="mt-1 min-h-11 w-full border border-slate-300 px-3"
                        >
                            <option value="under_review">Under Review</option>
                            <option value="approved">Approved</option>
                        </select>
                    </label>

                    <button
                        type="submit"
                        class="min-h-11 self-end bg-slate-800 px-4 text-sm font-semibold text-white"
                    >
                        Advance Content Review
                    </button>
                </form>

                <form
                    class="mt-4 grid gap-3 border-t border-slate-200 pt-4 md:grid-cols-2"
                    @submit.prevent="submitOwnershipEffect"
                >
                    <label class="text-sm font-medium text-slate-700">
                        Approved Submission
                        <select
                            v-model="ownershipEffect.submission_id"
                            required
                            class="mt-1 min-h-11 w-full border border-slate-300 px-3"
                        >
                            <option value="" disabled>Select Submission</option>
                            <option
                                v-for="row in partnership.ownership_submissions"
                                :key="row.id"
                                :value="row.id"
                            >
                                {{ row.id.slice(0, 8) }}
                            </option>
                        </select>
                    </label>

                    <button
                        type="submit"
                        class="min-h-11 self-end bg-slate-950 px-4 text-sm font-semibold text-white"
                    >
                        {{ t('partnership.effectOwnership') }}
                    </button>
                </form>
            </details>
        </div>
    </section>
</template>
