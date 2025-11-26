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

        // researcher upgrade
        'researcher-pro' => [
            'amount' => 14900,           // ₹149.00
            'label'  => 'Researcher Pro',
            'duration_days' => 30,
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
            'max_papers'      => 30,  // count relevant to their reviewer uploads if any
            'max_reports'     => 2,
            'max_collections' => 1,
            'managed_researchers' => 1, // free supervisor can be attached to 1 researcher
            'unlimited' => false,
        ],

        // supervisor upgrade
        'supervisor-pro' => [
            'amount' => 24900,           // ₹249.00
            'label'  => 'Supervisor Pro',
            'duration_days' => 30,
            'max_papers'      => 1000, // effectively larger; or set null for unlimited
            'max_reports'     => 9999,
            'max_collections' => 9999,
            'managed_researchers' => 6,
            'unlimited' => false,
        ],

        // admin university
        'admin-university' => [
            'amount' => 199900,          // ₹1,999.00
            'label'  => 'Admin (University)',
            'duration_days' => 30,
            'max_papers'      => null,   // null => unlimited
            'max_reports'     => null,
            'max_collections' => null,
            'managed_researchers' => null,
            'unlimited' => true,
        ],
    ],
];
