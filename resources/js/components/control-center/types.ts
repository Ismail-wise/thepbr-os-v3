export type BusinessSummary = {
    name: string;
    stage: string;
    setupPhase: string | null;
    workspaceStatus: string;
    baseCurrency: string;
};

export type HealthSummary = {
    ready: number;
    review: number;
    blocked: number;
    setupNeeded: number;
};

export type HealthRequirement = {
    area: string;
    status: string;
    isCurrentEffective: boolean;
    route: string | null;
};

export type AttentionItem = {
    key: string;
    count: number;
    route: string;
    tone: string;
};

export type NextActionItem = {
    key: string;
    count: number;
    route: string;
    state: string;
};

export type UpcomingItem = {
    kind: string;
    title: string | null;
    dueAt: string;
    route: string;
};

export type ActivityItem = {
    kind: string;
    actor: string;
    occurredAt: string;
};

export type GovernanceSummary = {
    needsAttention: number;
    openDecisions: number;
    pendingSignatures: number;
    openActions: number;
};
