export type AccountBusiness = {
    businessId: string;
    name: string;
    stage: string;
    setupPhase: string | null;
    workspaceStatus: string;
    baseCurrency: string;
};

export type AccountWorkItem = {
    businessId: string;
    businessName: string;
    kind: string;
    title: string;
    description: string | null;
    status: string;
    dueAt: string | null;
    route: string;
};

export type AccountApproval = {
    businessId: string;
    businessName: string;
    decisionLabel: string;
    openedAt: string | null;
    route: string;
};

export type AccountSignature = {
    businessId: string;
    businessName: string;
    decisionLabel: string;
    status: string;
    requestedAt: string | null;
    route: string;
};

export type AccountNotification = {
    businessId: string;
    businessName: string;
    kind: string;
    status: string;
    createdAt: string | null;
    route: string;
};

export type AccountAttentionItem = {
    businessId: string;
    businessName: string;
    kind: string;
    title: string;
    route: string;
    dueAt: string | null;
    description?: string | null;
    status?: string;
};
