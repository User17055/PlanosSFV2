<?php

return [
    'demo' => (bool) env('APP_DEMO', false),
    'financial_max_age_minutes' => (int) env('FINANCIAL_MAX_AGE_MINUTES', 1440),
    'proposal_validity_hours' => (int) env('PROPOSAL_VALIDITY_HOURS', 48),
    'reservation_minutes' => (int) env('BENEFIT_RESERVATION_MINUTES', 30),
    'commercial_approved' => (bool) env('COMMERCIAL_RULES_APPROVED', false),
    'paper_enabled' => (bool) env('PAPER_CONTRACTS_ENABLED', false),
    // Monthly bills are generated on generation_day and due on due_day. Contracts closed up to cutoff_day are
    // billed again the next month; later ones skip a month. The entry paid at signing is the first installment.
    'billing' => [
        'generation_day' => (int) env('BILLING_GENERATION_DAY', 1),
        'due_day' => (int) env('BILLING_DUE_DAY', 10),
        'cutoff_day' => (int) env('BILLING_CUTOFF_DAY', 25),
    ],
];
