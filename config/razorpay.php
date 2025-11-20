<?php

return [
    'key_id'     => env('RAZORPAY_KEY_ID'),
    'key_secret' => env('RAZORPAY_KEY_SECRET'),
    'currency'   => env('RAZORPAY_PLAN_DEFAULT_CURRENCY', 'INR'),

    // Whitelist of allowed plans and their amounts (in paise)
    'plans' => [
        // researcher upgrade
        'researcher-upgrade' => [
            'amount' => 14900,           // ₹149.00 -> 149 * 100
            'label'  => 'Researcher Pro',
            'duration_days' => 30,
        ],

        // supervisor upgrade
        'supervisor-upgrade' => [
            'amount' => 24900,           // ₹249.00
            'label'  => 'Supervisor Pro',
            'duration_days' => 30,
        ],

        // admin plan (if you ever sell via Razorpay)
        'admin-current' => [
            'amount' => 49900,           // ₹499.00
            'label'  => 'Admin',
            'duration_days' => 30,
        ],
    ],
];
