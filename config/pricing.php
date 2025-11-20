<?php

return [

    'roles' => [

        'researcher' => [
            'current' => [
                'key'     => 'researcher-current',
                'title'   => 'Researcher',
                'price'   => 'Free',
                'subtitle'=> 'Start free with limited usage.',
                'bullets' => [
                    'Up to 50 papers',
                    '5 reports',
                    '2 collections',
                    'Basic search and filtering',
                    'Email support',
                ],
                'chip'    => 'Current plan',
            ],
            'upgrade' => [
                'key'     => 'researcher-upgrade',
                'title'   => 'Researcher Pro',
                'price'   => '₹149',
                'subtitle'=> 'Unlock higher limits for serious work.',
                'bullets' => [
                    'Up to 200 papers',
                    '20 reports',
                    '10 collections',
                    'Advanced search and filtering',
                    'Priority email support',
                    'Export to multiple formats',
                ],
                'chip'    => 'Upgrade plan',
            ],
        ],

        'supervisor' => [
            'current' => [
                'key'     => 'supervisor-current',
                'title'   => 'Supervisor',
                'price'   => 'Free',
                'subtitle'=> 'Perfect when you are starting with a small team.',
                'bullets' => [
                    'Up to 1 researcher for review',
                    'Includes 30 papers',
                    '2 reports',
                    '1 collection',
                    'Basic review and approval workflow',
                    'Email notifications',
                ],
                'chip'    => 'Current plan',
            ],
            'upgrade' => [
                'key'     => 'supervisor-upgrade',
                'title'   => 'Supervisor Pro',
                'price'   => '₹249',
                'subtitle'=> 'Scale supervision across more researchers.',
                'bullets' => [
                    'Up to 6 researchers for review',
                    'Includes everything in upgraded Researcher plan:',
                    '• 200 papers',
                    '• 20 reports',
                    '• 10 collections',
                    'Advanced review and approval workflow',
                    'Team collaboration tools',
                    'Bulk actions and management',
                    'Priority support',
                ],
                'chip'    => 'Upgrade plan',
            ],
        ],

        'admin' => [
            'current' => [
                'key'     => 'admin-current',
                'title'   => 'Admin',
                'price'   => '₹499',
                'subtitle'=> 'Central admin access for your university.',
                'bullets' => [
                    'Paid plan — no free tier for Admin',
                    'Central admin access for your university',
                    'Manage researchers, supervisors & reports in one place',
                    'Unlimited users and researchers',
                    'Advanced analytics and reporting',
                    'Custom branding options',
                    'Dedicated account manager',
                    'Priority support with SLA',
                ],
                'chip'    => 'Current plan',
            ],
            'upgrade' => null, // no higher plan
        ],

    ],

];
