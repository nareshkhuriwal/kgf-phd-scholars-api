<?php

return [
    'key_id'     => env('RAZORPAY_KEY_ID'),
    'key_secret' => env('RAZORPAY_KEY_SECRET'),
    'currency'   => env('RAZORPAY_PLAN_DEFAULT_CURRENCY', 'INR'),

    // Whitelist of allowed plans and their amounts (in paise)
    'plans' => [
        // researcher (free)
        'researcher-free' => [
            'amount' => 0,
            'label'  => 'Researcher (Free)',
            'duration_days' => 30,
            // quotas
            'max_papers'      => 50,
            'max_reports'     => 5,
            'max_collections' => 2,
            'managed_researchers' => 0,
            'unlimited' => false,
        ],

        //
        // Researcher Pro - MONTHLY (minimum recommended: ₹249/month)
        //
        'researcher-pro' => [
            'amount' => 24900,           // ₹249.00 (in paise)
            'label'  => 'Researcher Pro (Monthly)',
            'duration_days' => 30,
            'max_papers'      => 200,
            'max_reports'     => 20,
            'max_collections' => 10,
            'managed_researchers' => 0,
            'unlimited' => false,
        ],

        //
        // Researcher Pro - YEARLY (minimum recommended: ₹2,899/year)
        //
        'researcher-pro-yearly' => [
            'amount' => 289900,         // ₹2,899.00 (in paise)
            'label'  => 'Researcher Pro (Yearly)',
            'duration_days' => 365,
            'max_papers'      => 200,
            'max_reports'     => 20,
            'max_collections' => 10,
            'managed_researchers' => 0,
            'unlimited' => false,
        ],

        // supervisor (free)
        'supervisor-free' => [
            'amount' => 0,
            'label'  => 'Supervisor (Free)',
            'duration_days' => 30,
            'max_papers'      => 30,
            'max_reports'     => 2,
            'max_collections' => 1,
            'managed_researchers' => 1,
            'unlimited' => false,
        ],

        //
        // Supervisor Pro - MONTHLY (minimum recommended: ₹499/month)
        //
        'supervisor-pro' => [
            'amount' => 49900,           // ₹499.00 (in paise)
            'label'  => 'Supervisor Pro (Monthly)',
            'duration_days' => 30,
            // Supervisor is intended to manage multiple researchers:
            'max_papers'      => 1000,   // effectively large; treated as generous quota
            'max_reports'     => 9999,
            'max_collections' => 9999,
            'managed_researchers' => 20, // updated to match "up to 20 researchers"
            'unlimited' => false,
        ],

        //
        // Supervisor Pro - YEARLY (minimum recommended: ₹4,999/year)
        //
        'supervisor-pro-yearly' => [
            'amount' => 499900,         // ₹4,999.00 (in paise)
            'label'  => 'Supervisor Pro (Yearly)',
            'duration_days' => 365,
            'max_papers'      => 1000,
            'max_reports'     => 9999,
            'max_collections' => 9999,
            'managed_researchers' => 20,
            'unlimited' => false,
        ],

        // admin trial
        'admin-trial' => [
            'amount' => 0,
            'label'  => 'Admin (Trial)',
            'duration_days' => 30,
            'max_papers'      => 5,     // trial constraints
            'max_reports'     => 5,
            'max_collections' => 5,
            'managed_researchers' => 5,
            'unlimited' => false,
        ],

        //
        // Admin Pro (Institutional) - ANNUAL (minimum recommended: ₹99,000/year per dept)
        //
        'admin-pro' => [
            'amount' => 1499900,        // ₹14,999.00 (in paise)
            'label'  => 'Admin Pro (Yearly)',
            'duration_days' => 365,
            'max_papers'      => null,   // null => unlimited
            'max_reports'     => null,
            'max_collections' => null,
            'managed_researchers' => null,
            'unlimited' => true,
        ],
    ],
];
