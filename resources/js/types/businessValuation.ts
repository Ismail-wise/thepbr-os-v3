export type ValuationMethod = {
    label: string;
    value: string;
    formula: string;
};

export type BusinessValuationReadModel = {
    id: string;
    businessId: string;
    baselineValuationId: string;
    asOfDate: string | null;
    formulaVersion: string;
    reviewState: string;
    historical: Record<string, string | null>;
    assumptions: Record<string, string | null>;
    provenance: {
        financialSnapshot?: null | {
            asOfDate: string;
            values: Record<string, string>;
        };
        assets?: Array<{ name: string; value: string }>;
        liabilities?: Array<{ name: string; value: string }>;
        explicitHistoricalFields?: string[];
    };
    methods: Record<string, ValuationMethod>;
    range: {
        low: string;
        base: string;
        high: string;
        basis: string;
    };
    confidence: {
        level: string;
        usableMethodCount: number;
    };
    evidenceQuality: {
        level: string;
        linkedEvidenceCount: number;
        verifiedEvidenceCount: number;
        evidenceIds: string[];
    };
    warnings: string[];
    semantics: Record<string, boolean>;
    createdAt: string | null;
};
