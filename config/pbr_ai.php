<?php

declare(strict_types=1);

return [
    'enabled' => (bool) env('PBR_AI_ENABLED', false),

    'provider' => env('PBR_AI_PROVIDER', 'disabled'),

    'max_prompt_length' => 4000,

    'retrieval_limit' => 8,

    /*
     * Restricted domains are disabled for AI context by default even when the
     * current User is otherwise allowed to view them. Enabling a domain does
     * not grant access; normal live authorization still runs first.
     */
    'restricted_domains' => [
        'conflict' => (bool) env(
            'PBR_AI_ALLOW_CONFLICT_CONTEXT',
            false,
        ),
        'finance' => (bool) env(
            'PBR_AI_ALLOW_FINANCE_CONTEXT',
            false,
        ),
        'risk' => (bool) env(
            'PBR_AI_ALLOW_RISK_CONTEXT',
            false,
        ),
        'continuity' => (bool) env(
            'PBR_AI_ALLOW_CONTINUITY_CONTEXT',
            false,
        ),
    ],
];
