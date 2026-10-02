import type { TranslationKey } from '../i18n/catalog';

export const humanizeAccountValue = (value: string | null): string => {
    if (!value) {
        return '';
    }

    return value
        .replace(/[_-]+/g, ' ')
        .replace(/\w/g, (character) => character.toUpperCase());
};

export const accountKindKey = (kind: string): TranslationKey => {
    const keys: Record<string, TranslationKey> = {
        approval: 'account.approval',
        signature: 'account.signature',
        proposal_review: 'account.proposalReview',
        record_review: 'account.recordReview',
        operations_action: 'account.operationsAction',
        governance_action: 'account.governanceAction',
        action_assigned: 'account.actionAssigned',
        proposal_review_assigned: 'account.proposalReviewAssigned',
        review_assigned: 'account.reviewAssigned',
        signature_requested: 'account.signatureRequested',
        governance_update: 'account.governanceUpdate',
    };

    return keys[kind] ?? 'account.governanceUpdate';
};
