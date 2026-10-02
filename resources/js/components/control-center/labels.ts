import type { TranslationKey } from '../../i18n/catalog';

export const areaLabelKeys: Record<string, TranslationKey> = {
    workspace: 'controlCenter.area.workspace',
    ownership: 'controlCenter.area.ownership',
    governance: 'controlCenter.area.governance',
    operations: 'controlCenter.area.operations',
    finance: 'controlCenter.area.finance',
    rewards: 'controlCenter.area.rewards',
    risk: 'controlCenter.area.risk',
    continuity: 'controlCenter.area.continuity',
    conflict: 'controlCenter.area.conflict',
};

export const attentionLabelKeys: Record<string, TranslationKey> = {
    'governance.proposals': 'controlCenter.attention.proposals',
    'governance.decisions': 'controlCenter.attention.decisions',
    'governance.signatures': 'controlCenter.attention.signatures',
    'governance.actions': 'controlCenter.attention.actions',
    'governance.reviews': 'controlCenter.attention.reviews',
    'health.workspace': 'controlCenter.attention.workspace',
    'health.ownership': 'controlCenter.attention.ownership',
    'health.governance': 'controlCenter.attention.governance',
    'health.operations': 'controlCenter.attention.operations',
    'health.finance': 'controlCenter.attention.finance',
    'health.rewards': 'controlCenter.attention.rewards',
    'health.risk': 'controlCenter.attention.risk',
    'health.continuity': 'controlCenter.attention.continuity',
    'health.conflict': 'controlCenter.attention.conflict',
};

export const activityLabelKeys: Record<string, TranslationKey> = {
    review_frozen: 'controlCenter.activity.reviewFrozen',
    record_state_changed: 'controlCenter.activity.stateChanged',
    current_effective_changed: 'controlCenter.activity.effectiveChanged',
    record_superseded: 'controlCenter.activity.superseded',
    proposal_frozen: 'controlCenter.activity.proposalFrozen',
    business_record_activity: 'controlCenter.activity.general',
};
